<?php

declare(strict_types=1);

/**
 * Read-only Azure SQL pricing validation for Phase 1 comparison.
 * Does not write. Uses existing SqlConnection / entra-cli auth from .env.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Config\Settings;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$settings = Settings::fromEnvironment(array_merge($_ENV, $_SERVER));
$container = ContainerFactory::create($settings);
/** @var SqlConnection $sql */
$sql = $container->get(SqlConnection::class);

$report = ['generatedAtUtc' => gmdate('Y-m-d\TH:i:s\Z'), 'source' => 'azure-sql-readonly'];

$report['priceLists'] = $sql->fetchAll(<<<'SQL'
SELECT pl.PublicId, pl.PriceListCode, pl.PriceListName, c.CurrencyCode, r.RegionCode, m.MarketCode, pl.IsActive
FROM [product].[PriceLists] pl
LEFT JOIN [product].[Currencies] c ON c.CurrencyId = pl.CurrencyId
LEFT JOIN [product].[Regions] r ON r.RegionId = pl.RegionId
LEFT JOIN [product].[Markets] m ON m.MarketId = pl.MarketId
ORDER BY pl.PriceListCode
SQL);

$report['pricingCounts'] = $sql->fetchAll(<<<'SQL'
SELECT
  (SELECT COUNT(*) FROM [product].[ProductVariantPrices]) AS variant_price_rows,
  (SELECT COUNT(*) FROM [product].[ProductPrices]) AS product_price_rows,
  (SELECT COUNT(*) FROM [migration].[LegacyProductMappings] WHERE SourceSystemCode = N'tc') AS tc_mappings
SQL)[0] ?? null;

$report['sampleMatrix'] = $sql->fetchAll(<<<'SQL'
SELECT TOP 20
  CONVERT(NVARCHAR(36), p.PublicId) AS productPublicId,
  p.InternalCode AS productInternalCode,
  p.ProductName,
  CONVERT(NVARCHAR(36), v.PublicId) AS variantPublicId,
  v.VariantCode,
  v.Sku,
  lm.SourceProductKey AS legacyMappingKey,
  CONVERT(NVARCHAR(50), vp.UnitPrice) AS newBaseCost,
  c.CurrencyCode,
  pl.PriceListCode,
  vp.ErpSourceReference,
  CONVERT(VARCHAR(33), vp.EffectiveFromUtc, 127) AS effectiveFromUtc,
  vp.IsActive
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.ProductId = v.ProductId
LEFT JOIN [migration].[LegacyProductMappings] lm
  ON lm.ProductVariantId = v.ProductVariantId AND lm.SourceSystemCode = N'tc'
OUTER APPLY (
  SELECT TOP 1 vp2.*
  FROM [product].[ProductVariantPrices] vp2
  WHERE vp2.ProductVariantId = v.ProductVariantId AND vp2.DeletedAtUtc IS NULL
  ORDER BY CASE WHEN vp2.IsActive = 1 THEN 0 ELSE 1 END, vp2.EffectiveFromUtc DESC
) vp
LEFT JOIN [product].[Currencies] c ON c.CurrencyId = vp.CurrencyId
LEFT JOIN [product].[PriceLists] pl ON pl.PriceListId = vp.PriceListId
WHERE vp.ProductVariantPriceId IS NOT NULL
ORDER BY p.ProductName, v.VariantCode
SQL);

$report['productsWithoutMapping'] = $sql->fetchAll(<<<'SQL'
SELECT TOP 10 CONVERT(NVARCHAR(36), p.PublicId) AS productPublicId, p.InternalCode, p.ProductName, COUNT(v.ProductVariantId) AS variant_count
FROM [product].[Products] p
INNER JOIN [product].[ProductVariants] v ON v.ProductId = p.ProductId AND v.DeletedAtUtc IS NULL
LEFT JOIN [migration].[LegacyProductMappings] lm
  ON lm.ProductVariantId = v.ProductVariantId AND lm.SourceSystemCode = N'tc'
WHERE lm.LegacyProductMappingId IS NULL AND p.DeletedAtUtc IS NULL
GROUP BY p.PublicId, p.InternalCode, p.ProductName
ORDER BY variant_count DESC
SQL);

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
