<?php

declare(strict_types=1);

/**
 * DEVELOPMENT dry-run: existing style inventory + hierarchy discovery.
 *
 * Read-only against WordPress, legacy tc MySQL, and the legacy filesystem.
 * Does not create Azure styles. Does not apply ParentStyleId.
 *
 * Usage (from tricab-api):
 *   php bin/style-inventory-discovery-preview.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\Service\DiscoverLegacyStyleHierarchyService;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';
CoreAutoload::register();

$settings = Environment::settings($basePath);
$report = [
    'productionWrites' => 0,
    'legacyWordpressWrites' => 0,
    'legacyTcMysqlWrites' => 0,
    'legacyFilesystemWrites' => 0,
    'developmentAzureSqlWrites' => 0,
    'blobWrites' => 0,
    'forbiddenWriteAttempts' => 0,
    'relationshipsApplied' => 0,
];

printf("APP_ENV=%s\n", $settings->appEnv);
printf("USER_REPOSITORY=%s\n", $settings->userRepository);
printf("DB_HOST=%s\n", $settings->dbHost);
printf("DB_DATABASE=%s\n", $settings->dbDatabase);
printf("isProduction=%s\n", $settings->isProduction() ? 'YES' : 'NO');
printf("blobAccount=%s\n", $settings->blobAccountName);
printf("blobEndpoint=%s\n", $settings->blobEndpoint);
printf("blobContainer=%s\n", $settings->blobContainerProducts);

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION. Stopping.\n");
    exit(2);
}

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "USER_REPOSITORY is not azure-sql. Stopping.\n");
    exit(1);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);
$identity = $db->fetchAll(
    'SELECT DB_NAME() AS [DatabaseName], @@SERVERNAME AS [ServerName], SUSER_SNAME() AS [LoginName]',
);
$databaseName = (string) ($identity[0]['DatabaseName'] ?? '');
$serverName = (string) ($identity[0]['ServerName'] ?? '');
printf("SQL @@SERVERNAME=%s\n", $serverName);
printf("SQL DB_NAME()=%s\n", $databaseName);
printf("SQL SUSER_SNAME()=%s\n", (string) ($identity[0]['LoginName'] ?? ''));

if (strcasecmp($databaseName, $settings->dbDatabase) !== 0) {
    fwrite(STDERR, "Database identity mismatch. Stopping.\n");
    exit(2);
}
if (stripos($serverName, 'prod') !== false || stripos($databaseName, 'prod') !== false) {
    fwrite(STDERR, "Server/database name looks like production. Stopping.\n");
    exit(2);
}

$determination = sprintf(
    'NON-PRODUCTION (%s Azure SQL %s / %s)',
    $settings->appEnv,
    $serverName,
    $databaseName,
);
printf("Production determination=%s\n", $determination);

/** @var DiscoverLegacyStyleHierarchyService $discovery */
$discovery = $container->get(DiscoverLegacyStyleHierarchyService::class);
$preview = $discovery->preview();

$inventory = is_array($preview['inventory'] ?? null) ? $preview['inventory'] : [];
$counters = is_array($inventory['counters'] ?? null) ? $inventory['counters'] : [];
$hierarchy = is_array($preview['counters'] ?? null) ? $preview['counters'] : [];
$writeGuard = is_array($preview['writeGuard'] ?? null) ? $preview['writeGuard'] : [];

printf("\nEXISTING STYLE INVENTORY\n\n");
printf("Legacy rows scanned           %s\n", number_format((int) ($counters['sourceStyleRows'] ?? 0)));
printf("Rows containing style         %s\n", number_format((int) ($counters['nonEmptyStyleRows'] ?? 0)));
printf("Raw distinct style codes      %s\n", number_format((int) ($counters['rawDistinctStyles'] ?? 0)));
printf("Canonical styles              %s\n", number_format((int) ($counters['canonicalDistinctStyles'] ?? 0)));
printf("\nExisting Azure styles         %s\n", number_format((int) ($counters['existingTargetStyles'] ?? 0)));
printf("Matched existing              %s\n", number_format((int) ($counters['matchedTargetStyles'] ?? 0)));
printf("Missing target                %s\n", number_format((int) ($counters['missingTargetStyles'] ?? 0)));
printf("Target-only                   %s\n", number_format((int) ($counters['targetOnlyStyles'] ?? 0)));
printf("Review                        %s\n", number_format((int) ($counters['review'] ?? 0)));
printf("Invalid                       %s\n", number_format((int) ($counters['invalid'] ?? 0)));
printf("\nWrites\n");
printf("WordPress                     %d\n", (int) ($writeGuard['wordpressWrites'] ?? 0));
printf("tc MySQL                      %d\n", (int) ($writeGuard['tcWrites'] ?? 0));
printf("Legacy filesystem             %d\n", (int) ($writeGuard['filesystemWrites'] ?? 0));
printf("Azure SQL                     %d\n", $report['developmentAzureSqlWrites']);
printf("Blob                          %d\n", $report['blobWrites']);

printf("\nSTYLE_INVENTORY_READY_TO_CREATE: %s\n", !empty($inventory['readyToCreate']) ? 'YES' : 'NO');
printf("STYLE_INVENTORY_CREATED: NO (operator approval not present in this execution)\n");

printf("\nPROPOSED STYLE HIERARCHY\n\n");
$trees = is_array($preview['proposedTrees'] ?? null) ? $preview['proposedTrees'] : [];
foreach ($trees as $tree) {
    if (!is_array($tree)) {
        continue;
    }
    $kind = (string) ($tree['kind'] ?? 'proposed');
    $root = (string) ($tree['rootStyleCode'] ?? '');
    printf("%s %s\n", $kind === 'standalone' ? 'Standalone' : '▼', $root);
    $children = is_array($tree['children'] ?? null) ? $tree['children'] : [];
    $last = array_key_last($children);
    foreach ($children as $i => $child) {
        if (!is_array($child)) {
            continue;
        }
        $branch = $i === $last ? '└──' : '├──';
        $confidence = (string) ($child['confidence'] ?? '');
        $state = (string) ($child['applicationState'] ?? 'NOT_APPLIED');
        printf("   %s %-12s %s [%s]\n", $branch, (string) ($child['styleCode'] ?? ''), $confidence, $state);
    }
}

printf("\nRelationships applied: %d\n", (int) ($hierarchy['relationshipsApplied'] ?? 0));
printf("HIGH=%d MEDIUM=%d LOW=%d REVIEW=%d standalone=%d ambiguous=%d cycles=%d\n",
    (int) ($hierarchy['highConfidence'] ?? 0),
    (int) ($hierarchy['mediumConfidence'] ?? 0),
    (int) ($hierarchy['lowConfidence'] ?? 0),
    (int) ($hierarchy['review'] ?? 0),
    (int) ($hierarchy['standaloneStyles'] ?? 0),
    (int) ($hierarchy['ambiguousParentCases'] ?? 0),
    (int) ($hierarchy['cycleConflicts'] ?? 0),
);

$outDir = $basePath . '/tmp';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$path = $outDir . '/style-inventory-discovery-preview.json';
file_put_contents($path, json_encode([
    'environment' => [
        'APP_ENV' => $settings->appEnv,
        'USER_REPOSITORY' => $settings->userRepository,
        'DB_HOST' => $settings->dbHost,
        'DB_DATABASE' => $settings->dbDatabase,
        'SQL_SERVERNAME' => $serverName,
        'SQL_DB_NAME' => $databaseName,
        'blobAccount' => $settings->blobAccountName,
        'productionDetermination' => $determination,
    ],
    'preview' => $preview,
    'safety' => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf("\nWrote %s\n", $path);
