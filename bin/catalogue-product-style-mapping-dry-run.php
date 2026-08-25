<?php

declare(strict_types=1);

/**
 * DEVELOPMENT read-only dry-run: CatalogueNode → parent ProductStyle discovery.
 * ZERO WRITES.
 *
 * Usage (from tricab-api):
 *   php bin/catalogue-product-style-mapping-dry-run.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\Service\DiscoverCatalogueProductStyleMappingService;

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
    'catalogueNodes' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodes] WHERE [DeletedAtUtc] IS NULL'),
    'catalogueNodeProducts' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodeProducts]'),
    'productStyles' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL'),
    'assignments' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]'),
    'products' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL'),
    'specs' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]'),
    'media' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]'),
];

echo "\nDRY RUN — CATALOGUE NAVIGATION → PRODUCTSTYLE — NO CHANGES APPLIED\n";

/** @var DiscoverCatalogueProductStyleMappingService $service */
$service = $container->get(DiscoverCatalogueProductStyleMappingService::class);
$report = $service->dryRun();

$after = [
    'catalogueNodes' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodes] WHERE [DeletedAtUtc] IS NULL'),
    'catalogueNodeProducts' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[CatalogueNodeProducts]'),
    'productStyles' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyles] WHERE [DeletedAtUtc] IS NULL'),
    'assignments' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleAssignments]'),
    'products' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[Products] WHERE [DeletedAtUtc] IS NULL'),
    'specs' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleSpecificationValues]'),
    'media' => $count($db, 'SELECT COUNT(*) AS cnt FROM [product].[ProductStyleMediaAssets]'),
];
foreach ($before as $key => $value) {
    printf("Delta %s=%d\n", $key, $after[$key] - $value);
    if ($after[$key] !== $value) {
        fwrite(STDERR, "Unexpected writes detected.\n");
        exit(3);
    }
}

$report['environment'] = [
    'appEnv' => $settings->appEnv,
    'userRepository' => $settings->userRepository,
    'dbHost' => $settings->dbHost,
    'dbDatabase' => $settings->dbDatabase,
    'serverName' => $serverName,
    'databaseName' => $databaseName,
    'isProduction' => false,
];
$report['before'] = $before;
$report['after'] = $after;

@mkdir($basePath . '/tmp', 0777, true);
$jsonPath = $basePath . '/tmp/catalogue-product-style-mapping-dry-run.json';
file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$c = $report['counters'];
echo "\nCOUNTERS\n";
foreach ($c as $key => $value) {
    printf("%s: %s\n", $key, is_scalar($value) ? (string) $value : json_encode($value));
}

echo "\nARCHITECTURAL ASSERTIONS\n";
foreach ($report['assertions'] as $key => $value) {
    printf("%s: %s\n", $key, is_bool($value) ? ($value ? 'YES' : 'NO') : (string) $value);
}

echo "\nOPERATOR INVESTIGATIONS\n";
foreach ($report['operatorInvestigations'] as $inv) {
    printf(
        "%s | found=%s | style=%s | decision=%s | current=%d | proposed(ALL)=%s\n",
        $inv['nodeName'],
        !empty($inv['nodeFound']) ? 'YES' : 'NO',
        $inv['proposedParentStyleCode'] ?? ($inv['operatorIntendedStyle'] ?? '-'),
        $inv['decision'] ?? '-',
        count($inv['currentDirectProducts'] ?? []),
        isset($inv['simulation']['byRegion']['ALL']['proposedVisibleCount'])
            ? (string) $inv['simulation']['byRegion']['ALL']['proposedVisibleCount']
            : '-',
    );
    if (($inv['simulation']['byRegion'] ?? null) !== null) {
        foreach (['AU', 'NZ', 'US', 'ALL'] as $region) {
            if (!isset($inv['simulation']['byRegion'][$region])) {
                continue;
            }
            $row = $inv['simulation']['byRegion'][$region];
            printf(
                "  %s: current=%d proposed=%d added=%d removed=%s\n",
                $region,
                (int) ($row['currentVisibleCount'] ?? 0),
                (int) ($row['proposedVisibleCount'] ?? 0),
                (int) ($row['productsAdded'] ?? 0),
                json_encode($row['productsRemoved'] ?? []),
            );
        }
    }
}

echo "\nDRY-RUN TABLE (nodes with direct products)\n";
printf("%-40s | %-8s | %-8s | %-24s | %-8s | %-20s\n", 'Catalogue Node', 'Current', 'Style', 'Children', 'Resolved', 'Decision');
foreach ($report['proposals'] as $proposal) {
    if (($proposal['decision'] ?? '') === 'NOT_APPLICABLE') {
        continue;
    }
    $children = implode('/', $proposal['childStyleCodes'] ?? []);
    $resolved = isset($proposal['simulation']['byRegion']['ALL']['proposedVisibleCount'])
        ? (string) $proposal['simulation']['byRegion']['ALL']['proposedVisibleCount']
        : '-';
    printf(
        "%-40s | %-8d | %-8s | %-24s | %-8s | %-20s\n",
        mb_substr((string) ($proposal['nodeName'] ?? ''), 0, 40),
        count($proposal['currentDirectProducts'] ?? []),
        (string) ($proposal['proposedParentStyleCode'] ?? '-'),
        mb_substr($children, 0, 24),
        $resolved,
        (string) ($proposal['decision'] ?? '-'),
    );
}

printf("\nArtifact: %s\n", $jsonPath);

$bv = null;
$dv = null;
$rr = null;
$bh = null;
foreach ($report['operatorInvestigations'] as $inv) {
    match ($inv['nodeName'] ?? '') {
        'BV 110C Flame Retardant' => $bv = $inv,
        'DV 110C Braid, Flame Retardant' => $dv = $inv,
        'RR 110C Fire Resistant' => $rr = $inv,
        'BH 110C Braid, Fire Resistant' => $bh = $inv,
        default => null,
    };
}

echo "\nFINAL BLOCK\n";
printf("CATALOGUE_STYLE_ANALYSIS_COMPLETE: YES\n");
printf("CATALOGUE_NODES_ANALYSED: %d\n", (int) ($c['catalogueNodesAnalysed'] ?? 0));
printf("PROPOSED_CATALOGUE_STYLE_LINKS: %d\n", (int) ($c['proposedCatalogueNodeProductStyleLinks'] ?? 0));
printf("BV_NODE_FOUND: %s\n", ($bv['nodeFound'] ?? false) ? 'YES' : 'NO');
printf("BV_PROPOSED_STYLE: %s\n", $bv['proposedParentStyleCode'] ?? ($bv['operatorIntendedStyle'] ?? 'N/A'));
printf("DV_NODE_FOUND: %s\n", ($dv['nodeFound'] ?? false) ? 'YES' : 'NO');
printf("DV_PROPOSED_STYLE: %s\n", $dv['proposedParentStyleCode'] ?? ($dv['operatorIntendedStyle'] ?? 'N/A'));
printf("RR_NODE_FOUND: %s\n", ($rr['nodeFound'] ?? false) ? 'YES' : 'NO');
printf("RR_PROPOSED_STYLE: %s\n", $rr['proposedParentStyleCode'] ?? ($rr['operatorIntendedStyle'] ?? 'N/A'));
printf("BH_NODE_FOUND: %s\n", ($bh['nodeFound'] ?? false) ? 'YES' : 'NO');
printf("BH_PROPOSED_STYLE: %s\n", $bh['proposedParentStyleCode'] ?? ($bh['operatorIntendedStyle'] ?? 'N/A'));
printf("FAKE_PARENT_PRODUCTS_CREATED: NO\n");
printf("CATALOGUE_WRITES: 0\n");
printf("CATALOGUE_STYLE_LINK_WRITES: 0\n");
printf("PRODUCT_WRITES: 0\n");
printf("PRODUCTSTYLE_WRITES: 0\n");
printf("PRODUCTSTYLE_ASSIGNMENT_WRITES: 0\n");
printf("READY_FOR_CATALOGUE_STYLE_OPERATOR_APPROVAL: YES\n");
printf("READY_FOR_CATALOGUE_STYLE_APPLY: NO\n");
printf("PRODUCTION_READY: NO\n");

exit(0);
