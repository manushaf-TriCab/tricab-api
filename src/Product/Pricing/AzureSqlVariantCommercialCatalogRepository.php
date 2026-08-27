<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Pricing;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductDecimalMapper;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;
use TriCab\Core\Product\Application\Pricing\VariantCommercialCatalogRepository;
use TriCab\Core\Product\Domain\ValueObject\PricingUnitDefaults;
use TriCab\Core\Product\Domain\ValueObject\VariantPricingLegacyContext;

final class AzureSqlVariantCommercialCatalogRepository implements VariantCommercialCatalogRepository
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function findCommercialCatalog(string $variantPublicId, string $asOfDateUtc): ?array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantCode],
    v.[Sku],
    p.[InternalCode] AS [StyleCode],
    COALESCE(pu_variant.[UnitCode], pu_product.[UnitCode], N'M') AS [UnitOfMeasureCode],
    COALESCE(pu_variant.[Symbol], pu_product.[Symbol], N'm') AS [UnitOfMeasureSymbol],
    eem.[ExternalEntityKey] AS [ExplicitLegacyCode],
    eem.[IsActive] AS [ExplicitLegacyActive],
    vp.[UnitPrice] AS [AzureBaseCost],
    c.[CurrencyCode] AS [AzureCurrencyCode],
    pl.[PriceListCode] AS [AzurePriceListCode],
    CASE
        WHEN UPPER(ISNULL(v.[VariantName], N'')) LIKE N'%MAKE%' THEN N'MAKE'
        ELSE N'UNKNOWN'
    END AS [AzureAvailability],
    ROW_NUMBER() OVER (
        PARTITION BY v.[PublicId]
        ORDER BY
            CASE pl.[PriceListCode] WHEN N'TCA-BASE' THEN 0 WHEN N'TCUS-BASE' THEN 1 ELSE 9 END,
            COALESCE(vp.[EffectiveFromUtc], '1900-01-01') DESC,
            vp.[ProductVariantPriceId] DESC
    ) AS [rn]
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId] AND p.[DeletedAtUtc] IS NULL
LEFT JOIN [product].[SpecificationUnits] pu_variant ON pu_variant.[SpecificationUnitId] = v.[PricingUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_product ON pu_product.[SpecificationUnitId] = p.[PricingUnitId]
LEFT JOIN [product].[ExternalEntityMappings] eem
    ON eem.[InternalEntityId] = v.[ProductVariantId]
   AND eem.[EntityTypeCode] = :entityTypeCode
   AND eem.[DeletedAtUtc] IS NULL
LEFT JOIN [product].[ExternalSystems] es
    ON es.[ExternalSystemId] = eem.[ExternalSystemId]
   AND es.[SystemCode] = :systemCode
   AND es.[DeletedAtUtc] IS NULL
LEFT JOIN [product].[ProductVariantPrices] vp
    ON vp.[ProductVariantId] = v.[ProductVariantId]
   AND vp.[DeletedAtUtc] IS NULL
   AND vp.[IsActive] = 1
   AND vp.[EffectiveToUtc] IS NULL
   AND (vp.[EffectiveFromUtc] IS NULL OR vp.[EffectiveFromUtc] <= CAST(:asOf AS DATETIME2))
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = vp.[PriceListId] AND pl.[DeletedAtUtc] IS NULL AND pl.[IsActive] = 1
LEFT JOIN [product].[Currencies] c ON c.[CurrencyId] = vp.[CurrencyId]
WHERE v.[PublicId] = :variantPublicId
  AND v.[DeletedAtUtc] IS NULL
SQL,
            [
                ':variantPublicId' => $variantPublicId,
                ':asOf' => $asOfDateUtc,
                ':entityTypeCode' => VariantPricingLegacyContext::ENTITY_TYPE,
                ':systemCode' => VariantPricingLegacyContext::SYSTEM_CODE,
            ],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $variantCode = trim((string) ($row['VariantCode'] ?? ''));
        $sku = trim((string) ($row['Sku'] ?? ''));
        if ($variantCode === '' && $sku === '') {
            return null;
        }

        $explicit = trim((string) ($row['ExplicitLegacyCode'] ?? ''));
        $explicitActive = (int) ($row['ExplicitLegacyActive'] ?? 0) === 1;
        $legacyProductCode = ($explicit !== '' && $explicitActive) ? $explicit : ($variantCode !== '' ? $variantCode : $sku);

        $azureBase = null;
        if ((int) ($row['rn'] ?? 0) === 1 && $row['AzureBaseCost'] !== null) {
            $priceList = AzureSqlValueMapper::stringValue($row['AzurePriceListCode'] ?? '');
            if (in_array($priceList, ['TCA-BASE', 'TCUS-BASE'], true)) {
                $azureBase = [
                    'baseCost' => ProductDecimalMapper::requireString($row['AzureBaseCost'], 'AzureBaseCost'),
                    'currencyCode' => AzureSqlValueMapper::stringValue($row['AzureCurrencyCode'] ?? ''),
                    'priceListCode' => $priceList,
                    'availability' => AzureSqlValueMapper::stringValue($row['AzureAvailability'] ?? CustomerSellPriceStatus::AVAILABILITY_UNKNOWN),
                ];
            }
        }

        return [
            'variantPublicId' => AzureSqlValueMapper::stringValue($row['VariantPublicId'] ?? $variantPublicId),
            'variantCode' => $variantCode,
            'sku' => $sku !== '' ? $sku : null,
            'styleCode' => AzureSqlValueMapper::nullableString($row['StyleCode'] ?? null),
            'uomCode' => AzureSqlValueMapper::nullableString($row['UnitOfMeasureCode'] ?? null) ?? PricingUnitDefaults::CODE,
            'uomSymbol' => AzureSqlValueMapper::nullableString($row['UnitOfMeasureSymbol'] ?? null) ?? PricingUnitDefaults::SYMBOL,
            'legacyProductCode' => $legacyProductCode,
            'azureBase' => $azureBase,
        ];
    }
}
