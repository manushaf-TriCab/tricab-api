<?php

declare(strict_types=1);

/**
 * DEVELOPMENT read-only Product.Code → ProductStyle mapping dry-run.
 * Does not create styles, assignments, products, parents, specs, media, WP, or tc.
 *
 * Usage (from tricab-api):
 *   php bin/style-product-code-mapping-dry-run.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\ProductCodeStyleMappingRow;
use TriCab\Core\Product\Application\Service\DiscoverProductCodeStyleMappingService;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
CoreAutoload::register();

$settings = Environment::settings($basePath);
printf("APP_ENV=%s\n", $settings->appEnv);
printf("USER_REPOSITORY=%s\n", $settings->userRepository);
printf("DB_HOST=%s\n", $settings->dbHost);
printf("DB_DATABASE=%s\n", $settings->dbDatabase);
printf("isProduction=%s\n", $settings->isProduction() ? 'YES' : 'NO');

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION. Stopping.\n");
    exit(2);
}
if (!$settings->isDevelopment() || !$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "Development Azure SQL is required. Stopping.\n");
    exit(2);
}
if (strcasecmp($settings->dbHost, 'tricab-mel.database.windows.net') !== 0 || strcasecmp($settings->dbDatabase, 'tc') !== 0) {
    fwrite(STDERR, "Not the approved development target. Stopping.\n");
    exit(2);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
$identity = $db->fetchAll('SELECT DB_NAME() AS [DatabaseName], @@SERVERNAME AS [ServerName], SUSER_SNAME() AS [LoginName]');
$serverName = (string) ($identity[0]['ServerName'] ?? '');
$databaseName = (string) ($identity[0]['DatabaseName'] ?? '');
printf("SQL @@SERVERNAME=%s\n", $serverName);
printf("SQL DB_NAME()=%s\n", $databaseName);
printf("SQL SUSER_SNAME()=%s\n", (string) ($identity[0]['LoginName'] ?? ''));
if (strcasecmp($serverName, 'tricab-mel') !== 0 || strcasecmp($databaseName, 'tc') !== 0) {
    fwrite(STDERR, "SQL identity mismatch. Stopping.\n");
    exit(2);
}

$count = static function (SqlConnection $db, string $sql): int {
    return (int) ($db->fetchAll($sql)[0]['cnt'] ?? 0);
};
$before = [
    'styles' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL'),
    'parents' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NOT NULL'),
    'assignments' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]'),
    'products' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL'),
    'variants' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE [DeletedAtUtc] IS NULL'),
    'specs' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]'),
    'media' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]'),
];

echo "\nDRY RUN — NO CHANGES APPLIED\n";
echo "SELECTED HIERARCHY RELATIONSHIPS = []\n";
echo "Product.Code is Style identity for STYLE_SHELL_PRODUCT.\n";

/** @var DiscoverProductCodeStyleMappingService $service */
$service = $container->get(DiscoverProductCodeStyleMappingService::class);
$mapping = $service->dryRun();

$after = [
    'styles' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL'),
    'parents' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NOT NULL'),
    'assignments' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]'),
    'products' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL'),
    'variants' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE [DeletedAtUtc] IS NULL'),
    'specs' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]'),
    'media' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]'),
];

foreach ($before as $key => $value) {
    printf("Delta %s=%d\n", $key, $after[$key] - $value);
}

$c = $mapping['counters'];
echo "\nPRODUCT COUNTERS\n";
foreach ([
    'totalProducts' => 'Total Products',
    'styleShellProducts' => 'STYLE_SHELL_PRODUCT',
    'targetNativeProducts' => 'TARGET_NATIVE_PRODUCT',
    'debugTestProducts' => 'DEBUG_TEST_PRODUCT',
    'unknownProducts' => 'UNKNOWN',
    'eligibleProducts' => 'Eligible Products',
    'distinctEligibleProductCodes' => 'Distinct eligible Product Codes',
    'exactStyleMatch' => 'EXACT_STYLE_MATCH',
    'missingProductStyle' => 'MISSING_PRODUCT_STYLE',
    'alreadyAssignedSame' => 'ALREADY_ASSIGNED_SAME',
    'assignmentConflict' => 'ASSIGNMENT_CONFLICT',
    'invalidProductCode' => 'INVALID_PRODUCT_CODE',
    'review' => 'REVIEW',
    'notApplicable' => 'NOT_APPLICABLE',
    'safeExistingStyleAssignmentCandidates' => 'Safe existing-style assignment candidates',
    'safeMissingStyleCreationCandidates' => 'Safe missing-style creation candidates',
    'safeAssignmentAfterCreateCandidates' => 'Safe assignment-after-create candidates',
] as $key => $label) {
    printf("%s: %s\n", $label, (string) ($c[$key] ?? 0));
}

echo "\nPRODUCTSTYLE COUNTERS\n";
printf("ProductStyles before: %d\n", $before['styles']);
printf("ProductStyles after: %d\n", $after['styles']);
printf("ProductStyles matching Product.Code: %d\n", $c['productStylesMatchingProductCode']);
printf("Missing ProductStyles required: %d\n", $c['missingProductStylesRequired']);
printf("Existing ProductStyles unused by eligible: %d\n", $c['existingProductStylesUnusedByEligible']);
printf("New ProductStyles created: 0\n");

echo "\nASSIGNMENT COUNTERS\n";
printf("Assignments before: %d\n", $before['assignments']);
printf("Assignments after: %d\n", $after['assignments']);
printf("Already correct: %d\n", $c['alreadyCorrect']);
printf("Conflicting: %d\n", $c['conflicting']);
printf("Proposed new assignments: %d\n", $c['proposedNewAssignments']);

$print = static function (string $title, array $rows, int $limit = 5): void {
    echo "\n{$title}\n";
    $n = 0;
    foreach ($rows as $row) {
        if (!$row instanceof ProductCodeStyleMappingRow) {
            continue;
        }
        printf(
            "%s  code=%s  expected=%s  exists=%s  current=%s  %s\n",
            $row->productName,
            $row->rawProductCode ?? 'null',
            $row->expectedStyleCode ?? 'null',
            $row->styleExists ? 'YES' : 'NO',
            $row->currentAssignedStyleCode ?? 'none',
            $row->decision,
        );
        ++$n;
        if ($n >= $limit) {
            break;
        }
    }
    if ($n === 0) {
        echo "(none)\n";
    }
};

$byDecision = [];
$byCode = [];
foreach ($mapping['rows'] as $row) {
    if ($row instanceof ProductCodeStyleMappingRow) {
        $byDecision[$row->decision][] = $row;
        if ($row->canonicalProductCode !== null) {
            $byCode[$row->canonicalProductCode] = $row;
        }
    }
}
$print('EXACT_STYLE_MATCH samples', $byDecision['EXACT_STYLE_MATCH'] ?? []);
$print('MISSING_PRODUCT_STYLE samples', $byDecision['MISSING_PRODUCT_STYLE'] ?? []);
$print('ASSIGNMENT_CONFLICT (all)', $byDecision['ASSIGNMENT_CONFLICT'] ?? [], 50);
$print('NOT_APPLICABLE samples', $byDecision['NOT_APPLICABLE'] ?? []);
$print('INVALID_PRODUCT_CODE (all)', $byDecision['INVALID_PRODUCT_CODE'] ?? [], 50);
$print('REVIEW (all if reasonably sized)', $byDecision['REVIEW'] ?? [], 50);

echo "\nSPECIFIC CODES\n";
foreach (['RCA', 'BVA', 'BHA', 'RRA', 'UPC', 'UPM'] as $code) {
    $row = $byCode[$code] ?? null;
    if ($row instanceof ProductCodeStyleMappingRow) {
        printf(
            "%s  %s  current=%s  expected=%s  exists=%s  %s  %s\n",
            $code,
            $row->productName,
            $row->currentAssignedStyleCode ?? 'none',
            $row->expectedStyleCode ?? 'null',
            $row->styleExists ? 'YES' : 'NO',
            $row->decision,
            $row->reason,
        );
    } else {
        echo $code . "  (no Product with this Product.Code)\n";
    }
}

$toArray = static fn (mixed $row): mixed => $row instanceof ProductCodeStyleMappingRow ? $row->toArray() : $row;
$outDir = $basePath . '/tmp';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents($outDir . '/style-product-code-mapping-dry-run.json', json_encode([
    'environment' => [
        'APP_ENV' => $settings->appEnv,
        'USER_REPOSITORY' => $settings->userRepository,
        'DB_HOST' => $settings->dbHost,
        'DB_DATABASE' => $settings->dbDatabase,
        'SQL_SERVERNAME' => $serverName,
        'SQL_DB_NAME' => $databaseName,
        'isProduction' => false,
    ],
    'before' => $before,
    'after' => $after,
    'businessRule' => $mapping['businessRule'],
    'counters' => $c,
    'sourceReads' => $mapping['sourceReads'],
    'writeGuard' => $mapping['writeGuard'],
    'styleCoverage' => $mapping['styleCoverage'],
    'duplicateProductCodes' => $mapping['duplicateProductCodes'],
    'notes' => $mapping['notes'],
    'rows' => array_map($toArray, $mapping['rows']),
    'existingStyleAssignmentCandidates' => array_map($toArray, $mapping['existingStyleAssignmentCandidates']),
    'missingStyleCreationCandidates' => array_map($toArray, $mapping['missingStyleCreationCandidates']),
    'assignmentAfterCreateCandidates' => array_map($toArray, $mapping['assignmentAfterCreateCandidates']),
    'assignmentConflicts' => array_map($toArray, $mapping['assignmentConflicts']),
    'existingAssignments' => array_map($toArray, $mapping['existingAssignments']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf("\nWrote %s/style-product-code-mapping-dry-run.json\n", $outDir);

foreach ($before as $key => $value) {
    if ($after[$key] !== $value) {
        fwrite(STDERR, "Unexpected writes detected.\n");
        exit(3);
    }
}
echo "PRODUCT_STYLES_CREATED: 0\n";
echo "PRODUCT_STYLE_ASSIGNMENTS_APPLIED: 0\n";
echo "NEW_PARENT_RELATIONSHIPS: 0\n";
echo "READY_FOR_SHARED_DATA_GENERALISATION: NO\n";
echo "PRODUCTION_READY: NO\n";
