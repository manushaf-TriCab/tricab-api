<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Pricing;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductDecimalMapper;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\VariantBaseCostRepository;
use TriCab\Core\Product\Domain\ValueObject\PricingUnitDefaults;

/**
 * Reads active Azure ProductVariantPrices as INTERNAL base cost for the customer resolver.
 * Prefers TCA-BASE (AUD) then TCUS-BASE (USD). Never used by public product DTOs.
 */
final class AzureSqlVariantBaseCostRepository implements VariantBaseCostRepository
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function findActiveBaseCost(string $variantPublicId, string $asOfDateUtc): ?array
    {
        $map = $this->findActiveBaseCosts([$variantPublicId], $asOfDateUtc);

        return $map[$variantPublicId] ?? null;
    }

    public function findActiveBaseCosts(array $variantPublicIds, string $asOfDateUtc): array
    {
        $ids = array_values(array_unique(array_filter($variantPublicIds, static fn ($id) => is_string($id) && $id !== '')));
        if ($ids === []) {
            return [];
        }

        $params = [':asOf' => $asOfDateUtc];
        $in = [];
        foreach ($ids as $i => $id) {
            $key = ':v' . $i;
            $in[] = $key;
            $params[$key] = $id;
        }
        $inList = implode(',', $in);

        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantCode],
    p.[InternalCode] AS [StyleCode],
    CONVERT(NVARCHAR(50), vp.[UnitPrice]) AS [BaseCost],
    c.[CurrencyCode],
    pl.[PriceListCode],
    COALESCE(pu_price.[UnitCode], pu_variant.[UnitCode], pu_product.[UnitCode], N'M') AS [UnitOfMeasureCode],
    COALESCE(pu_price.[Symbol], pu_variant.[Symbol], pu_product.[Symbol], N'm') AS [UnitOfMeasureSymbol],
    CASE
        WHEN UPPER(ISNULL(v.[VariantName], N'')) LIKE N'%MAKE%' THEN N'MAKE'
        ELSE N'UNKNOWN'
    END AS [Availability],
    ROW_NUMBER() OVER (
        PARTITION BY v.[PublicId]
        ORDER BY
            CASE pl.[PriceListCode] WHEN N'TCA-BASE' THEN 0 WHEN N'TCUS-BASE' THEN 1 ELSE 9 END,
            COALESCE(vp.[EffectiveFromUtc], '1900-01-01') DESC,
            vp.[ProductVariantPriceId] DESC
    ) AS [rn]
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
INNER JOIN [product].[ProductVariantPrices] vp
    ON vp.[ProductVariantId] = v.[ProductVariantId]
   AND vp.[DeletedAtUtc] IS NULL
   AND vp.[IsActive] = 1
   AND vp.[EffectiveToUtc] IS NULL
   AND (vp.[EffectiveFromUtc] IS NULL OR vp.[EffectiveFromUtc] <= CAST(:asOf AS DATETIME2))
INNER JOIN [product].[PriceLists] pl ON pl.[PriceListId] = vp.[PriceListId] AND pl.[DeletedAtUtc] IS NULL AND pl.[IsActive] = 1
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = vp.[CurrencyId]
LEFT JOIN [product].[SpecificationUnits] pu_price ON pu_price.[SpecificationUnitId] = vp.[SpecificationUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_variant ON pu_variant.[SpecificationUnitId] = v.[PricingUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_product ON pu_product.[SpecificationUnitId] = p.[PricingUnitId]
WHERE v.[PublicId] IN ({$inList})
  AND v.[DeletedAtUtc] IS NULL
  AND pl.[PriceListCode] IN (N'TCA-BASE', N'TCUS-BASE')
SQL,
            $params,
        );

        $out = [];
        foreach ($rows as $row) {
            if ((int) ($row['rn'] ?? 0) !== 1) {
                continue;
            }
            $vid = AzureSqlValueMapper::stringValue($row['VariantPublicId'] ?? '');
            $out[$vid] = [
                'baseCost' => ProductDecimalMapper::requireString($row['BaseCost'] ?? null, 'BaseCost'),
                'currencyCode' => AzureSqlValueMapper::stringValue($row['CurrencyCode'] ?? ''),
                'priceListCode' => AzureSqlValueMapper::stringValue($row['PriceListCode'] ?? ''),
                'uomCode' => AzureSqlValueMapper::nullableString($row['UnitOfMeasureCode'] ?? null) ?? PricingUnitDefaults::CODE,
                'uomSymbol' => AzureSqlValueMapper::nullableString($row['UnitOfMeasureSymbol'] ?? null) ?? PricingUnitDefaults::SYMBOL,
                'variantCode' => AzureSqlValueMapper::nullableString($row['VariantCode'] ?? null),
                'styleCode' => AzureSqlValueMapper::nullableString($row['StyleCode'] ?? null),
                'availability' => AzureSqlValueMapper::stringValue($row['Availability'] ?? CustomerSellPriceStatus::AVAILABILITY_UNKNOWN),
            ];
        }

        return $out;
    }
}
