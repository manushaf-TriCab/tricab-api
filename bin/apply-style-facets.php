<?php

declare(strict_types=1);

/**
 * DEVELOPMENT ONLY: seed style-level search facets onto [product].[ProductStyles].
 *
 * Applies ../catalog-load/sql/37_azure_style_facets_seed.sql, which adds the nine
 * facet definitions (CABLE-TYPE, VOLTAGE-CLASS, IS-LSZH, IS-FIRE-RESISTANT,
 * IS-ARMOURED, HAS-SCREEN, IS-TERMITE-RESISTANT, MAX-TEMP-C, STYLE-SYNONYMS),
 * their option vocabularies, and one value row per style that has a source.
 *
 * Regenerate first with `python build_azure_style_facets_seed.py` in catalog-load.
 * Idempotent, so re-running refreshes in place.
 *
 * Refuses production. Does not touch WordPress or legacy tc MySQL.
 *
 * Usage (from tricab-api):
 *   php bin/apply-style-facets.php [--verify-only]
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$verifyOnly = in_array('--verify-only', $argv, true);

printf("APP_ENV=%s  DB_DATABASE=%s\n", $settings->appEnv, $settings->dbDatabase);

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION. Stopping.\n");
    exit(2);
}
if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "USER_REPOSITORY is not azure-sql — nothing to apply.\n");
    exit(1);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$identity = $db->fetchAll('SELECT DB_NAME() AS [D], @@SERVERNAME AS [S]');
$databaseName = (string) ($identity[0]['D'] ?? '');
$serverName = (string) ($identity[0]['S'] ?? '');
printf("SQL DB_NAME()=%s  @@SERVERNAME=%s\n", $databaseName, $serverName);

if (strcasecmp($databaseName, $settings->dbDatabase) !== 0
    || stripos($serverName, 'prod') !== false
    || stripos($databaseName, 'prod') !== false) {
    fwrite(STDERR, "Database identity check failed. Stopping.\n");
    exit(2);
}

$seedPath = dirname($basePath) . '/catalog-load/sql/37_azure_style_facets_seed.sql';

if (!$verifyOnly) {
    if (!is_file($seedPath)) {
        fwrite(STDERR, "Missing {$seedPath}. Run build_azure_style_facets_seed.py first.\n");
        exit(1);
    }
    printf("Applying 37_azure_style_facets_seed.sql (%s KiB)...\n", number_format((int) (filesize($seedPath) / 1024)));
    $sql = file_get_contents($seedPath);
    if ($sql === false) {
        throw new RuntimeException('Unable to read ' . $seedPath);
    }
    $started = microtime(true);
    $db->execute($sql);
    printf("  ok (%.1fs)\n", microtime(true) - $started);
}

echo "\n--- verification ---\n";

$facets = ['CABLE-TYPE', 'VOLTAGE-CLASS', 'IS-LSZH', 'IS-FIRE-RESISTANT', 'IS-ARMOURED',
           'HAS-SCREEN', 'IS-TERMITE-RESISTANT', 'MAX-TEMP-C', 'STYLE-SYNONYMS'];
$list = "N'" . implode("', N'", $facets) . "'";

echo "definitions and how many styles carry each:\n";
foreach ($db->fetchAll(
    <<<SQL
SELECT d.[DefinitionCode] AS [Code], d.[DataTypeCode] AS [Type], d.[IsFilterable] AS [Filter],
       COUNT(v.[ProductStyleSpecificationValueId]) AS [Styles]
FROM [product].[SpecificationDefinitions] d
LEFT JOIN [product].[ProductStyleSpecificationValues] v
    ON v.[SpecificationDefinitionId] = d.[SpecificationDefinitionId] AND v.[DeletedAtUtc] IS NULL
WHERE d.[DefinitionCode] IN ({$list}) AND d.[DeletedAtUtc] IS NULL
GROUP BY d.[DefinitionCode], d.[DataTypeCode], d.[IsFilterable], d.[DisplayOrder]
ORDER BY d.[DisplayOrder]
SQL
) as $row) {
    printf("  %-22s %-14s filterable=%s  styles=%s\n", (string) $row['Code'], (string) $row['Type'], (string) $row['Filter'], (string) $row['Styles']);
}

$missing = array_diff($facets, array_column($db->fetchAll(
    "SELECT [DefinitionCode] FROM [product].[SpecificationDefinitions] WHERE [DefinitionCode] IN ({$list}) AND [DeletedAtUtc] IS NULL"
), 'DefinitionCode'));
if ($missing !== []) {
    fwrite(STDERR, 'Missing definitions after apply: ' . implode(', ', $missing) . "\n");
    exit(1);
}

echo "\ncable type across styles:\n";
foreach ($db->fetchAll(
    <<<'SQL'
SELECT o.[OptionCode] AS [Code], COUNT(*) AS [N]
FROM [product].[ProductStyleSpecificationValues] v
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
INNER JOIN [product].[SpecificationOptions] o ON o.[SpecificationOptionId] = v.[SpecificationOptionId]
WHERE d.[DefinitionCode] = N'CABLE-TYPE' AND v.[DeletedAtUtc] IS NULL
GROUP BY o.[OptionCode] ORDER BY COUNT(*) DESC
SQL
) as $row) {
    printf("  %-18s %s\n", (string) $row['Code'], (string) $row['N']);
}

/*
 * The end-to-end question Phase 1 exists to make answerable: filter styles by
 * facet, then codes by size, in one query. If this returns rows, structured
 * search has something to stand on.
 */
echo "\nend-to-end probe — LSZH marine styles, 4 to 25 mm2, 4 cores or more:\n";
$probe = $db->fetchAll(
    <<<'SQL'
SELECT TOP 8
    i.[CodeValue], i.[StyleCode], st.[DisplayName], i.[CoreCount], i.[CrossSectionMm2],
    ISNULL(i.[StockStatus], N'-') AS [Stock]
FROM [product].[ProductCodeIndex] i
INNER JOIN [product].[ProductStyles] st ON st.[StyleCode] = i.[StyleCode] AND st.[DeletedAtUtc] IS NULL
INNER JOIN [product].[ProductStyleSpecificationValues] tv
    ON tv.[ProductStyleId] = st.[ProductStyleId] AND tv.[DeletedAtUtc] IS NULL
INNER JOIN [product].[SpecificationDefinitions] td
    ON td.[SpecificationDefinitionId] = tv.[SpecificationDefinitionId] AND td.[DefinitionCode] = N'CABLE-TYPE'
INNER JOIN [product].[SpecificationOptions] to_
    ON to_.[SpecificationOptionId] = tv.[SpecificationOptionId] AND to_.[OptionCode] = N'MARINE-OFFSHORE'
WHERE i.[CrossSectionMm2] BETWEEN 4 AND 25
  AND i.[CoreCount] >= 4
  AND i.[CoreTypeCode] = N'C'
ORDER BY i.[CrossSectionMm2], i.[CodeValue]
SQL
);
foreach ($probe as $row) {
    printf(
        "  %-24s %-4s %-38s %2sC %6s mm2  %s\n",
        (string) $row['CodeValue'],
        (string) $row['StyleCode'],
        substr((string) $row['DisplayName'], 0, 36),
        (string) $row['CoreCount'],
        number_format((float) $row['CrossSectionMm2'], 1),
        (string) $row['Stock'],
    );
}
printf("  %d rows\n", count($probe));

if ($probe === []) {
    fwrite(STDERR, "\nThe end-to-end probe returned nothing — facets and codes are not joining.\n");
    exit(1);
}

echo "\nOK\n";
