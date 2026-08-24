<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Migration\GuardedReadOnlyMysqlConnection;
use TriCab\Api\Product\Migration\MysqlLegacyTcRepository;
use TriCab\Api\Product\Migration\ReadOnlyMysqlConnectionFactory;
use TriCab\Core\Product\Migration\Live\InMemoryLegacySqlStatementLogger;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();
$env = array_merge($_ENV, $_SERVER);
$legacyConfig = LiveLegacySourceConfig::fromEnvironment($env);
$settings = Settings::fromEnvironment($env);
$container = ContainerFactory::create($settings);
/** @var SqlConnection $sql */
$sql = $container->get(SqlConnection::class);

$tcRepo = null;
if ($legacyConfig->tcConfigured()) {
    $factory = new ReadOnlyMysqlConnectionFactory(new LegacyReadOnlySqlGuard(), new InMemoryLegacySqlStatementLogger());
    $conn = $factory->create((string) $legacyConfig->tcDsn, (string) $legacyConfig->tcUser, (string) $legacyConfig->tcPassword, 'tc', $legacyConfig->tcSslMode);
    $tcRepo = new MysqlLegacyTcRepository($conn);
}

$rows = $sql->fetchAll(<<<'SQL'
SELECT TOP 25
  CONVERT(NVARCHAR(36), p.PublicId) AS productPublicId,
  p.ProductName,
  p.InternalCode,
  CONVERT(NVARCHAR(36), v.PublicId) AS variantPublicId,
  v.VariantCode,
  v.Sku,
  lm.SourceProductKey AS legacyItemCode,
  (SELECT COUNT(*) FROM [product].[ProductVariantPrices] vp WHERE vp.ProductVariantId = v.ProductVariantId AND vp.DeletedAtUtc IS NULL) AS newPriceRowCount
FROM [migration].[LegacyProductMappings] lm
INNER JOIN [product].[ProductVariants] v ON v.ProductVariantId = lm.ProductVariantId
INNER JOIN [product].[Products] p ON p.ProductId = v.ProductId
WHERE lm.SourceSystemCode = N'tc'
ORDER BY p.ProductName, v.VariantCode
SQL);

$matrix = [];
foreach ($rows as $row) {
    $legacyCode = trim((string) ($row['legacyItemCode'] ?? ''));
    $variantCode = trim((string) ($row['VariantCode'] ?? ''));
    $lookupCode = $legacyCode !== '' ? $legacyCode : $variantCode;
    if ($lookupCode !== '' && str_contains($lookupCode, ':')) {
        $lookupCode = substr($lookupCode, strrpos($lookupCode, ':') + 1);
    }

    $legacyCosts = [];
    foreach ([2507, 2517, 2519] as $dbco) {
        $legacyCosts[(string) $dbco] = $tcRepo?->findLatestBaseCost($lookupCode, $dbco);
    }

    $exact2507 = null;
    if ($tcRepo !== null && $conn instanceof GuardedReadOnlyMysqlConnection) {
        // count exact price rows via guarded connection
    }

    $match2507 = $legacyCosts['2507'];
    $matrix[] = [
        'productPublicId' => $row['productPublicId'],
        'productName' => $row['ProductName'],
        'variantPublicId' => $row['variantPublicId'],
        'variantCode' => $variantCode,
        'legacyMappingKey' => $legacyCode,
        'lookupItemCode' => $lookupCode,
        'newPriceRowCount' => (int) ($row['newPriceRowCount'] ?? 0),
        'legacyCost_2507' => $match2507['cost'] ?? null,
        'legacyCost_2517' => $legacyCosts['2517']['cost'] ?? null,
        'legacyCost_2519' => $legacyCosts['2519']['cost'] ?? null,
        'legacyDc_2507' => $match2507['lastUpdatedUtc'] ?? null,
        'classification' => classify($row, $lookupCode, $legacyCosts),
    ];
}

function classify(array $row, string $lookupCode, array $legacyCosts): string
{
    if ($lookupCode === '') {
        return 'MISSING_LEGACY_CODE';
    }
    if ((int) ($row['newPriceRowCount'] ?? 0) === 0) {
        return 'MISSING_NEW';
    }
    if ($legacyCosts['2507'] === null && $legacyCosts['2517'] === null && $legacyCosts['2519'] === null) {
        return 'MISSING_LEGACY';
    }
    return 'NEEDS_NEW_PRICE_DATA';
}

echo json_encode([
    'generatedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'),
    'matrix' => $matrix,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
