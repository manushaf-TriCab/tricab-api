<?php

declare(strict_types=1);

/**
 * DEVELOPMENT ONLY: apply operator-approved Style Family Tree + Product.Code mapping.
 *
 * Creates missing ProductStyles (LOCAL), applies approved ParentStyleId links
 * (excluding PJ→PJT and BVA→BVA-X), assigns exact Product.Code styles, corrects RCA.
 *
 * Does not change inheritance, specifications, media, products, variants,
 * WordPress, tc MySQL, or filesystem.
 *
 * Usage (from tricab-api):
 *   php bin/style-family-product-mapping-apply-development.php
 *   php bin/style-family-product-mapping-apply-development.php --dry-revalidate
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Application\Service\ApplyApprovedStyleFamilyTreeAndMappingService;
use TriCab\Core\Product\Application\Service\DiscoverProductStyleFamilyTreeService;
use TriCab\Core\Product\Contract\ProductStyleRepository;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
CoreAutoload::register();

set_time_limit(0);

$dryRevalidate = in_array('--dry-revalidate', $argv ?? [], true);
$packagePath = $basePath . '/tmp/style-family-operator-review.json';
$expectedHost = 'tricab-mel.database.windows.net';
$expectedDatabase = 'tc';
$expectedServer = 'tricab-mel';

$settings = Environment::settings($basePath);
printf("APP_ENV=%s\n", $settings->appEnv);
printf("USER_REPOSITORY=%s\n", $settings->userRepository);
printf("DB_HOST=%s\n", $settings->dbHost);
printf("DB_DATABASE=%s\n", $settings->dbDatabase);
printf("isProduction=%s\n", $settings->isProduction() ? 'YES' : 'NO');

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION. Stopping. PRODUCTION WRITES = 0\n");
    exit(2);
}
if (!$settings->isDevelopment() || !$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "Development Azure SQL is required. Stopping.\n");
    exit(2);
}
if (strcasecmp($settings->dbHost, $expectedHost) !== 0 || strcasecmp($settings->dbDatabase, $expectedDatabase) !== 0) {
    fwrite(STDERR, "DB_HOST/DB_DATABASE are not the approved development target. Stopping.\n");
    exit(2);
}
if (!is_file($packagePath)) {
    fwrite(STDERR, "Approved package missing: {$packagePath}\n");
    exit(2);
}

/** @var array<string, mixed> $package */
$package = json_decode((string) file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
$identity = $db->fetchAll(
    'SELECT DB_NAME() AS [DatabaseName], @@SERVERNAME AS [ServerName], SUSER_SNAME() AS [LoginName]',
);
$databaseName = (string) ($identity[0]['DatabaseName'] ?? '');
$serverName = (string) ($identity[0]['ServerName'] ?? '');
$loginName = (string) ($identity[0]['LoginName'] ?? '');
printf("SQL @@SERVERNAME=%s\n", $serverName);
printf("SQL DB_NAME()=%s\n", $databaseName);
printf("SQL SUSER_SNAME()=%s\n", $loginName);

if (strcasecmp($databaseName, $expectedDatabase) !== 0 || strcasecmp($databaseName, $settings->dbDatabase) !== 0) {
    fwrite(STDERR, "Database identity mismatch. Stopping.\n");
    exit(2);
}
if (strcasecmp($serverName, $expectedServer) !== 0) {
    fwrite(STDERR, "SQL @@SERVERNAME is not tricab-mel. Stopping.\n");
    exit(2);
}
if (stripos($serverName, 'prod') !== false || stripos($databaseName, 'prod') !== false) {
    fwrite(STDERR, "Server/database looks like production. Stopping.\n");
    exit(2);
}

$count = static function (SqlConnection $db, string $sql): int {
    return (int) ($db->fetchAll($sql)[0]['cnt'] ?? 0);
};

$snapshot = static function (SqlConnection $db, callable $count): array {
    return [
        'styles' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL'),
        'parents' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NOT NULL'),
        'assignments' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]'),
        'products' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL'),
        'variants' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE [DeletedAtUtc] IS NULL'),
        'specs' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]'),
        'media' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]'),
        'auditEvents' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductAuditEvents]'),
    ];
};

$before = $snapshot($db, $count);
printf(
    "\nPre-apply styles=%d parents=%d assignments=%d products=%d variants=%d specs=%d media=%d audit=%d\n",
    $before['styles'],
    $before['parents'],
    $before['assignments'],
    $before['products'],
    $before['variants'],
    $before['specs'],
    $before['media'],
    $before['auditEvents'],
);

// Build approved package from operator review JSON with PJ→PJT excluded.
$createCodes = [];
foreach ($package['missingChildStyles'] ?? [] as $row) {
    $code = strtoupper(trim((string) ($row['proposedProductStyle'] ?? $row['productCode'] ?? '')));
    if ($code !== '') {
        $createCodes[] = $code;
    }
}
$createCodes = array_values(array_unique($createCodes));

$parentLinks = [];
foreach (array_merge(
    $package['packages']['B_OPERATOR_CONFIRMED_REQUIRING_APPLY'] ?? [],
    $package['packages']['C_ADDITIONAL_APPROVE_CANDIDATES'] ?? [],
) as $edge) {
    $parent = strtoupper(trim((string) ($edge['parentStyleCode'] ?? '')));
    $child = strtoupper(trim((string) ($edge['childStyleCode'] ?? '')));
    if ($parent === '' || $child === '') {
        continue;
    }
    if ($parent === 'PJ' && $child === 'PJT') {
        continue;
    }
    if ($parent === 'BVA' && $child === 'BVA-X') {
        continue;
    }
    $parentLinks[] = ['parentStyleCode' => $parent, 'childStyleCode' => $child];
}

$assignments = [];
$replacements = [];
foreach ($package['productAssignmentPackage'] ?? [] as $row) {
    $action = (string) ($row['futureRequiredAction'] ?? '');
    $productPublicId = trim((string) ($row['productPublicId'] ?? ''));
    $styleCode = strtoupper(trim((string) ($row['exactProductStyle'] ?? $row['productCode'] ?? '')));
    if ($productPublicId === '' || $styleCode === '') {
        continue;
    }
    if ($action === 'REPLACE_ASSIGNMENT') {
        $replacements[] = [
            'productPublicId' => $productPublicId,
            'fromStyleCode' => strtoupper(trim((string) ($row['currentAssignment'] ?? 'BVA'))),
            'toStyleCode' => $styleCode,
        ];
        continue;
    }
    $assignments[] = [
        'productPublicId' => $productPublicId,
        'styleCode' => $styleCode,
    ];
}
foreach ($package['assignmentReplacements'] ?? [] as $row) {
    $productPublicId = trim((string) ($row['productPublicId'] ?? ''));
    $from = strtoupper(trim((string) ($row['currentAssignment'] ?? '')));
    $to = strtoupper(trim((string) ($row['expectedAssignment'] ?? $row['exactProductStyle'] ?? '')));
    if ($productPublicId === '' || $from === '' || $to === '') {
        continue;
    }
    $exists = false;
    foreach ($replacements as $existing) {
        if (strcasecmp($existing['productPublicId'], $productPublicId) === 0) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $replacements[] = [
            'productPublicId' => $productPublicId,
            'fromStyleCode' => $from,
            'toStyleCode' => $to,
        ];
    }
}

printf("Approved create styles=%d\n", count($createCodes));
printf("Approved parent links (PJ→PJT excluded)=%d\n", count($parentLinks));
printf("Exact assignments to insert=%d\n", count($assignments));
printf("Assignment replacements=%d\n", count($replacements));

if (count($createCodes) !== 74) {
    fwrite(STDERR, "STOP: expected 74 approved missing styles, got " . count($createCodes) . "\n");
    exit(2);
}
if (count($parentLinks) !== 67) {
    fwrite(STDERR, "STOP: expected 67 approved parent links, got " . count($parentLinks) . "\n");
    exit(2);
}
if (count($assignments) + count($replacements) !== 100) {
    fwrite(STDERR, "STOP: expected 100 product mappings, got " . (count($assignments) + count($replacements)) . "\n");
    exit(2);
}

// Live revalidation against discovery (material change stop).
/** @var DiscoverProductStyleFamilyTreeService $discover */
$discover = $container->get(DiscoverProductStyleFamilyTreeService::class);
$live = $discover->dryRun();
$liveEligible = (int) ($live['counters']['eligibleStyleShellProducts'] ?? 0);
$liveMissing = (int) ($live['counters']['missingChildProductStyles'] ?? count($live['missingChildStyleCandidates'] ?? []));
$liveApprove = (int) ($live['counters']['additionalApproveCandidateRelationships'] ?? 0);
$liveConfirmedApply = (int) ($live['counters']['confirmedRelationshipsRequiringApply'] ?? 0);
printf(
    "Live revalidation eligible=%d missingChildren=%d confirmedApply=%d approveCandidates=%d styles=%d parents=%d assignments=%d\n",
    $liveEligible,
    $liveMissing,
    $liveConfirmedApply,
    $liveApprove,
    $before['styles'],
    $before['parents'],
    $before['assignments'],
);

// First-time apply expects the approved dry-run baseline. Idempotent re-runs are allowed after that.
$isBaseline = $before['styles'] === 464 && $before['parents'] === 5 && $before['assignments'] === 1;
$isPostApply = $before['styles'] >= 538 && $before['assignments'] >= 100;
if (!$dryRevalidate && !$isBaseline && !$isPostApply) {
    fwrite(STDERR, "STOP: live target state has materially changed from the approved baseline or completed apply.\n");
    fwrite(STDERR, sprintf(
        "styles=%d parents=%d assignments=%d (baseline 464/5/1 or post-apply >=538/>=100)\n",
        $before['styles'],
        $before['parents'],
        $before['assignments'],
    ));
    exit(2);
}
if (!$dryRevalidate && $isBaseline) {
    if ($liveEligible !== 100 || $liveMissing !== 74 || $liveConfirmedApply !== 4 || $liveApprove !== 64) {
        fwrite(STDERR, "STOP: live discovery materially differs from approved package.\n");
        fwrite(STDERR, sprintf(
            "eligible=%d missing=%d confirmedApply=%d approve=%d\n",
            $liveEligible,
            $liveMissing,
            $liveConfirmedApply,
            $liveApprove,
        ));
        exit(2);
    }
}

$userRows = $db->fetchAll(
    'SELECT TOP (1) [UserId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [EmailAddress] AS [Email] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = :email',
    [':email' => 'thilanh@tricab.com'],
);
if ($userRows === []) {
    fwrite(STDERR, "Could not resolve thilanh@tricab.com.\n");
    exit(1);
}
$actor = new ActorContext(
    userId: (int) $userRows[0]['UserId'],
    publicId: (string) $userRows[0]['PublicId'],
    emailAddress: (string) $userRows[0]['Email'],
    correlationId: 'style-family-product-mapping-apply-development',
);

/** @var ApplyApprovedStyleFamilyTreeAndMappingService $apply */
$apply = $container->get(ApplyApprovedStyleFamilyTreeAndMappingService::class);

if ($dryRevalidate) {
    echo "\nDRY REVALIDATE MODE — invoking apply as idempotency check (writes only if gaps remain)\n";
}

echo "\n=== PHASE A–G APPLY ===\n";
$result = $apply->apply(
    actor: $actor,
    confirmed: true,
    developmentEnvironment: true,
    createStyleCodes: $createCodes,
    parentLinks: $parentLinks,
    assignments: $assignments,
    replacements: $replacements,
    excludedParentLinks: [
        ['parentStyleCode' => 'PJ', 'childStyleCode' => 'PJT'],
        ['parentStyleCode' => 'BVA', 'childStyleCode' => 'BVA-X'],
    ],
);

$after = $snapshot($db, $count);
$c = $result['counters'];

printf("\nProductStyles before=%d after=%d created=%d already=%d failed=%d conflicts=%d\n",
    $before['styles'], $after['styles'], $c['stylesCreated'], $c['stylesAlreadyExisted'], $c['stylesFailed'], $c['stylesConflicts']);
printf("Parent links before=%d after=%d created=%d already=%d conflicts=%d failed=%d\n",
    $before['parents'], $after['parents'], $c['parentLinksCreated'], $c['parentLinksAlreadyCorrect'], $c['parentConflicts'], $c['parentFailed']);
printf("Assignments before=%d after=%d created=%d replaced=%d already=%d conflicts=%d failed=%d\n",
    $before['assignments'], $after['assignments'], $c['assignmentsCreated'], $c['assignmentsReplaced'], $c['assignmentsAlreadyCorrect'], $c['assignmentConflicts'], $c['assignmentsFailed']);
printf("Specs delta=%d media delta=%d products delta=%d variants delta=%d\n",
    $after['specs'] - $before['specs'],
    $after['media'] - $before['media'],
    $after['products'] - $before['products'],
    $after['variants'] - $before['variants'],
);
printf("Cycles self=%d direct=%d deep=%d\n", $c['selfCycles'], $c['directCycles'], $c['deepCycles']);
printf("Inheritance unchanged=%s\n", !empty($result['inheritanceUnchanged']['ok']) ? 'YES' : 'NO');

/** @var ProductStyleRepository $styles */
$styles = $container->get(ProductStyleRepository::class);

$special = [
    'BV_PARENT_FAMILY_PRESERVED' => true,
    'RC_PARENT_FAMILY_APPLIED' => true,
    'PJT_STYLE_CREATED' => $styles->findByStyleCode('PJT', false) !== null,
    'PJT_PARENT' => $styles->findByStyleCode('PJT', false)?->parentStyleCode ?? 'NULL',
    'PJ_TO_PJT_APPLIED' => false,
    'BVA_X_CHANGED' => false,
    'RCA_STYLE_CREATED' => $styles->findByStyleCode('RCA', false) !== null,
    'RCA_PARENT' => $styles->findByStyleCode('RCA', false)?->parentStyleCode,
    'RCA_PRODUCT_ASSIGNED_STYLE' => null,
    'BVA_STYLE_RETAINED' => $styles->findByStyleCode('BVA', false) !== null,
    'UP_PRODUCT_ASSIGNED_STYLE' => null,
    'UP_PARENT_CHILDREN_APPLIED' => true,
];

foreach (['BVA', 'BVC', 'BVM', 'BVP'] as $child) {
    $s = $styles->findByStyleCode($child, false);
    if ($s === null || strtoupper((string) $s->parentStyleCode) !== 'BV') {
        $special['BV_PARENT_FAMILY_PRESERVED'] = false;
    }
}
foreach (['RCA', 'RCC', 'RCM', 'RCP'] as $child) {
    $s = $styles->findByStyleCode($child, false);
    if ($s === null || strtoupper((string) $s->parentStyleCode) !== 'RC') {
        $special['RC_PARENT_FAMILY_APPLIED'] = false;
    }
}
foreach (['UPA', 'UPC', 'UPM'] as $child) {
    $s = $styles->findByStyleCode($child, false);
    if ($s === null || strtoupper((string) $s->parentStyleCode) !== 'UP') {
        $special['UP_PARENT_CHILDREN_APPLIED'] = false;
    }
}
$pjt = $styles->findByStyleCode('PJT', false);
if ($pjt !== null && $pjt->parentStyleCode !== null) {
    $special['PJ_TO_PJT_APPLIED'] = true;
    $special['PJT_PARENT'] = $pjt->parentStyleCode;
} else {
    $special['PJT_PARENT'] = 'NULL';
}
$bvaX = $styles->findByStyleCode('BVA-X', false);
if ($bvaX !== null && strtoupper((string) $bvaX->parentStyleCode) !== 'BVA') {
    $special['BVA_X_CHANGED'] = true;
}

$assignRows = $db->fetchAll(
    'SELECT CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
            UPPER(LTRIM(RTRIM(p.[InternalCode]))) AS [ProductCode],
            s.[StyleCode] AS [AssignedStyle]
     FROM [product].[Products] p
     LEFT JOIN [product].[ProductStyleAssignments] a ON a.[ProductId] = p.[ProductId]
     LEFT JOIN [product].[ProductStyles] s ON s.[ProductStyleId] = a.[ProductStyleId] AND s.[DeletedAtUtc] IS NULL
     WHERE p.[DeletedAtUtc] IS NULL
       AND UPPER(LTRIM(RTRIM(ISNULL(p.[InternalCode], \'\')))) IN (' . implode(',', array_map(
           static fn (array $r): string => "'" . str_replace("'", "''", strtoupper((string) ($r['exactProductStyle'] ?? $r['productCode'] ?? ''))) . "'",
           $package['productAssignmentPackage'] ?? [],
       )) . ')',
);

$correct = 0;
$unassigned = 0;
$wrong = 0;
$mismatches = [];
$byProduct = [];
foreach ($assignRows as $row) {
    $code = strtoupper((string) $row['ProductCode']);
    $assigned = $row['AssignedStyle'] !== null ? strtoupper((string) $row['AssignedStyle']) : null;
    $byProduct[$code] = $assigned;
    if ($assigned === null) {
        ++$unassigned;
        $mismatches[] = $code . ' → UNASSIGNED';
    } elseif ($assigned !== $code) {
        ++$wrong;
        $mismatches[] = $code . ' → ' . $assigned;
    } else {
        ++$correct;
    }
}
$special['RCA_PRODUCT_ASSIGNED_STYLE'] = $byProduct['RCA'] ?? null;
$special['UP_PRODUCT_ASSIGNED_STYLE'] = $byProduct['UP'] ?? null;

// Print persisted families
$approvedParents = ['BV','RC','BH','DF','DV','EG','ES','EW','FC','FE','FJ','FK','FZ','HT','NJ','NT','NU','PJ','RB','RR','RS','UP'];
echo "\n=== PERSISTED STYLE TREE ===\n";
foreach ($approvedParents as $parentCode) {
    $parent = $styles->findByStyleCode($parentCode, false);
    if ($parent === null) {
        printf("%s [MISSING]\n", $parentCode);
        continue;
    }
    $children = $styles->listChildren($parent->publicId, false);
    usort($children, static fn ($a, $b) => strcmp($a->styleCode, $b->styleCode));
    printf("%s\n", $parentCode);
    $n = count($children);
    foreach ($children as $i => $child) {
        $branch = $i === $n - 1 ? '└── ' : '├── ';
        printf("%s%s\n", $branch, $child->styleCode);
    }
}
$pjtStyle = $styles->findByStyleCode('PJT', false);
printf("\nPJT [STANDALONE] parent=%s\n", $pjtStyle?->parentStyleCode ?? 'NULL');
$bvaXStyle = $styles->findByStyleCode('BVA-X', false);
printf("BVA-X [REVIEW/SMOKE] parent=%s\n", $bvaXStyle?->parentStyleCode ?? 'NULL');

$out = [
    'before' => $before,
    'after' => $after,
    'result' => $result,
    'special' => $special,
    'reconciliation' => [
        'eligible' => count($package['productAssignmentPackage'] ?? []),
        'correct' => $correct,
        'unassigned' => $unassigned,
        'wrong' => $wrong,
        'mismatches' => $mismatches,
    ],
];
@mkdir($basePath . '/tmp', 0777, true);
file_put_contents($basePath . '/tmp/style-family-product-mapping-apply-report.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n=== SPECIAL ASSERTIONS ===\n";
foreach ($special as $k => $v) {
    printf("%s: %s\n", $k, is_bool($v) ? ($v ? 'YES' : 'NO') : (string) ($v ?? 'NULL'));
}

echo "\n=== PRODUCT RECONCILIATION ===\n";
printf("Eligible=%d correct=%d unassigned=%d wrong=%d\n", count($package['productAssignmentPackage'] ?? []), $correct, $unassigned, $wrong);
if ($mismatches !== []) {
    echo "Mismatches:\n";
    foreach ($mismatches as $m) {
        echo "  {$m}\n";
    }
}

printf("\nReport written: tmp/style-family-product-mapping-apply-report.json\n");

$failed = $c['stylesFailed'] + $c['stylesConflicts'] + $c['parentFailed'] + $c['parentConflicts']
    + $c['assignmentsFailed'] + ($c['selfCycles'] + $c['directCycles'] + $c['deepCycles'])
    + ($unassigned + $wrong)
    + (empty($result['inheritanceUnchanged']['ok']) ? 1 : 0)
    + ($special['PJ_TO_PJT_APPLIED'] ? 1 : 0)
    + ($special['BVA_X_CHANGED'] ? 1 : 0)
    + (!$special['BV_PARENT_FAMILY_PRESERVED'] ? 1 : 0)
    + (!$special['RC_PARENT_FAMILY_APPLIED'] ? 1 : 0);

exit($failed > 0 ? 1 : 0);
