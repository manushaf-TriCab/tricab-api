<?php

declare(strict_types=1);

/**
 * DEVELOPMENT ONLY: create and populate [product].[ProductCodeIndex].
 *
 * Applies, in order:
 *   database/scripts/development/create-product-code-index.sql   (the shape)
 *   ../catalog-load/sql/36_azure_code_index_load.sql             (the ~27,000 codes)
 *
 * Both are idempotent, so re-running refreshes in place. Regenerate the load with
 * `python build_azure_code_index_load.py` in catalog-load first if the source CSVs
 * have moved on.
 *
 * Refuses production. Does not touch WordPress or legacy tc MySQL.
 *
 * Usage (from tricab-api):
 *   php bin/apply-product-code-index.php [--verify-only]
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$verifyOnly = in_array('--verify-only', $argv, true);

printf("APP_ENV=%s  isProduction=%s\n", $settings->appEnv, $settings->isProduction() ? 'YES' : 'NO');
printf("DB_HOST=%s  DB_DATABASE=%s\n", $settings->dbHost, $settings->dbDatabase);

if ($settings->isProduction()) {
    fwrite(STDERR, "PRODUCTION. Stopping before schema apply.\n");
    exit(2);
}

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "USER_REPOSITORY is not azure-sql — nothing to apply.\n");
    exit(1);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$identity = $db->fetchAll('SELECT DB_NAME() AS [D], @@SERVERNAME AS [S], SUSER_SNAME() AS [L]');
$databaseName = (string) ($identity[0]['D'] ?? '');
$serverName = (string) ($identity[0]['S'] ?? '');
printf("SQL DB_NAME()=%s  @@SERVERNAME=%s  login=%s\n", $databaseName, $serverName, (string) ($identity[0]['L'] ?? ''));

if (strcasecmp($databaseName, $settings->dbDatabase) !== 0) {
    fwrite(STDERR, "Database identity mismatch. Stopping.\n");
    exit(2);
}
if (stripos($serverName, 'prod') !== false || stripos($databaseName, 'prod') !== false) {
    fwrite(STDERR, "Server/database name looks like production. Stopping.\n");
    exit(2);
}

$runScript = static function (SqlConnection $db, string $path): void {
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    $batches = [];
    $current = [];
    foreach (preg_split('/\R/', $sql) ?: [] as $line) {
        if (preg_match('/^\s*GO\s*(?:--.*)?$/i', $line) === 1) {
            $batches[] = implode("\n", $current);
            $current = [];
            continue;
        }
        $current[] = $line;
    }
    $batches[] = implode("\n", $current);
    $batches = array_values(array_filter($batches, static fn (string $b): bool => trim($b) !== ''));
    foreach ($batches as $index => $batch) {
        $started = microtime(true);
        $db->execute($batch);
        printf("  ok %s batch %d/%d (%.1fs)\n", basename($path), $index + 1, count($batches), microtime(true) - $started);
    }
};

$createPath = $basePath . '/database/scripts/development/create-product-code-index.sql';
$loadPath = dirname($basePath) . '/catalog-load/sql/36_azure_code_index_load.sql';

if (!$verifyOnly) {
    foreach ([$createPath, $loadPath] as $path) {
        if (!is_file($path)) {
            fwrite(STDERR, "Missing input: {$path}\n");
            exit(1);
        }
    }

    echo "Applying create-product-code-index.sql...\n";
    $runScript($db, $createPath);
    echo "Applying 36_azure_code_index_load.sql (" . number_format((int) (filesize($loadPath) / 1024)) . " KiB)...\n";
    $runScript($db, $loadPath);
}

echo "\n--- verification ---\n";

$scalar = static fn (SqlConnection $db, string $sql): string
    => (string) (array_values($db->fetchAll($sql)[0] ?? ['?'])[0] ?? '?');

printf("rows                  : %s\n", $scalar($db, 'SELECT COUNT(*) FROM [product].[ProductCodeIndex]'));
printf("linked to a variant   : %s\n", $scalar($db, 'SELECT COUNT(*) FROM [product].[ProductCodeIndex] WHERE [ProductVariantId] IS NOT NULL'));
printf("sized in mm2          : %s\n", $scalar($db, 'SELECT COUNT(*) FROM [product].[ProductCodeIndex] WHERE [CrossSectionMm2] IS NOT NULL'));
printf("current cables        : %s\n", $scalar($db, 'SELECT COUNT(*) FROM [product].[ProductCodeIndex] WHERE [IsCurrent] = 1'));
printf("distinct style codes  : %s\n", $scalar($db, 'SELECT COUNT(DISTINCT [StyleCode]) FROM [product].[ProductCodeIndex]'));

echo "\nstock status:\n";
foreach ($db->fetchAll('SELECT ISNULL([StockStatus], N\'(none)\') AS [S], COUNT(*) AS [N] FROM [product].[ProductCodeIndex] GROUP BY [StockStatus] ORDER BY COUNT(*) DESC') as $row) {
    printf("  %-12s %s\n", (string) $row['S'], (string) $row['N']);
}

echo "\ncross-section unit by core type:\n";
foreach ($db->fetchAll('SELECT [CoreTypeCode] AS [C], [CrossSectionUnit] AS [U], COUNT(*) AS [N] FROM [product].[ProductCodeIndex] GROUP BY [CoreTypeCode], [CrossSectionUnit] ORDER BY [CoreTypeCode], [CrossSectionUnit]') as $row) {
    printf("  core %-2s -> %-5s %s\n", (string) $row['C'], (string) $row['U'], (string) $row['N']);
}

/*
 * The integrity check the plan asks for: segment 8 read without segment 7 would
 * give the same mm2 for the same raw value under different core types. Each of
 * these ten values appears under both 'C' and 'A' and must resolve differently.
 */
echo "\nsegment 7/8 ambiguity — the same raw value under different core types:\n";
$ambiguous = $db->fetchAll(
    <<<'SQL'
SELECT
    [CrossSectionRaw] AS [Raw],
    MAX(CASE WHEN [CoreTypeCode] = N'C' THEN [CrossSectionMm2] END) AS [AsC],
    MAX(CASE WHEN [CoreTypeCode] = N'A' THEN [CrossSectionMm2] END) AS [AsA],
    MAX(CASE WHEN [CoreTypeCode] = N'A' THEN [CrossSectionUnit] END) AS [UnitA]
FROM [product].[ProductCodeIndex]
WHERE [CrossSectionRaw] IN (N'1', N'4', N'6', N'8', N'10', N'16', N'300', N'400', N'500', N'1000')
GROUP BY [CrossSectionRaw]
HAVING MAX(CASE WHEN [CoreTypeCode] = N'C' THEN [CrossSectionMm2] END) IS NOT NULL
   AND MAX(CASE WHEN [CoreTypeCode] = N'A' THEN [CrossSectionMm2] END) IS NOT NULL
ORDER BY TRY_CONVERT(DECIMAL(10,3), [CrossSectionRaw])
SQL
);
$failures = 0;
foreach ($ambiguous as $row) {
    $asC = (float) $row['AsC'];
    $asA = (float) $row['AsA'];
    $same = abs($asC - $asA) < 0.001;
    if ($same) {
        $failures++;
    }
    printf(
        "  %-6s C=%-10s A=%-10s (%s) %s\n",
        (string) $row['Raw'],
        number_format($asC, 3),
        number_format($asA, 3),
        (string) $row['UnitA'],
        $same ? 'FAIL — read without segment 7' : 'ok',
    );
}
printf("  %d ambiguous values checked, %d failures\n", count($ambiguous), $failures);

echo "\nround-trip: every indexed code must rebuild from its own segments\n";
$roundTrip = $db->fetchAll(
    <<<'SQL'
SELECT COUNT(*) AS [Mismatched]
FROM [product].[ProductCodeIndex]
WHERE [CodeValue] <> CONCAT(
        [StyleCode], ISNULL([StyleSuffix], N''), ISNULL([JacketTypeCode], N''),
        N'-', ISNULL([CoreColourConfig], N''), ISNULL([BraidArmourScreen], N''),
        ISNULL([ConductorCode], N''), ISNULL([CoreConfigTail], N''),
        N'/', [CoreCount], [CoreTypeCode],
        [CrossSectionRaw], ISNULL([JacketColour], N''), ISNULL([CodeTail], N'')
    )
SQL
);
$mismatched = (int) ($roundTrip[0]['Mismatched'] ?? -1);
printf("  codes that do not round-trip: %d\n", $mismatched);

if ($failures > 0 || $mismatched > 0) {
    fwrite(STDERR, "\nVerification failed.\n");
    exit(1);
}

echo "\nOK\n";
