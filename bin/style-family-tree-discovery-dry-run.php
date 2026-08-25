<?php

declare(strict_types=1);

/**
 * DEVELOPMENT read-only Style Family Tree + exact Product.Code mapping dry-run.
 * Does not create styles, set ParentStyleId, change assignments, specs, or media.
 *
 * Usage (from tricab-api):
 *   php bin/style-family-tree-discovery-dry-run.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\Service\DiscoverProductStyleFamilyTreeService;

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
echo "Products map to exact child StyleCode, never the family parent.\n";

/** @var DiscoverProductStyleFamilyTreeService $service */
$service = $container->get(DiscoverProductStyleFamilyTreeService::class);
$report = $service->dryRun();

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

$c = $report['counters'];
echo "\nCOUNTERS\n";
foreach ($c as $key => $value) {
    printf("%s: %s\n", $key, is_scalar($value) ? (string) $value : json_encode($value));
}

$printTree = static function (array $nodes, string $indent = '') use (&$printTree): void {
    $n = count($nodes);
    foreach ($nodes as $i => $node) {
        $branch = $indent === '' ? '' : (($i === $n - 1) ? '└── ' : '├── ');
        $mapped = !empty($node['productMapped']) ? ' [Product mapped]' : '';
        $exists = !empty($node['styleExists']) ? '' : ' [STYLE MISSING]';
        $state = ($node['applicationState'] ?? '') === 'ALREADY_PERSISTED' ? ' [PERSISTED]' : ' [NOT APPLIED]';
        printf(
            "%s%s%s  [%s]%s%s%s\n",
            $indent,
            $branch,
            $node['styleCode'],
            $node['decision'] ?? $node['role'] ?? '',
            $mapped,
            $exists,
            $state,
        );
        $childIndent = $indent === '' ? '' : $indent . (($i === $n - 1) ? '    ' : '│   ');
        if ($indent === '') {
            $childIndent = ($i === $n - 1) ? '    ' : '│   ';
        }
        $printTree($node['children'] ?? [], $childIndent);
    }
};

echo "\nTRICAB STYLE TREE\n";
$printTree($report['proposedTree'] ?? []);

echo "\nOPERATOR CONFIRMED\n";
foreach ($report['operatorConfirmedRelationships'] as $rel) {
    printf(
        "%s → %s  parentExists=%s childExists=%s currentParent=%s assignment=%s → expected=%s  %s\n",
        $rel['parentStyleCode'],
        $rel['childStyleCode'],
        !empty($rel['parentExists']) ? 'YES' : 'NO',
        !empty($rel['childExists']) ? 'YES' : 'NO',
        $rel['currentParent'] ?? 'none',
        $rel['currentAssignment'] ?? 'none',
        $rel['expectedProductStyle'],
        $rel['applicationState'],
    );
}

echo "\nAPPROVE CANDIDATES\n";
foreach ($report['proposedAdditionalRelationships'] as $rel) {
    printf("%s → %s  %s  %s\n", $rel['parentStyleCode'], $rel['childStyleCode'], $rel['decision'], $rel['reason']);
}

echo "\nRCA / BV CHECK\n";
foreach ($report['productMappings'] as $row) {
    if (!in_array($row['productCode'], ['RCA', 'BVA', 'RCP', 'RCM', 'RCC', 'BVC', 'BVM', 'BVP'], true)) {
        continue;
    }
    printf(
        "%s  %s  expected=%s parent=%s current=%s  %s\n",
        $row['productCode'],
        $row['productName'],
        $row['expectedAssignment'],
        $row['proposedParentStyle'] ?? 'none',
        $row['currentAssignment'] ?? 'none',
        $row['assignmentDecision'],
    );
}

$outDir = $basePath . '/tmp';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
file_put_contents($outDir . '/style-family-tree-discovery-dry-run.json', json_encode([
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
    'report' => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf("\nWrote %s/style-family-tree-discovery-dry-run.json\n", $outDir);

foreach ($before as $key => $value) {
    if ($after[$key] !== $value) {
        fwrite(STDERR, "Unexpected writes detected.\n");
        exit(3);
    }
}

echo "PRODUCTSTYLE_WRITES: 0\n";
echo "PARENT_RELATIONSHIP_WRITES: 0\n";
echo "PRODUCTSTYLE_ASSIGNMENT_WRITES: 0\n";
echo "READY_FOR_STYLE_FAMILY_APPLY: NO\n";
echo "PRODUCTION_READY: NO\n";
