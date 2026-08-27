<?php

declare(strict_types=1);

/**
 * Phase 13.6C — read-only pilot baseline check (Azure SQL only path after container boot).
 * No writes. Console-safe.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$coreSrc = dirname(__DIR__) . '/core/src';
spl_autoload_register(static function (string $class) use ($coreSrc): void {
    if (!str_starts_with($class, 'TriCab\\Core\\')) {
        return;
    }
    $path = $coreSrc . '/' . str_replace('\\', '/', substr($class, strlen('TriCab\\Core\\'))) . '.php';
    if (is_file($path)) {
        require $path;
    }
}, true, true);

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();
$settings = Environment::settings($root);
if (strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(1);
}

$c = ContainerFactory::create($settings);
$db = $c->get(SqlConnection::class);

$server = $db->fetchAll('SELECT @@SERVERNAME AS s, DB_NAME() AS d, SUSER_SNAME() AS u')[0] ?? [];
$batch = 'p134-tca-BVA-20260826T010912';
$pilot = $db->fetchAll("
SELECT COUNT(*) AS c,
       SUM(CASE WHEN pvp.IsActive = 1 THEN 1 ELSE 0 END) AS active,
       SUM(CASE WHEN pl.PriceListCode = N'TCA-BASE' THEN 1 ELSE 0 END) AS tcaBase,
       SUM(CASE WHEN cur.CurrencyCode = N'AUD' THEN 1 ELSE 0 END) AS aud
FROM [product].[ProductVariantPrices] pvp
INNER JOIN [product].[PriceLists] pl ON pl.PriceListId = pvp.PriceListId
INNER JOIN [product].[Currencies] cur ON cur.CurrencyId = pvp.CurrencyId
WHERE pvp.ErpSourceReference LIKE N'%' + ? + N'%'
   OR pvp.MigrationBatchId = ?
", [$batch, $batch]);

// Prefer MigrationBatchId if column exists; fall back handled above.
$byBatch = $db->fetchAll("
SELECT COUNT(*) AS c,
       SUM(CASE WHEN IsActive = 1 THEN 1 ELSE 0 END) AS active
FROM [product].[ProductVariantPrices]
WHERE MigrationBatchId = ?
", [$batch]);

$dup = $db->fetchAll("
SELECT COUNT(*) AS d FROM (
  SELECT ProductVariantId
  FROM [product].[ProductVariantPrices]
  WHERE MigrationBatchId = ?
  GROUP BY ProductVariantId
  HAVING COUNT(*) > 1
) x
", [$batch]);

$rows = $db->fetchAll("
SELECT CONVERT(NVARCHAR(36), ProductVariantPriceId) AS id,
       CONVERT(NVARCHAR(36), ProductVariantId) AS vid,
       CONVERT(NVARCHAR(50), UnitPrice) AS unit,
       CONVERT(NVARCHAR(33), EffectiveFrom, 126) AS eff
FROM [product].[ProductVariantPrices]
WHERE MigrationBatchId = ?
ORDER BY ProductVariantPriceId
", [$batch]);

$payload = '';
foreach ($rows as $r) {
    $payload .= ($payload === '' ? '' : '|') . $r['id'] . '|' . $r['vid'] . '|' . $r['unit'] . '|' . $r['eff'];
}
$checksum = hash('sha256', $payload);

$counts = [];
foreach ([
    'ProductVariantPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductVariantPrices] WHERE DeletedAtUtc IS NULL',
    'ProductPrices' => 'SELECT COUNT(*) AS c FROM [product].[ProductPrices] WHERE DeletedAtUtc IS NULL',
    'Customers' => 'SELECT COUNT(*) AS c FROM [customer].[Customers]',
    'Ph13' => "SELECT COUNT(*) AS c FROM [customer].[Customers] WHERE CustomerCode = N'PH13-PRICE-VALIDATION'",
] as $k => $sql) {
    $counts[$k] = (int) ($db->fetchAll($sql)[0]['c'] ?? 0);
}

$expected = 'f27805c79846ee6f16942154f5a46aaf912a193545403685afd4212068746485';
$out = [
    'appEnv' => $settings->appEnv,
    'production' => 'NO',
    'server' => $server,
    'pilotByBatch' => $byBatch[0] ?? null,
    'pilotAlt' => $pilot[0] ?? null,
    'duplicates' => (int) ($dup[0]['d'] ?? 0),
    'checksum' => $checksum,
    'checksumExpected' => $expected,
    'checksumMatch' => hash_equals($expected, $checksum),
    'counts' => $counts,
];

file_put_contents($root . '/tmp/phase136c-pilot-baseline.json', json_encode($out, JSON_PRETTY_PRINT));
echo json_encode($out, JSON_PRETTY_PRINT) . "\n";
