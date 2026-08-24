<?php

declare(strict_types=1);

/**
 * Read-only live tc pricing validation harness.
 * SELECT / SHOW / DESCRIBE / INFORMATION_SCHEMA only — uses LegacyReadOnlySqlGuard.
 *
 * Usage: php bin/legacy-pricing-live-validation.php [--json]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Product\Migration\GuardedReadOnlyMysqlConnection;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$env = array_merge($_ENV, $_SERVER);
$config = LiveLegacySourceConfig::fromEnvironment($env);
if (!$config->tcConfigured()) {
    fwrite(STDERR, "LEGACY_TC_* not configured.\n");
    exit(1);
}

$factory = new ReadOnlyMysqlConnectionFactory(new LegacyReadOnlySqlGuard(), new InMemoryLegacySqlStatementLogger());
$conn = $factory->create(
    (string) $config->tcDsn,
    (string) $config->tcUser,
    (string) $config->tcPassword,
    'tc',
    $config->tcSslMode,
);

/** @param array<string, mixed> $params */
$query = static function (GuardedReadOnlyMysqlConnection $conn, string $sql, array $params = [], string $method = 'validate'): array {
    return $conn->query($sql, $params, 'PricingValidation', $method);
};

$report = [
    'generatedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
    'source' => 'live-tc-mysql-readonly',
];

// 1. tc.price schema
$describe = $query($conn, 'DESCRIBE `price`', [], 'describePrice');
$indexes = $query($conn, 'SHOW INDEX FROM `price`', [], 'indexPrice');
$rowCount = $query($conn, 'SELECT COUNT(*) AS cnt FROM `price`', [], 'countPrice');
$dbcoDistinct = $query($conn, 'SELECT `dbco`, COUNT(*) AS row_count FROM `price` GROUP BY `dbco` ORDER BY `dbco`', [], 'dbcoCounts');
$dcRange = $query($conn, 'SELECT MIN(`dc`) AS min_dc, MAX(`dc`) AS max_dc FROM `price`', [], 'dcRange');
$dupes = $query($conn, 'SELECT `code`, `dbco`, COUNT(*) AS cnt FROM `price` GROUP BY `code`, `dbco` HAVING COUNT(*) > 1 ORDER BY cnt DESC LIMIT 20', [], 'codeDbcoDupes');
$dupesTotal = $query($conn, 'SELECT COUNT(*) AS group_count FROM (SELECT `code`, `dbco` FROM `price` GROUP BY `code`, `dbco` HAVING COUNT(*) > 1) x', [], 'codeDbcoDupeGroups');
$badCost = $query($conn, <<<'SQL'
SELECT
  SUM(CASE WHEN `cost` IS NULL THEN 1 ELSE 0 END) AS null_cost,
  SUM(CASE WHEN `cost` = 0 THEN 1 ELSE 0 END) AS zero_cost,
  SUM(CASE WHEN `cost` < 0 THEN 1 ELSE 0 END) AS negative_cost
FROM `price`
SQL, [], 'badCost');

$report['priceSchema'] = [
    'columns' => $describe,
    'indexes' => $indexes,
    'rowCount' => (int) ($rowCount[0]['cnt'] ?? 0),
    'dbcoCounts' => $dbcoDistinct,
    'dcRange' => $dcRange[0] ?? null,
    'duplicateCodeDbcoSample' => $dupes,
    'duplicateCodeDbcoGroupCount' => (int) ($dupesTotal[0]['group_count'] ?? 0),
    'badCostCounts' => $badCost[0] ?? null,
];

// 2. Debtor companies of interest
foreach ([2507, 2517, 2519] as $dbco) {
    $report['dbco'][$dbco] = $query(
        $conn,
        'SELECT COUNT(*) AS row_count, COUNT(DISTINCT `code`) AS distinct_codes FROM `price` WHERE `dbco` = :dbco',
        ['dbco' => $dbco],
        'dbco_' . $dbco,
    )[0] ?? null;
}

// 3. Latest-price rule samples — pick codes with history per dbco
$sampleCodes = $query($conn, <<<'SQL'
SELECT p.code, p.dbco, COUNT(*) AS history_rows, MAX(p.dc) AS max_dc
FROM `price` p
GROUP BY p.code, p.dbco
HAVING COUNT(*) > 1
ORDER BY history_rows DESC
LIMIT 8
SQL, [], 'sampleHistoryCodes');

$latestSamples = [];
foreach ($sampleCodes as $sample) {
    $code = (string) $sample['code'];
    $dbco = (int) $sample['dbco'];
    $rows = $query(
        $conn,
        'SELECT `code`, `dbco`, `cost`, `setup`, `uwt`, `br`, `dc` FROM `price` WHERE `code` = :code AND `dbco` = :dbco ORDER BY `dc` DESC LIMIT 5',
        ['code' => $code, 'dbco' => $dbco],
        'latestSample',
    );
    $sameMax = $query(
        $conn,
        'SELECT COUNT(*) AS cnt FROM `price` WHERE `code` = :code AND `dbco` = :dbco AND `dc` = (SELECT MAX(`dc`) FROM `price` WHERE `code` = :code2 AND `dbco` = :dbco2)',
        ['code' => $code, 'dbco' => $dbco, 'code2' => $code, 'dbco2' => $dbco],
        'sameMaxDc',
    );
    $latestSamples[] = [
        'code' => $code,
        'dbco' => $dbco,
        'historyRows' => (int) $sample['history_rows'],
        'topRowsByDcDesc' => $rows,
        'rowsAtMaxDc' => (int) ($sameMax[0]['cnt'] ?? 0),
    ];
}
$report['latestPriceSamples'] = $latestSamples;

// 5/6. Item code vs tpro mapping samples
$tproCols = array_column($query($conn, 'SELECT COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'tpro\'', [], 'tproCols'), 'c');
$tproSelect = ['ida'];
foreach (['product_code', 'style', 'sku'] as $col) {
    if (in_array($col, $tproCols, true)) {
        $tproSelect[] = $col;
    }
}
$tproSamples = $query($conn, 'SELECT `' . implode('`, `', $tproSelect) . '` FROM `tpro` ORDER BY `ida` LIMIT 15', [], 'tproSamples');
$itemMatches = [];
foreach ($tproSamples as $row) {
    $ida = (string) ($row['ida'] ?? '');
    $productCode = (string) ($row['product_code'] ?? $ida);
    foreach ([2507, 2517] as $dbco) {
        $exactProduct = $query(
            $conn,
            'SELECT COUNT(*) AS cnt FROM `price` WHERE `code` = :code AND `dbco` = :dbco',
            ['code' => $productCode, 'dbco' => $dbco],
            'matchProductCode',
        );
        $exactIda = $query(
            $conn,
            'SELECT COUNT(*) AS cnt FROM `price` WHERE `code` = :code AND `dbco` = :dbco',
            ['code' => $ida, 'dbco' => $dbco],
            'matchIda',
        );
        $mask = null;
        if (strlen($productCode) >= 5) {
            $prefix = substr($productCode, 0, 5) . 'XX';
            $maskRows = $query(
                $conn,
                'SELECT COUNT(*) AS cnt FROM `price` WHERE `code` LIKE :mask AND `dbco` = :dbco',
                ['mask' => str_replace('XX', '%', $prefix), 'dbco' => $dbco],
                'maskCount',
            );
            $mask = (int) ($maskRows[0]['cnt'] ?? 0);
        }
        $itemMatches[] = [
            'ida' => $ida,
            'product_code' => $productCode,
            'dbco' => $dbco,
            'priceRows_product_code' => (int) ($exactProduct[0]['cnt'] ?? 0),
            'priceRows_ida' => (int) ($exactIda[0]['cnt'] ?? 0),
            'maskLikeRows' => $mask,
        ];
    }
}
$report['itemCodeMatchSamples'] = $itemMatches;

// Mask-like codes inventory
$maskCodes = $query($conn, <<<'SQL'
SELECT `code`, `dbco`, COUNT(*) AS history_rows
FROM `price`
WHERE `code` LIKE '%XX%' OR `code` LIKE '%-XX-%' OR `code` LIKE '%STYLE%'
GROUP BY `code`, `dbco`
ORDER BY history_rows DESC
LIMIT 25
SQL, [], 'maskCodes');

$report['maskCodeSamples'] = $maskCodes;

// 10. cyrte FX
$cyrteExists = $query($conn, "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cyrte'", [], 'cyrteExists');
if ($cyrteExists !== []) {
    $report['cyrteSchema'] = $query($conn, 'DESCRIBE `cyrte`', [], 'describeCyrte');
    $report['cyrteRows'] = $query($conn, 'SELECT * FROM `cyrte` ORDER BY 1 LIMIT 50', [], 'cyrteSample');
}

// 12. tmktm
$tmktmExists = $query($conn, "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tmktm'", [], 'tmktmExists');
if ($tmktmExists !== []) {
    $report['tmktmSchema'] = $query($conn, 'DESCRIBE `tmktm`', [], 'describeTmktm');
    $report['tmktmRowCount'] = (int) ($query($conn, 'SELECT COUNT(*) AS cnt FROM `tmktm`', [], 'countTmktm')[0]['cnt'] ?? 0);
    $fields = ['stylg', 'cntry', 'ter', 'mkt', 'pt', 'cond', 'mgnr_lo', 'mgnr_hi', 'min', 'max'];
    $existing = array_column($query($conn, 'SELECT COLUMN_NAME AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'tmktm\'', [], 'tmktmCols'), 'c');
    $coverage = [];
    foreach ($fields as $field) {
        if (!in_array($field, $existing, true)) {
            $coverage[$field] = ['present' => false];
            continue;
        }
        $coverage[$field] = $query(
            $conn,
            sprintf('SELECT COUNT(*) AS total, COUNT(DISTINCT `%s`) AS distinct_values FROM `tmktm`', $field),
            [],
            'tmktmCoverage_' . $field,
        )[0] ?? null;
        $coverage[$field]['present'] = true;
    }
    $report['tmktmFieldCoverage'] = $coverage;
    $report['tmktmSample'] = $query($conn, 'SELECT * FROM `tmktm` LIMIT 10', [], 'tmktmSample');
}

// 11. Customer context tables (metadata only, masked aggregates)
foreach (['contacts', 'cust'] as $table) {
    $exists = $query($conn, 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', ['t' => $table], 'table_' . $table);
    if ($exists !== []) {
        $report['customerTables'][$table] = [
            'columns' => $query($conn, 'DESCRIBE `' . $table . '`', [], 'describe_' . $table),
            'rowCount' => (int) ($query($conn, 'SELECT COUNT(*) AS cnt FROM `' . $table . '`', [], 'count_' . $table)[0]['cnt'] ?? 0),
        ];
    }
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "JSON encode failed\n");
    exit(1);
}

echo $json;
