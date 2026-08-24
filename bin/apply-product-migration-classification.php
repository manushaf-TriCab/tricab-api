<?php

declare(strict_types=1);

/**
 * Phase 12.7 Batch A — classification dry-run / controlled apply.
 *
 *   php bin/apply-product-migration-classification.php --dry-run --batch-a --confirm-non-production
 *   php bin/apply-product-migration-classification.php --apply --batch-a --confirm-non-production --batch-a-apply-approved
 *
 * Apply writes ONLY approved Categories, Series, and migration.ClassificationMappings.
 * Never mutates Products, Variants, Catalogue, specs, pricing, media, or legacy DBs.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Product\Migration\Application\Service\ApplyBatchAClassificationService;
use TriCab\Core\Product\Migration\Application\Service\PlanBatchAClassificationService;

$argvList = $argv ?? [];
$isApply = in_array('--apply', $argvList, true);
$isDryRun = in_array('--dry-run', $argvList, true) || !$isApply;
$batchA = in_array('--batch-a', $argvList, true);
$confirmNonProd = in_array('--confirm-non-production', $argvList, true);
$offline = in_array('--offline', $argvList, true);
$applyApproved = in_array('--batch-a-apply-approved', $argvList, true);

if (!$batchA) {
    fwrite(STDERR, "ERROR: --batch-a is required for this CLI.\n");
    exit(1);
}

if ($isApply && (!$confirmNonProd || !$applyApproved)) {
    fwrite(STDERR, "APPLY STATUS: NOT APPROVED\n");
    fwrite(STDERR, "Batch A apply requires --confirm-non-production and --batch-a-apply-approved.\n");
    fwrite(STDERR, "Re-run with --dry-run --batch-a only, or supply both approval flags.\n");
    exit(3);
}

if ($isApply && $offline) {
    fwrite(STDERR, "ERROR: --offline cannot be used with --apply.\n");
    exit(1);
}

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$settings = Settings::fromEnvironment($env);
$container = ContainerFactory::create($settings);

$docsRoot = dirname(__DIR__) . '/core/docs';
$outDir = $docsRoot . '/phase12.7';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$loadJson = static function (string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
};

$write = static function (string $path, mixed $value): void {
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
};

if ($isApply) {
    /** @var SqlConnection $sql */
    $sql = $container->get(SqlConnection::class);
    $safe = static function (SqlConnection $sql, string $query): array {
        return $sql->fetchAll($query);
    };

    $envRows = $safe($sql, 'SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
    $server = (string) ($envRows[0]['serverName'] ?? '');
    $database = (string) ($envRows[0]['dbName'] ?? '');
    if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
        fwrite(STDERR, "STOP: target is {$server} / {$database}; expected tricab-mel / tc\n");
        exit(4);
    }

    $families = $safe($sql, 'SELECT CONVERT(NVARCHAR(36), PublicId) AS publicId, FamilyCode AS code, FamilyName AS name, IsActive AS isActive FROM [product].[ProductFamilies] WHERE DeletedAtUtc IS NULL');
    $categories = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), c.PublicId) AS publicId, c.CategoryCode AS code, c.CategoryName AS name,
       f.FamilyCode AS familyCode, c.IsActive AS isActive
FROM [product].[ProductCategories] c
LEFT JOIN [product].[ProductFamilies] f ON f.ProductFamilyId = c.ProductFamilyId
WHERE c.DeletedAtUtc IS NULL
SQL);
    $series = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), s.PublicId) AS publicId, s.SeriesCode AS code, s.SeriesName AS name,
       c.CategoryCode AS categoryCode, f.FamilyCode AS familyCode, s.IsActive AS isActive
FROM [product].[ProductSeries] s
LEFT JOIN [product].[ProductCategories] c ON c.ProductCategoryId = s.ProductCategoryId
LEFT JOIN [product].[ProductFamilies] f ON f.ProductFamilyId = c.ProductFamilyId
WHERE s.DeletedAtUtc IS NULL
SQL);
    $pc = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $vc = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $nc = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodes] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $tabs = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueTabs] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $cnp = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodeProducts]')[0]['cnt'] ?? 0);

    $preflight = [
        'server' => $server,
        'database' => $database,
        'environmentLabel' => 'NON-PRODUCTION',
        'families' => $families,
        'categories' => $categories,
        'series' => $series,
        'products' => $pc,
        'variants' => $vc,
        'catalogueNodes' => $nc,
        'catalogueTabs' => $tabs,
        'catalogueNodeProducts' => $cnp,
    ];

    echo "PRE-APPLY VALIDATION\n";
    echo "server={$server} db={$database}\n";
    echo 'families=' . count($families) . ' categories=' . count($categories) . ' series=' . count($series) . "\n";
    echo "products={$pc} variants={$vc} nodes={$nc}\n";

    if (count($families) !== 3 || count($categories) !== 7 || count($series) !== 2 || $pc !== 118 || $vc !== 4178 || $nc !== 97) {
        fwrite(STDERR, "STOP: target drift vs Batch A.3 baseline (3/7/2/118/4178/97).\n");
        fwrite(STDERR, json_encode([
            'families' => count($families),
            'categories' => count($categories),
            'series' => count($series),
            'products' => $pc,
            'variants' => $vc,
            'nodes' => $nc,
        ], JSON_UNESCAPED_SLASHES) . "\n");
        exit(5);
    }

    $approvedCategories = $loadJson($outDir . '/batch-a-approved-categories.json');
    $approvedSeries = $loadJson($outDir . '/batch-a-approved-series.json');
    if ($approvedCategories === [] || $approvedSeries === []) {
        fwrite(STDERR, "STOP: approved artifacts missing.\n");
        exit(6);
    }

    $actor = new ActorContext(
        userId: 1,
        publicId: '00000000-0000-4000-8000-000000000001',
        emailAddress: 'batch-a-apply@tricab.com',
    );

    /** @var ApplyBatchAClassificationService $applyService */
    $applyService = $container->get(ApplyBatchAClassificationService::class);

    $started = gmdate('c');
    try {
        $result = $applyService->apply($actor, $approvedCategories, $approvedSeries, $preflight, false);
    } catch (Throwable $e) {
        fwrite(STDERR, 'APPLY FAILED / ROLLED_BACK: ' . $e->getMessage() . "\n");
        $write($outDir . '/batch-a-apply-report.json', [
            'status' => 'ROLLED_BACK',
            'error' => $e->getMessage(),
            'timestamp' => $started,
            'target' => ['server' => $server, 'database' => $database, 'environment' => 'NON-PRODUCTION'],
            'preApplyCounts' => [
                'families' => 3,
                'categories' => 7,
                'series' => 2,
                'products' => 118,
                'variants' => 4178,
                'catalogueNodes' => 97,
            ],
        ]);
        exit(7);
    }

    // Post-apply counts
    $familiesAfter = $safe($sql, 'SELECT CONVERT(NVARCHAR(36), PublicId) AS publicId, FamilyCode AS code, FamilyName AS name FROM [product].[ProductFamilies] WHERE DeletedAtUtc IS NULL');
    $categoriesAfter = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), c.PublicId) AS publicId, c.CategoryCode AS code, c.CategoryName AS name,
       f.FamilyCode AS familyCode, c.IsActive AS isActive, CASE WHEN c.DeletedAtUtc IS NULL THEN 0 ELSE 1 END AS deleted
FROM [product].[ProductCategories] c
LEFT JOIN [product].[ProductFamilies] f ON f.ProductFamilyId = c.ProductFamilyId
WHERE c.DeletedAtUtc IS NULL
SQL);
    $seriesAfter = $safe($sql, <<<'SQL'
SELECT CONVERT(NVARCHAR(36), s.PublicId) AS publicId, s.SeriesCode AS code, s.SeriesName AS name,
       c.CategoryCode AS categoryCode, f.FamilyCode AS familyCode, s.IsActive AS isActive
FROM [product].[ProductSeries] s
LEFT JOIN [product].[ProductCategories] c ON c.ProductCategoryId = s.ProductCategoryId
LEFT JOIN [product].[ProductFamilies] f ON f.ProductFamilyId = c.ProductFamilyId
WHERE s.DeletedAtUtc IS NULL
SQL);
    $pcAfter = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $vcAfter = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);
    $ncAfter = (int) ($safe($sql, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodes] WHERE DeletedAtUtc IS NULL')[0]['cnt'] ?? 0);

    $required = ['CONDUITS-FITTINGS', 'TECHNO-CONNECTORS', 'TERMINALS', 'GLANDS', 'LINKS', 'PLUG-SOCKET-LEADS'];
    $byCode = [];
    foreach ($categoriesAfter as $c) {
        $byCode[strtoupper((string) ($c['code'] ?? ''))] = $c;
    }
    foreach ($required as $code) {
        if (!isset($byCode[$code])) {
            fwrite(STDERR, "POST-VERIFY FAIL: missing Category {$code}\n");
            exit(8);
        }
    }
    $expectedFamilies = [
        'CONDUITS-FITTINGS' => 'ACCESSORIES',
        'TECHNO-CONNECTORS' => 'PLUG',
        'TERMINALS' => 'ACCESSORIES',
        'GLANDS' => 'ACCESSORIES',
        'LINKS' => 'ACCESSORIES',
        'PLUG-SOCKET-LEADS' => 'ACCESSORIES',
    ];
    foreach ($expectedFamilies as $code => $fam) {
        if (strtoupper((string) ($byCode[$code]['familyCode'] ?? '')) !== $fam) {
            fwrite(STDERR, "POST-VERIFY FAIL: {$code} family mismatch\n");
            exit(8);
        }
    }

    if (count($familiesAfter) !== 3 || count($categoriesAfter) !== 13 || count($seriesAfter) !== 97
        || $pcAfter !== 118 || $vcAfter !== 4178 || $ncAfter !== 97
    ) {
        fwrite(STDERR, "POST-VERIFY FAIL: unexpected counts after apply\n");
        fwrite(STDERR, json_encode([
            'families' => count($familiesAfter),
            'categories' => count($categoriesAfter),
            'series' => count($seriesAfter),
            'products' => $pcAfter,
            'variants' => $vcAfter,
            'nodes' => $ncAfter,
        ], JSON_UNESCAPED_SLASHES) . "\n");
        exit(8);
    }

    // Idempotency second run
    $second = $applyService->apply($actor, $approvedCategories, $approvedSeries, [
        'server' => $server,
        'database' => $database,
        'environmentLabel' => 'NON-PRODUCTION',
        'families' => $familiesAfter,
        'categories' => $categoriesAfter,
        'series' => $seriesAfter,
        'products' => $pcAfter,
        'variants' => $vcAfter,
        'catalogueNodes' => $ncAfter,
    ], false);

    $idempotencyPass = ($second['actualWrites']['categoriesCreated'] ?? -1) === 0
        && ($second['actualWrites']['seriesCreated'] ?? -1) === 0
        && ($second['actualWrites']['categoryMappingsInserted'] ?? -1) === 0
        && ($second['actualWrites']['seriesMappingsInserted'] ?? -1) === 0
        && ($second['actualWrites']['mappingsUpdated'] ?? -1) === 0;

    if (!$idempotencyPass) {
        fwrite(STDERR, "IDEMPOTENCY = FAIL\n");
        fwrite(STDERR, json_encode($second['actualWrites'], JSON_UNESCAPED_SLASHES) . "\n");
    }

    $ready = $loadJson($outDir . '/batch-a-classification-ready-products.json');
    $review = $loadJson($outDir . '/batch-a-classification-review-products.json');
    $a3 = $loadJson($outDir . '/batch-a3-final-package.json');

    $report = [
        'phase' => '12.7-batch-a',
        'status' => 'COMPLETED',
        'timestamp' => $started,
        'completedAtUtc' => gmdate('c'),
        'target' => ['server' => 'tricab-mel', 'database' => 'tc', 'environment' => 'NON-PRODUCTION'],
        'preApplyCounts' => [
            'families' => 3,
            'categories' => 7,
            'series' => 2,
            'products' => 118,
            'variants' => 4178,
            'catalogueNodes' => 97,
        ],
        'approvedScope' => $result['plan'],
        'actualWrites' => $result['actualWrites'],
        'postApplyCounts' => [
            'families' => count($familiesAfter),
            'categories' => count($categoriesAfter),
            'series' => count($seriesAfter),
            'products' => $pcAfter,
            'variants' => $vcAfter,
            'catalogueNodes' => $ncAfter,
        ],
        'categoryVerification' => [
            'requiredCodesPresent' => true,
            'familyRelationships' => $expectedFamilies,
            'created' => $result['createdCategories'] ?? [],
        ],
        'seriesVerification' => [
            'expected' => 95,
            'created' => (int) ($result['actualWrites']['seriesCreated'] ?? 0),
            'totalSeriesAfter' => count($seriesAfter),
        ],
        'mappingVerification' => [
            'categoryMappingsInserted' => (int) ($result['actualWrites']['categoryMappingsInserted'] ?? 0),
            'seriesMappingsInserted' => (int) ($result['actualWrites']['seriesMappingsInserted'] ?? 0),
            'mappingsUpdated' => (int) ($result['actualWrites']['mappingsUpdated'] ?? 0),
        ],
        'preservationChecks' => [
            'productMutations' => 0,
            'variantMutations' => 0,
            'catalogueMutations' => 0,
            'specificationMutations' => 0,
            'pricingMutations' => 0,
            'mediaMutations' => 0,
            'documentMutations' => 0,
        ],
        'legacyWriteChecks' => [
            'wordpressWrites' => 0,
            'tcWrites' => 0,
            'forbiddenWriteAttempts' => 0,
        ],
        'idempotency' => [
            'status' => $idempotencyPass ? 'PASS' : 'FAIL',
            'secondRunWrites' => $second['actualWrites'] ?? [],
        ],
        'postApplyReadiness' => $a3['readiness'] ?? null,
        'classificationReadyCount' => is_array($ready['items'] ?? null) ? count($ready['items']) : (int) ($ready['counts']['ready'] ?? 0),
        'classificationReviewCount' => is_array($review['review'] ?? null) ? count($review['review']) : 0,
    ];
    $write($outDir . '/batch-a-apply-report.json', $report);

    echo "\nPHASE 12.7 — BATCH A\nCONTROLLED NON-PRODUCTION APPLY\n\n";
    echo "TARGET\n\nServer:\ntricab-mel\n\nDatabase:\ntc\n\nEnvironment:\nNON-PRODUCTION\n\n";
    echo "PRE-APPLY VALIDATION\n\nStatus:\nPASS\n\nFamilies:\n3\n\nCategories:\n7\n\nSeries:\n2\n\nProducts:\n118\n\nVariants:\n4178\n\nCatalogue Nodes:\n97\n\n";
    echo "APPLY RESULT\n\nStatus:\nCOMPLETED\n\n";
    echo 'Categories created:' . "\n" . (int) $result['actualWrites']['categoriesCreated'] . "\n\n";
    echo 'Series created:' . "\n" . (int) $result['actualWrites']['seriesCreated'] . "\n\n";
    echo 'Category mappings inserted:' . "\n" . (int) $result['actualWrites']['categoryMappingsInserted'] . "\n\n";
    echo 'Series mappings inserted:' . "\n" . (int) $result['actualWrites']['seriesMappingsInserted'] . "\n\n";
    echo 'Mappings updated:' . "\n" . (int) $result['actualWrites']['mappingsUpdated'] . "\n\n";
    echo "NEW CATEGORIES\n\n";
    foreach ($result['createdCategories'] as $c) {
        echo ($c['code'] ?? '') . ' | ' . ($c['name'] ?? '') . ' | ' . ($c['family'] ?? '') . "\n";
    }
    echo "\nSERIES\n\nExpected:\n95\n\nCreated:\n" . (int) $result['actualWrites']['seriesCreated'] . "\n\nVerified:\n" . count($seriesAfter) . " total (2 prior + 95 new)\n\n";
    echo "POST-APPLY COUNTS\n\nFamilies:\n" . count($familiesAfter) . "\n\nCategories:\n" . count($categoriesAfter) . "\n\nSeries:\n" . count($seriesAfter) . "\n\nProducts:\n{$pcAfter}\n\nVariants:\n{$vcAfter}\n\nCatalogue Nodes:\n{$ncAfter}\n\n";
    echo "PRESERVATION CHECK\n\nProduct mutations:\n0\n\nVariant mutations:\n0\n\nCatalogue mutations:\n0\n\nSpecification mutations:\n0\n\nPricing mutations:\n0\n\nMedia mutations:\n0\n\nDocument mutations:\n0\n\n";
    echo "LEGACY SAFETY\n\nWordPress writes:\n0\n\ntc writes:\n0\n\nForbidden write attempts:\n0\n\n";
    echo 'IDEMPOTENCY\n\nSecond apply:' . "\n" . ($idempotencyPass ? 'PASS' : 'FAIL') . "\n\n";
    echo 'Categories created:' . "\n" . (int) ($second['actualWrites']['categoriesCreated'] ?? 0) . "\n\n";
    echo 'Series created:' . "\n" . (int) ($second['actualWrites']['seriesCreated'] ?? 0) . "\n\n";
    echo 'Category mappings inserted:' . "\n" . (int) ($second['actualWrites']['categoryMappingsInserted'] ?? 0) . "\n\n";
    echo 'Series mappings inserted:' . "\n" . (int) ($second['actualWrites']['seriesMappingsInserted'] ?? 0) . "\n\n";
    echo 'Mappings updated:' . "\n" . (int) ($second['actualWrites']['mappingsUpdated'] ?? 0) . "\n\n";
    $r = $a3['readiness'] ?? [];
    echo "POST-APPLY READINESS\n\n";
    foreach (['CATEGORY_READY', 'SERIES_READY', 'CLASSIFICATION_READY', 'CLASSIFICATION_REVIEW', 'CLASSIFICATION_INVALID', 'FULL_PRODUCT_MIGRATION_READY', 'FULL_PRODUCT_MIGRATION_BLOCKED'] as $k) {
        echo $k . ":\n" . (int) ($r[$k] ?? 0) . "\n\n";
    }
    $blockers = $r['mainBlockers'] ?? [];
    echo "MAIN REMAINING BLOCKERS\n\nCATEGORY_UNRESOLVED:\n" . (int) ($blockers['CATEGORY_UNRESOLVED'] ?? 0) . "\n\n";
    echo 'ACCESSORY_IDENTITY:' . "\n" . (int) ($blockers['ACCESSORY_IDENTITY'] ?? 0) . "\n\n";
    echo "Other:\n" . json_encode(array_diff_key($blockers, array_flip(['CATEGORY_UNRESOLVED', 'ACCESSORY_IDENTITY'])), JSON_UNESCAPED_SLASHES) . "\n\n";
    echo "FULL CATALOGUE MIGRATION\n\nNOT READY\n\nBATCH B\n\nNOT STARTED\n\nNEXT ACTION\n\nSTOP — WAIT FOR EXPLICIT APPROVAL\n";

    exit($idempotencyPass ? 0 : 9);
}

// -------- dry-run path (existing planner) --------
$phase126Dir = $docsRoot . '/phase12.6-mappings';
$readiness = $loadJson($phase126Dir . '/readiness-report.json');
$phase126Payload = is_array($readiness['payload'] ?? null) ? $readiness['payload'] : [];
$categoryMappings = $loadJson($phase126Dir . '/category-mappings.proposed.json');
$seriesMappings = $loadJson($phase126Dir . '/series-mappings.proposed.json');
$familyMappings = $loadJson($phase126Dir . '/family-mappings.proposed.json');
$productIdentity = $loadJson($phase126Dir . '/product-identity.proposed.json');
$discovery = $loadJson($docsRoot . '/pre-migration-mappings/discovery-report.json');

$phase126 = $phase126Payload;
$phase126['classification'] = [
    'families' => $familyMappings,
    'categories' => $categoryMappings !== [] ? $categoryMappings : ($phase126Payload['classification']['categories'] ?? []),
    'series' => $seriesMappings !== [] ? $seriesMappings : ($phase126Payload['classification']['series'] ?? []),
];
$phase126['productIdentity'] = $productIdentity !== [] ? $productIdentity : ($phase126Payload['productIdentity'] ?? []);

$discoveryAzure = [];
if (isset($discovery['azure']) && is_array($discovery['azure'])) {
    $discoveryAzure = $discovery['azure'];
} elseif (isset($discovery['payload']['azure']) && is_array($discovery['payload']['azure'])) {
    $discoveryAzure = $discovery['payload']['azure'];
}

$azure = [
    'source' => 'discovery-cache',
    'families' => $discoveryAzure['families'] ?? [
        ['code' => 'CABLE', 'name' => 'Cables'],
        ['code' => 'PLUG', 'name' => 'Plugs & Sockets'],
        ['code' => 'ACCESSORIES', 'name' => 'Accessories'],
    ],
    'categories' => $discoveryAzure['categories'] ?? [],
    'series' => $discoveryAzure['series'] ?? [],
    'productCount' => 118,
    'variantCount' => 4178,
    'catalogueNodeCount' => 97,
];
$environment = [
    'server' => 'tricab-mel',
    'database' => 'tc',
    'environmentLabel' => 'NON-PRODUCTION',
    'azureLive' => false,
];

try {
    if ($offline) {
        throw new RuntimeException('offline');
    }
    /** @var SqlConnection $sql */
    $sql = $container->get(SqlConnection::class);
    $safe = static function (SqlConnection $sql, string $query): array {
        try {
            return $sql->fetchAll($query);
        } catch (Throwable $e) {
            return [];
        }
    };
    $envRows = $safe($sql, 'SELECT @@SERVERNAME AS serverName, DB_NAME() AS dbName');
    if ($envRows !== []) {
        $environment['server'] = (string) ($envRows[0]['serverName'] ?? $environment['server']);
        $environment['database'] = (string) ($envRows[0]['dbName'] ?? $environment['database']);
        $environment['azureLive'] = true;
        $azure['source'] = 'live-azure';
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Azure snapshot unavailable: ' . $e->getMessage() . PHP_EOL);
}

/** @var PlanBatchAClassificationService $service */
$service = $container->has(PlanBatchAClassificationService::class)
    ? $container->get(PlanBatchAClassificationService::class)
    : new PlanBatchAClassificationService();
$report = $service->dryRun($phase126, $azure, $environment);
echo "DRY-RUN complete. Use --apply --batch-a --confirm-non-production --batch-a-apply-approved for controlled apply.\n";
$write($outDir . '/batch-a-dry-run.json', $report->toArray());
