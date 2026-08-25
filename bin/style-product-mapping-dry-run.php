<?php

declare(strict_types=1);

/**
 * DEVELOPMENT read-only Product → ProductStyle mapping dry-run.
 * Does not write ParentStyleId, ProductStyleAssignments, products, variants,
 * specs, media, WP, tc, blob, classification, catalogue, or regions.
 *
 * Usage (from tricab-api):
 *   php bin/style-product-mapping-dry-run.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\ProductStyleMappingRow;
use TriCab\Core\Product\Application\DTO\UnresolvedLegacyStyleProduct;
use TriCab\Core\Product\Application\Service\DiscoverExistingProductStyleMappingService;

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
$beforeParents = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NOT NULL');
$beforeAssignments = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]');
$beforeProducts = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL');
$beforeVariants = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE [DeletedAtUtc] IS NULL');
$beforeSpecs = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]');
$beforeMedia = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]');

echo "\nSELECTED HIERARCHY RELATIONSHIPS = []\n";
echo "EMPTY_STYLE_HIERARCHY_CONFIRMED: YES\n";
echo "Hierarchy apply was not called.\n";
printf("ParentStyleId rows before=%d\n", $beforeParents);

/** @var DiscoverExistingProductStyleMappingService $service */
$service = $container->get(DiscoverExistingProductStyleMappingService::class);
$mapping = $service->dryRun();

$afterParents = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL AND [ParentStyleId] IS NOT NULL');
$afterAssignments = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]');
$afterProducts = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL');
$afterVariants = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductVariants] WHERE [DeletedAtUtc] IS NULL');
$afterSpecs = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]');
$afterMedia = $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]');

printf("ParentStyleId rows after=%d\n", $afterParents);
printf(
    "Delta parents=%d assignments=%d products=%d variants=%d specs=%d media=%d\n",
    $afterParents - $beforeParents,
    $afterAssignments - $beforeAssignments,
    $afterProducts - $beforeProducts,
    $afterVariants - $beforeVariants,
    $afterSpecs - $beforeSpecs,
    $afterMedia - $beforeMedia,
);

$c = $mapping['counters'];
echo "\nPRODUCT COUNTERS\n";
printf("Total target Products: %d\n", $c['totalTargetProducts']);
printf("Active Products: %d\n", $c['activeProducts']);
printf("Archived Products: %d\n", $c['archivedProducts']);
printf("Legacy-migrated Products: %d\n", $c['legacyMigratedProducts']);
printf("Target-native Products: %d\n", $c['targetNativeProducts']);
printf("Unknown-origin Products: %d\n", $c['unknownOriginProducts']);
printf("Products already assigned: %d\n", $c['productsAlreadyAssigned']);
printf("Products unassigned: %d\n", $c['productsUnassigned']);
printf("EXACT_MATCH: %d\n", $c['exactMatch']);
printf("ALREADY_ASSIGNED_SAME: %d\n", $c['alreadyAssignedSame']);
printf("ALREADY_ASSIGNED_CONFLICT: %d\n", $c['alreadyAssignedConflict']);
printf("NO_STYLE: %d\n", $c['noStyle']);
printf("STYLE_NOT_FOUND: %d\n", $c['styleNotFound']);
printf("AMBIGUOUS_SOURCE: %d\n", $c['ambiguousSource']);
printf("PRODUCT_IDENTITY_UNRESOLVED: %d\n", $c['productIdentityUnresolved']);
printf("Unresolved legacy products (gap, not target states): %d\n", $c['unresolvedLegacyProducts']);
printf("REVIEW: %d\n", $c['review']);
printf("NOT_APPLICABLE: %d\n", $c['notApplicable']);
printf("SAFE_ASSIGNMENT_CANDIDATES: %d\n", $c['safeAssignmentCandidates']);

echo "\nSTYLE COUNTERS\n";
printf("ProductStyles total: %d\n", $c['productStylesTotal']);
printf("Legacy-confirmed ProductStyles: %d\n", $c['legacyConfirmedProductStyles']);
printf("Target-only ProductStyles: %d\n", $c['targetOnlyProductStyles']);
printf("Styles referenced by exact matches: %d\n", $c['stylesReferencedByExactMatches']);
printf("Styles with zero exact-match products: %d\n", $c['stylesWithZeroExactMatchProducts']);
printf("Distinct canonical legacy style codes on matched Products: %d\n", $c['distinctCanonicalLegacyStyleCodesOnMatchedProducts']);
printf("Legacy style codes missing from ProductStyles: %d\n", $c['legacyStyleCodesMissingFromProductStyles']);

echo "\nPRODUCTS PER STYLE (EXACT_MATCH unassigned)\n";
$shown = 0;
foreach ($mapping['productsPerStyle'] as $styleCode => $n) {
    printf("%s\t%d\n", $styleCode, $n);
    ++$shown;
    if ($shown >= 40) {
        echo "...\n";
        break;
    }
}
if ($mapping['productsPerStyle'] === []) {
    echo "(none)\n";
}

$printRows = static function (string $title, array $rows, int $limit = 5): void {
    echo "\n{$title}\n";
    $n = 0;
    foreach ($rows as $row) {
        if (!$row instanceof ProductStyleMappingRow) {
            continue;
        }
        printf(
            "%s  %s  legacy=%s  canonical=%s  matched=%s  assigned=%s  %s\n",
            $row->productPublicId,
            $row->productCode ?? $row->productName,
            $row->rawLegacyStyleCode ?? 'null',
            $row->canonicalStyleCode ?? 'null',
            $row->matchedStyleCode ?? 'null',
            $row->currentAssignedStyleCode ?? 'none',
            $row->reason,
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
foreach ($mapping['rows'] as $row) {
    if ($row instanceof ProductStyleMappingRow) {
        $byDecision[$row->decision][] = $row;
    }
}
$printRows('EXACT_MATCH samples', $byDecision['EXACT_MATCH'] ?? [], 5);
$printRows('NO_STYLE samples', $byDecision['NO_STYLE'] ?? [], 5);
$printRows('STYLE_NOT_FOUND samples', $byDecision['STYLE_NOT_FOUND'] ?? [], 5);
$printRows('ALREADY_ASSIGNED_CONFLICT (all)', $byDecision['ALREADY_ASSIGNED_CONFLICT'] ?? [], 50);
$printRows('AMBIGUOUS_SOURCE (all if reasonably sized)', $byDecision['AMBIGUOUS_SOURCE'] ?? [], 50);
$printRows('CABLE_STYLE cases', $mapping['cableStyleCases'] ?? [], 50);
$numeric = [];
foreach ($mapping['rows'] as $row) {
    if ($row instanceof ProductStyleMappingRow && $row->numericStyle) {
        $numeric[] = $row;
    }
}
$printRows('NUMERIC / REVIEW samples', $numeric, 10);

echo "\nUNRESOLVED LEGACY PRODUCTS (migration gap; not created)\n";
$u = 0;
foreach ($mapping['unresolvedLegacyProducts'] as $row) {
    if (!$row instanceof UnresolvedLegacyStyleProduct) {
        continue;
    }
    printf("%s  style=%s  %s\n", $row->sourceKey, $row->rawStyleCode ?? 'null', $row->title ?? '');
    ++$u;
    if ($u >= 10) {
        printf("... %d total\n", count($mapping['unresolvedLegacyProducts']));
        break;
    }
}
if ($u === 0) {
    echo "(none)\n";
}

$toArray = static function (mixed $row): mixed {
    if ($row instanceof ProductStyleMappingRow || $row instanceof UnresolvedLegacyStyleProduct) {
        return $row->toArray();
    }

    return $row;
};

$outDir = $basePath . '/tmp';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$encoded = json_encode([
    'environment' => [
        'APP_ENV' => $settings->appEnv,
        'USER_REPOSITORY' => $settings->userRepository,
        'DB_HOST' => $settings->dbHost,
        'DB_DATABASE' => $settings->dbDatabase,
        'SQL_SERVERNAME' => $serverName,
        'SQL_DB_NAME' => $databaseName,
        'isProduction' => false,
    ],
    'hierarchy' => $mapping['hierarchy'],
    'identity' => $mapping['identity'],
    'before' => [
        'parents' => $beforeParents,
        'assignments' => $beforeAssignments,
        'products' => $beforeProducts,
        'variants' => $beforeVariants,
        'specs' => $beforeSpecs,
        'media' => $beforeMedia,
    ],
    'after' => [
        'parents' => $afterParents,
        'assignments' => $afterAssignments,
        'products' => $afterProducts,
        'variants' => $afterVariants,
        'specs' => $afterSpecs,
        'media' => $afterMedia,
    ],
    'counters' => $c,
    'sourceReads' => $mapping['sourceReads'],
    'writeGuard' => $mapping['writeGuard'],
    'styleCoverage' => $mapping['styleCoverage'],
    'productsPerStyle' => $mapping['productsPerStyle'],
    'notes' => $mapping['notes'],
    'safeAssignmentCandidates' => array_map($toArray, $mapping['safeAssignmentCandidates']),
    'rows' => array_map($toArray, $mapping['rows']),
    'unresolvedLegacyProducts' => array_map($toArray, $mapping['unresolvedLegacyProducts']),
    'cableStyleCases' => array_map($toArray, $mapping['cableStyleCases']),
    'existingAssignments' => array_map($toArray, $mapping['existingAssignments']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($outDir . '/style-product-mapping-dry-run.json', $encoded);
printf("\nWrote %s/style-product-mapping-dry-run.json\n", $outDir);

if (
    $afterParents !== $beforeParents
    || $afterAssignments !== $beforeAssignments
    || $afterProducts !== $beforeProducts
    || $afterVariants !== $beforeVariants
    || $afterSpecs !== $beforeSpecs
    || $afterMedia !== $beforeMedia
) {
    fwrite(STDERR, "Unexpected writes detected.\n");
    exit(3);
}

echo "PRODUCT_STYLE_ASSIGNMENTS_APPLIED: 0\n";
echo "NEW_PARENT_RELATIONSHIPS_APPLIED: 0\n";
echo "READY_FOR_SHARED_DATA_GENERALISATION: NO\n";
echo "PRODUCTION_READY: NO\n";
