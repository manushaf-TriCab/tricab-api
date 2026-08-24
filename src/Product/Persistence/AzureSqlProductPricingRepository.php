<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductBaseCostHistoryEntry;
use TriCab\Core\Product\Application\DTO\ProductPricingMatrixRow;
use TriCab\Core\Product\Application\DTO\ProductPricingOverview;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Application\Exception\InvalidPricingUnit;
use TriCab\Core\Product\Application\Exception\OverlappingBaseCostRecord;
use TriCab\Core\Product\Application\Exception\ProductPricingConcurrencyConflict;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\PricingUnitDefaults;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Support\PricingRecordStatus;

/**
 * Restricted Azure SQL pricing repository (admin-only).
 */
final class AzureSqlProductPricingRepository implements ProductPricingRepository
{
    use AzureSqlTransaction;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        $productRows = $this->fetchProductPriceRows($productPublicId);
        $variantRows = $this->fetchVariantPriceRows($productPublicId);

        return array_map(
            fn (array $row): ProductPricingSummary => $this->hydrateSummary($row),
            [...$productRows, ...$variantRows],
        );
    }

    public function getOverview(ProductPublicId $productPublicId): ProductPricingOverview
    {
        $matrix = $this->listMatrixByProduct($productPublicId);
        $variantCount = count($matrix);
        $withPricing = 0;
        $missing = 0;
        $current = 0;
        $scheduled = 0;
        $expired = 0;
        $inactive = 0;

        foreach ($matrix as $row) {
            if ($row->status === PricingRecordStatus::MISSING) {
                ++$missing;
                continue;
            }
            ++$withPricing;
            match ($row->status) {
                PricingRecordStatus::CURRENT => ++$current,
                PricingRecordStatus::SCHEDULED => ++$scheduled,
                PricingRecordStatus::EXPIRED => ++$expired,
                PricingRecordStatus::INACTIVE => ++$inactive,
                default => null,
            };
        }

        $productRecords = $this->fetchProductPriceRows($productPublicId);
        foreach ($productRecords as $record) {
            $summary = $this->hydrateSummary($record);
            ++$current;
            if ($summary->status === PricingRecordStatus::SCHEDULED) {
                --$current;
                ++$scheduled;
            } elseif ($summary->status === PricingRecordStatus::EXPIRED) {
                --$current;
                ++$expired;
            } elseif ($summary->status === PricingRecordStatus::INACTIVE) {
                --$current;
                ++$inactive;
            }
        }

        $defaultUnit = $this->loadProductDefaultUnitContext($productPublicId);

        return new ProductPricingOverview(
            variantCount: $variantCount,
            variantsWithPricing: $withPricing,
            missingBaseCostCount: $missing,
            currentRecordCount: $current,
            scheduledRecordCount: $scheduled,
            expiredRecordCount: $expired,
            inactiveRecordCount: $inactive,
            defaultUnitOfMeasurePublicId: $defaultUnit['defaultUnitOfMeasurePublicId'],
            defaultUnitOfMeasureCode: $defaultUnit['defaultUnitOfMeasureCode'],
            defaultUnitOfMeasureSymbol: $defaultUnit['defaultUnitOfMeasureSymbol'],
            defaultUnitSource: $defaultUnit['defaultUnitSource'],
        );
    }

    public function setProductDefaultPricingUnit(
        ProductPublicId $productPublicId,
        ?string $pricingUnitPublicId,
        UserId $actorUserId,
    ): ProductPricingOverview {
        return $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $pricingUnitPublicId, $actorUserId): ProductPricingOverview {
                $unitId = $this->resolveOptionalSpecificationUnitId($pricingUnitPublicId);
                $rows = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [product].[Products]
SET [PricingUnitId] = :pricingUnitId,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT INSERTED.[PublicId]
WHERE [PublicId] = :productPublicId
  AND [DeletedAtUtc] IS NULL
SQL,
                    [
                        ':pricingUnitId' => $unitId,
                        ':updatedByUserId' => $actorUserId->toInt(),
                        ':productPublicId' => $productPublicId->toString(),
                    ],
                );
                if ($rows === []) {
                    throw ProductPersistenceException::productNotFound($productPublicId->toString());
                }

                return $this->getOverview($productPublicId);
            },
        );
    }

    public function listMatrixByProduct(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantName],
    v.[VariantCode],
    v.[Sku],
    v.[DisplayOrder],
    lm.[SourceProductKey] AS [LegacyItemCode],
    CONVERT(NVARCHAR(36), cp.[PublicId]) AS [PricingPublicId],
    cp.[CurrencyCode],
    CONVERT(NVARCHAR(50), cp.[UnitPrice]) AS [BaseCost],
    cp.[PriceListCode],
    cp.[ErpSourceReference],
    CONVERT(VARCHAR(33), cp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), cp.[EffectiveToUtc], 127) AS [EffectiveToUtc],
    cp.[IsActive],
    cp.[RowVersion],
    COALESCE(pu_price.[UnitCode], pu_variant.[UnitCode], pu_product.[UnitCode], N'M') AS [UnitOfMeasureCode],
    COALESCE(pu_price.[Symbol], pu_variant.[Symbol], pu_product.[Symbol], N'm') AS [UnitOfMeasureSymbol]
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
OUTER APPLY (
    SELECT TOP (1)
        vp.[PublicId],
        vp.[UnitPrice],
        vp.[EffectiveFromUtc],
        vp.[EffectiveToUtc],
        vp.[IsActive],
        vp.[RowVersion],
        vp.[ErpSourceReference],
        vp.[SpecificationUnitId],
        c.[CurrencyCode],
        pl.[PriceListCode]
    FROM [product].[ProductVariantPrices] vp
    INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = vp.[CurrencyId]
    LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = vp.[PriceListId]
    WHERE vp.[ProductVariantId] = v.[ProductVariantId]
      AND vp.[DeletedAtUtc] IS NULL
    ORDER BY
        CASE WHEN vp.[IsActive] = 1 THEN 0 ELSE 1 END,
        COALESCE(vp.[EffectiveFromUtc], '1900-01-01') DESC,
        vp.[ProductVariantPriceId] DESC
) cp
LEFT JOIN [product].[SpecificationUnits] pu_price ON pu_price.[SpecificationUnitId] = cp.[SpecificationUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_variant ON pu_variant.[SpecificationUnitId] = v.[PricingUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_product ON pu_product.[SpecificationUnitId] = p.[PricingUnitId]
LEFT JOIN [migration].[LegacyProductMappings] lm
    ON lm.[ProductVariantId] = v.[ProductVariantId]
   AND lm.[SourceSystemCode] = N'tc'
WHERE p.[PublicId] = :productPublicId
  AND v.[DeletedAtUtc] IS NULL
ORDER BY v.[DisplayOrder], v.[VariantName]
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        $out = [];
        foreach ($rows as $row) {
            $isActive = AzureSqlValueMapper::intValue($row['IsActive'] ?? 0) === 1;
            $effectiveFrom = AzureSqlValueMapper::nullableString($row['EffectiveFromUtc'] ?? null);
            $effectiveTo = AzureSqlValueMapper::nullableString($row['EffectiveToUtc'] ?? null);
            $pricingPublicId = AzureSqlValueMapper::nullableString($row['PricingPublicId'] ?? null);
            $status = $pricingPublicId === null
                ? PricingRecordStatus::MISSING
                : PricingRecordStatus::resolve($isActive, $effectiveFrom, $effectiveTo);

            $out[] = new ProductPricingMatrixRow(
                variantPublicId: AzureSqlValueMapper::stringValue($row['VariantPublicId'] ?? ''),
                variantName: AzureSqlValueMapper::stringValue($row['VariantName'] ?? ''),
                variantCode: AzureSqlValueMapper::nullableString($row['VariantCode'] ?? null),
                sku: AzureSqlValueMapper::nullableString($row['Sku'] ?? null),
                displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                legacyItemCode: AzureSqlValueMapper::nullableString($row['LegacyItemCode'] ?? null),
                currentPricingPublicId: $pricingPublicId,
                currencyCode: AzureSqlValueMapper::nullableString($row['CurrencyCode'] ?? null),
                baseCost: $row['BaseCost'] !== null ? ProductDecimalMapper::requireString($row['BaseCost'], 'BaseCost') : null,
                unitOfMeasureCode: AzureSqlValueMapper::nullableString($row['UnitOfMeasureCode'] ?? null) ?? PricingUnitDefaults::CODE,
                unitOfMeasureSymbol: AzureSqlValueMapper::nullableString($row['UnitOfMeasureSymbol'] ?? null) ?? PricingUnitDefaults::SYMBOL,
                priceListCode: AzureSqlValueMapper::nullableString($row['PriceListCode'] ?? null),
                priceSourceCode: PricingSourceCodec::decode(AzureSqlValueMapper::nullableString($row['ErpSourceReference'] ?? null)),
                effectiveFromUtc: $effectiveFrom,
                effectiveToUtc: $effectiveTo,
                status: $status,
                isActive: $isActive,
                rowVersion: $this->rowVersionBase64($row['RowVersion'] ?? null),
            );
        }

        return $out;
    }

    public function listHistory(
        ProductPublicId $productPublicId,
        ?string $variantPublicId = null,
        ?string $priceListPublicId = null,
    ): array {
        $priceListId = null;
        if ($priceListPublicId !== null && $priceListPublicId !== '') {
            $priceListId = $this->requirePriceListId($priceListPublicId);
        }

        if ($variantPublicId !== null && $variantPublicId !== '') {
            $variantId = $this->requireVariantId($variantPublicId);
            $rows = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), vp.[PublicId]) AS [PublicId],
    N'variant' AS [RecordType],
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantName],
    c.[CurrencyCode],
    CONVERT(NVARCHAR(50), vp.[UnitPrice]) AS [BaseCost],
    pl.[PriceListCode],
    vp.[ErpSourceReference],
    CONVERT(VARCHAR(33), vp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), vp.[EffectiveToUtc], 127) AS [EffectiveToUtc],
    vp.[IsActive],
    vp.[RowVersion],
    COALESCE(pu_price.[UnitCode], pu_variant.[UnitCode], pu_product.[UnitCode], N'M') AS [UnitOfMeasureCode],
    COALESCE(pu_price.[Symbol], pu_variant.[Symbol], pu_product.[Symbol], N'm') AS [UnitOfMeasureSymbol]
FROM [product].[ProductVariantPrices] vp
INNER JOIN [product].[ProductVariants] v ON v.[ProductVariantId] = vp.[ProductVariantId]
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = vp.[CurrencyId]
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = vp.[PriceListId]
LEFT JOIN [product].[SpecificationUnits] pu_price ON pu_price.[SpecificationUnitId] = vp.[SpecificationUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_variant ON pu_variant.[SpecificationUnitId] = v.[PricingUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_product ON pu_product.[SpecificationUnitId] = p.[PricingUnitId]
WHERE p.[PublicId] = :productPublicId
  AND vp.[ProductVariantId] = :variantId
  AND vp.[DeletedAtUtc] IS NULL
  AND (:priceListId IS NULL OR vp.[PriceListId] = :priceListId)
ORDER BY COALESCE(vp.[EffectiveFromUtc], vp.[CreatedAtUtc]) DESC, vp.[ProductVariantPriceId] DESC
SQL,
                [
                    ':productPublicId' => $productPublicId->toString(),
                    ':variantId' => $variantId,
                    ':priceListId' => $priceListId,
                ],
            );
        } else {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), pp.[PublicId]) AS [PublicId],
    N'product' AS [RecordType],
    NULL AS [VariantPublicId],
    NULL AS [VariantName],
    c.[CurrencyCode],
    CONVERT(NVARCHAR(50), pp.[UnitPrice]) AS [BaseCost],
    pl.[PriceListCode],
    pp.[ErpSourceReference],
    CONVERT(VARCHAR(33), pp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), pp.[EffectiveToUtc], 127) AS [EffectiveToUtc],
    pp.[IsActive],
    pp.[RowVersion],
    COALESCE(pu_price.[UnitCode], pu_product.[UnitCode], N'M') AS [UnitOfMeasureCode],
    COALESCE(pu_price.[Symbol], pu_product.[Symbol], N'm') AS [UnitOfMeasureSymbol]
FROM [product].[ProductPrices] pp
INNER JOIN [product].[Products] p ON p.[ProductId] = pp.[ProductId]
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = pp.[CurrencyId]
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = pp.[PriceListId]
LEFT JOIN [product].[SpecificationUnits] pu_price ON pu_price.[SpecificationUnitId] = pp.[SpecificationUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_product ON pu_product.[SpecificationUnitId] = p.[PricingUnitId]
WHERE p.[PublicId] = :productPublicId
  AND pp.[DeletedAtUtc] IS NULL
  AND (:priceListId IS NULL OR pp.[PriceListId] = :priceListId)
ORDER BY COALESCE(pp.[EffectiveFromUtc], pp.[CreatedAtUtc]) DESC, pp.[ProductPriceId] DESC
SQL,
                [
                    ':productPublicId' => $productPublicId->toString(),
                    ':priceListId' => $priceListId,
                ],
            );
        }

        return array_map(function (array $row): ProductBaseCostHistoryEntry {
            $isActive = AzureSqlValueMapper::intValue($row['IsActive'] ?? 0) === 1;
            $effectiveFrom = AzureSqlValueMapper::nullableString($row['EffectiveFromUtc'] ?? null);
            $effectiveTo = AzureSqlValueMapper::nullableString($row['EffectiveToUtc'] ?? null);

            return new ProductBaseCostHistoryEntry(
                publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                recordType: AzureSqlValueMapper::stringValue($row['RecordType'] ?? 'product'),
                variantPublicId: AzureSqlValueMapper::nullableString($row['VariantPublicId'] ?? null),
                variantName: AzureSqlValueMapper::nullableString($row['VariantName'] ?? null),
                currencyCode: AzureSqlValueMapper::stringValue($row['CurrencyCode'] ?? ''),
                baseCost: ProductDecimalMapper::requireString($row['BaseCost'] ?? null, 'BaseCost'),
                unitOfMeasureCode: AzureSqlValueMapper::nullableString($row['UnitOfMeasureCode'] ?? null) ?? PricingUnitDefaults::CODE,
                priceListCode: AzureSqlValueMapper::nullableString($row['PriceListCode'] ?? null),
                priceSourceCode: PricingSourceCodec::decode(AzureSqlValueMapper::nullableString($row['ErpSourceReference'] ?? null)),
                effectiveFromUtc: $effectiveFrom,
                effectiveToUtc: $effectiveTo,
                status: PricingRecordStatus::resolve($isActive, $effectiveFrom, $effectiveTo),
                isActive: $isActive,
                rowVersion: $this->rowVersionBase64($row['RowVersion'] ?? null),
            );
        }, $rows);
    }

    public function findByPublicId(string $publicId): ?ProductPricingSummary
    {
        $product = $this->findProductPriceRowByPublicId($publicId);
        if ($product !== null) {
            return $this->hydrateSummary($product);
        }

        $variant = $this->findVariantPriceRowByPublicId($publicId);

        return $variant === null ? null : $this->hydrateSummary($variant);
    }

    public function upsert(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductPricingSummary {
        return $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $data, $actorUserId): ProductPricingSummary {
                $baseCost = $data['baseCost'] ?? $data['unitPrice'] ?? '';
                $data['baseCost'] = $baseCost;

                if (($data['pricingPublicId'] ?? null) !== null) {
                    return $this->updateExisting($data, $actorUserId);
                }

                if (($data['variantPublicId'] ?? null) !== null) {
                    $this->assertVariantBelongsToProduct($data['variantPublicId'], $productPublicId);

                    return $this->insertVariantPrice($productPublicId, $data, $actorUserId);
                }

                return $this->insertProductPrice($productPublicId, $data, $actorUserId);
            },
        );
    }

    public function setActive(
        string $pricingPublicId,
        bool $isActive,
        UserId $actorUserId,
        ?string $expectedRowVersion = null,
    ): ProductPricingSummary {
        return $this->runInTransaction(
            $this->connection,
            function () use ($pricingPublicId, $isActive, $actorUserId, $expectedRowVersion): ProductPricingSummary {
                $productRow = $this->findProductPriceRowByPublicId($pricingPublicId);
                if ($productRow !== null) {
                    $this->updateActiveFlag(
                        table: 'product.ProductPrices',
                        idColumn: 'ProductPriceId',
                        idValue: AzureSqlValueMapper::intValue($productRow['ProductPriceId'] ?? 0),
                        isActive: $isActive,
                        actorUserId: $actorUserId,
                        expectedRowVersion: $expectedRowVersion,
                        pricingPublicId: $pricingPublicId,
                        rowVersionBinary: $productRow['RowVersion'] ?? null,
                    );

                    return $this->hydrateSummary($this->findProductPriceRowByPublicId($pricingPublicId) ?? $productRow);
                }

                $variantRow = $this->findVariantPriceRowByPublicId($pricingPublicId);
                if ($variantRow === null) {
                    throw ProductPersistenceException::referenceNotFound('Pricing record', $pricingPublicId);
                }

                $this->updateActiveFlag(
                    table: 'product.ProductVariantPrices',
                    idColumn: 'ProductVariantPriceId',
                    idValue: AzureSqlValueMapper::intValue($variantRow['ProductVariantPriceId'] ?? 0),
                    isActive: $isActive,
                    actorUserId: $actorUserId,
                    expectedRowVersion: $expectedRowVersion,
                    pricingPublicId: $pricingPublicId,
                    rowVersionBinary: $variantRow['RowVersion'] ?? null,
                );

                return $this->hydrateSummary($this->findVariantPriceRowByPublicId($pricingPublicId) ?? $variantRow);
            },
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchProductPriceRows(ProductPublicId $productPublicId): array
    {
        return $this->connection->fetchAll(
            <<<'SQL'
SELECT
    pp.[ProductPriceId],
    CONVERT(NVARCHAR(36), pp.[PublicId]) AS [PublicId],
    N'product' AS [RecordType],
    NULL AS [VariantPublicId],
    NULL AS [VariantName],
    NULL AS [VariantCode],
    c.[CurrencyCode],
    CONVERT(NVARCHAR(50), pp.[UnitPrice]) AS [BaseCost],
    CONVERT(NVARCHAR(50), pp.[MinimumQuantity]) AS [MinimumQuantity],
    pl.[PriceListCode],
    CONVERT(NVARCHAR(36), pl.[PublicId]) AS [PriceListPublicId],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [RegionPublicId],
    CONVERT(NVARCHAR(36), m.[PublicId]) AS [MarketPublicId],
    pp.[ErpSourceReference],
    CONVERT(VARCHAR(33), pp.[LastErpSyncedAtUtc], 127) AS [LastSourceUpdatedAtUtc],
    CONVERT(VARCHAR(33), pp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), pp.[EffectiveToUtc], 127) AS [EffectiveToUtc],
    pp.[IsActive],
    pp.[RowVersion],
    NULL AS [LegacyItemCode],
    COALESCE(pu_price.[UnitCode], pu_product.[UnitCode], N'M') AS [UnitOfMeasureCode],
    COALESCE(pu_price.[Symbol], pu_product.[Symbol], N'm') AS [UnitOfMeasureSymbol],
    CONVERT(NVARCHAR(36), COALESCE(pu_price.[PublicId], pu_product.[PublicId])) AS [UnitOfMeasurePublicId]
FROM [product].[ProductPrices] pp
INNER JOIN [product].[Products] p ON p.[ProductId] = pp.[ProductId]
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = pp.[CurrencyId]
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = pp.[PriceListId]
LEFT JOIN [product].[Regions] r ON r.[RegionId] = pp.[RegionId]
LEFT JOIN [product].[Markets] m ON m.[MarketId] = pp.[MarketId]
LEFT JOIN [product].[SpecificationUnits] pu_price ON pu_price.[SpecificationUnitId] = pp.[SpecificationUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_product ON pu_product.[SpecificationUnitId] = p.[PricingUnitId]
WHERE p.[PublicId] = :productPublicId
  AND pp.[DeletedAtUtc] IS NULL
ORDER BY COALESCE(pp.[EffectiveFromUtc], pp.[CreatedAtUtc]) DESC
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchVariantPriceRows(ProductPublicId $productPublicId): array
    {
        return $this->connection->fetchAll(
            <<<'SQL'
SELECT
    vp.[ProductVariantPriceId],
    CONVERT(NVARCHAR(36), vp.[PublicId]) AS [PublicId],
    N'variant' AS [RecordType],
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantName],
    v.[VariantCode],
    c.[CurrencyCode],
    CONVERT(NVARCHAR(50), vp.[UnitPrice]) AS [BaseCost],
    CONVERT(NVARCHAR(50), vp.[MinimumQuantity]) AS [MinimumQuantity],
    pl.[PriceListCode],
    CONVERT(NVARCHAR(36), pl.[PublicId]) AS [PriceListPublicId],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [RegionPublicId],
    CONVERT(NVARCHAR(36), m.[PublicId]) AS [MarketPublicId],
    vp.[ErpSourceReference],
    CONVERT(VARCHAR(33), vp.[LastErpSyncedAtUtc], 127) AS [LastSourceUpdatedAtUtc],
    CONVERT(VARCHAR(33), vp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), vp.[EffectiveToUtc], 127) AS [EffectiveToUtc],
    vp.[IsActive],
    vp.[RowVersion],
    lm.[SourceProductKey] AS [LegacyItemCode],
    COALESCE(pu_price.[UnitCode], pu_variant.[UnitCode], pu_product.[UnitCode], N'M') AS [UnitOfMeasureCode],
    COALESCE(pu_price.[Symbol], pu_variant.[Symbol], pu_product.[Symbol], N'm') AS [UnitOfMeasureSymbol],
    CONVERT(NVARCHAR(36), COALESCE(pu_price.[PublicId], pu_variant.[PublicId], pu_product.[PublicId])) AS [UnitOfMeasurePublicId]
FROM [product].[ProductVariantPrices] vp
INNER JOIN [product].[ProductVariants] v ON v.[ProductVariantId] = vp.[ProductVariantId]
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = vp.[CurrencyId]
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = vp.[PriceListId]
LEFT JOIN [product].[Regions] r ON r.[RegionId] = vp.[RegionId]
LEFT JOIN [product].[Markets] m ON m.[MarketId] = vp.[MarketId]
LEFT JOIN [product].[SpecificationUnits] pu_price ON pu_price.[SpecificationUnitId] = vp.[SpecificationUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_variant ON pu_variant.[SpecificationUnitId] = v.[PricingUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_product ON pu_product.[SpecificationUnitId] = p.[PricingUnitId]
LEFT JOIN [migration].[LegacyProductMappings] lm
    ON lm.[ProductVariantId] = v.[ProductVariantId]
   AND lm.[SourceSystemCode] = N'tc'
WHERE p.[PublicId] = :productPublicId
  AND vp.[DeletedAtUtc] IS NULL
ORDER BY v.[DisplayOrder], COALESCE(vp.[EffectiveFromUtc], vp.[CreatedAtUtc]) DESC
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertProductPrice(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductPricingSummary {
        $productId = $this->requireProductId($productPublicId);
        $context = $this->resolvePriceContext($data);
        $this->assertNoOverlap('product', $productId, null, $context, $data);

        if (($data['createRevision'] ?? false) === true) {
            $this->closeCurrentRecords('product', $productId, null, $context, $data['effectiveFromUtc'] ?? null, $actorUserId);
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[ProductPrices] (
    [ProductId], [PriceListId], [CurrencyId], [RegionId], [MarketId],
    [MinimumQuantity], [UnitPrice], [DiscountPercent], [TaxInclusive],
    [EffectiveFromUtc], [EffectiveToUtc], [ErpSourceReference], [SpecificationUnitId],
    [CreatedByUserId], [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :productId, :priceListId, :currencyId, :regionId, :marketId,
    :minimumQuantity, :baseCost, :discountPercent, :taxInclusive,
    :effectiveFromUtc, :effectiveToUtc, :erpSourceReference, :specificationUnitId,
    :createdByUserId, :updatedByUserId
)
SQL,
            $this->bindInsertParams($context, $data, $actorUserId, productId: $productId),
        );

        $loaded = $this->findProductPriceRowByPublicId(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
        if ($loaded === null) {
            throw ProductPersistenceException::insertFailed('ProductPrice');
        }

        return $this->hydrateSummary($loaded);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertVariantPrice(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductPricingSummary {
        unset($productPublicId);
        $variantId = $this->requireVariantId((string) $data['variantPublicId']);
        $context = $this->resolvePriceContext($data);
        $this->assertNoOverlap('variant', null, $variantId, $context, $data);

        if (($data['createRevision'] ?? false) === true) {
            $this->closeCurrentRecords('variant', null, $variantId, $context, $data['effectiveFromUtc'] ?? null, $actorUserId);
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[ProductVariantPrices] (
    [ProductVariantId], [PriceListId], [CurrencyId], [RegionId], [MarketId],
    [MinimumQuantity], [UnitPrice], [DiscountPercent], [TaxInclusive],
    [EffectiveFromUtc], [EffectiveToUtc], [ErpSourceReference], [SpecificationUnitId],
    [CreatedByUserId], [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :variantId, :priceListId, :currencyId, :regionId, :marketId,
    :minimumQuantity, :baseCost, :discountPercent, :taxInclusive,
    :effectiveFromUtc, :effectiveToUtc, :erpSourceReference, :specificationUnitId,
    :createdByUserId, :updatedByUserId
)
SQL,
            $this->bindInsertParams($context, $data, $actorUserId, variantId: $variantId),
        );

        $loaded = $this->findVariantPriceRowByPublicId(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
        if ($loaded === null) {
            throw ProductPersistenceException::insertFailed('ProductVariantPrice');
        }

        return $this->hydrateSummary($loaded);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateExisting(array $data, UserId $actorUserId): ProductPricingSummary
    {
        $publicId = (string) $data['pricingPublicId'];
        $expected = ($data['expectedRowVersion'] ?? null) !== null
            ? RowVersionToken::fromBase64((string) $data['expectedRowVersion'])
            : null;

        $productRow = $this->findProductPriceRowByPublicId($publicId);
        if ($productRow !== null) {
            $this->executePriceUpdate(
                table: '[product].[ProductPrices]',
                idColumn: 'ProductPriceId',
                idValue: AzureSqlValueMapper::intValue($productRow['ProductPriceId'] ?? 0),
                data: $data,
                actorUserId: $actorUserId,
                expected: $expected,
                publicId: $publicId,
            );

            $loaded = $this->findProductPriceRowByPublicId($publicId);
            if ($loaded === null) {
                throw ProductPersistenceException::insertFailed('ProductPrice');
            }

            return $this->hydrateSummary($loaded);
        }

        $variantRow = $this->findVariantPriceRowByPublicId($publicId);
        if ($variantRow === null) {
            throw ProductPersistenceException::referenceNotFound('Pricing record', $publicId);
        }

        $this->executePriceUpdate(
            table: '[product].[ProductVariantPrices]',
            idColumn: 'ProductVariantPriceId',
            idValue: AzureSqlValueMapper::intValue($variantRow['ProductVariantPriceId'] ?? 0),
            data: $data,
            actorUserId: $actorUserId,
            expected: $expected,
            publicId: $publicId,
        );

        $loaded = $this->findVariantPriceRowByPublicId($publicId);
        if ($loaded === null) {
            throw ProductPersistenceException::insertFailed('ProductVariantPrice');
        }

        return $this->hydrateSummary($loaded);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function executePriceUpdate(
        string $table,
        string $idColumn,
        int $idValue,
        array $data,
        UserId $actorUserId,
        ?RowVersionToken $expected,
        string $publicId,
    ): void {
        $params = [
            ':id' => $idValue,
            ':baseCost' => $data['baseCost'],
            ':discountPercent' => $data['discountPercent'] ?? null,
            ':taxInclusive' => ($data['taxInclusive'] ?? false) ? 1 : 0,
            ':effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
            ':effectiveToUtc' => $data['effectiveToUtc'] ?? null,
            ':erpSourceReference' => PricingSourceCodec::encode($data['priceSourceCode'] ?? null),
            ':updatedByUserId' => $actorUserId->toInt(),
        ];

        $unitClause = '';
        if (array_key_exists('unitOfMeasurePublicId', $data)) {
            $unitClause = ', [SpecificationUnitId] = :specificationUnitId';
            $params[':specificationUnitId'] = $this->resolveOptionalSpecificationUnitId($data['unitOfMeasurePublicId']);
        }

        $versionClause = '';
        if ($expected !== null) {
            $versionClause = ' AND [RowVersion] = :expectedRowVersion';
            $params[':expectedRowVersion'] = new SqlBinary($expected->toBinary());
        }

        $rows = $this->connection->fetchAll(
            <<<SQL
UPDATE {$table}
SET
    [UnitPrice] = :baseCost,
    [DiscountPercent] = :discountPercent,
    [TaxInclusive] = :taxInclusive,
    [EffectiveFromUtc] = :effectiveFromUtc,
    [EffectiveToUtc] = :effectiveToUtc,
    [ErpSourceReference] = :erpSourceReference{$unitClause},
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT INSERTED.[PublicId]
WHERE [{$idColumn}] = :id{$versionClause}
SQL,
            $params,
        );

        if ($rows === []) {
            throw ProductPricingConcurrencyConflict::forPublicId($publicId);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, scalar|SqlBinary|null>
     */
    private function bindInsertParams(
        array $context,
        array $data,
        UserId $actorUserId,
        ?int $productId = null,
        ?int $variantId = null,
    ): array {
        $params = [
            ':priceListId' => $context['priceListId'],
            ':currencyId' => $context['currencyId'],
            ':regionId' => $context['regionId'],
            ':marketId' => $context['marketId'],
            ':minimumQuantity' => $data['minimumQuantity'],
            ':baseCost' => $data['baseCost'],
            ':discountPercent' => $data['discountPercent'] ?? null,
            ':taxInclusive' => ($data['taxInclusive'] ?? false) ? 1 : 0,
            ':effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
            ':effectiveToUtc' => $data['effectiveToUtc'] ?? null,
            ':erpSourceReference' => PricingSourceCodec::encode($data['priceSourceCode'] ?? 'manual'),
            ':specificationUnitId' => $this->resolveOptionalSpecificationUnitId($data['unitOfMeasurePublicId'] ?? null),
            ':createdByUserId' => $actorUserId->toInt(),
            ':updatedByUserId' => $actorUserId->toInt(),
        ];
        if ($productId !== null) {
            $params[':productId'] = $productId;
        }
        if ($variantId !== null) {
            $params[':variantId'] = $variantId;
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{priceListId: ?int, currencyId: int, regionId: ?int, marketId: ?int}
     */
    private function resolvePriceContext(array $data): array
    {
        $priceListId = null;
        $priceListCurrencyCode = null;
        if (($data['priceListPublicId'] ?? null) !== null) {
            $priceListId = $this->requirePriceListId($data['priceListPublicId']);
            $priceListCurrencyCode = $this->requirePriceListCurrencyCode($data['priceListPublicId']);
        }

        $currencyCode = (string) ($data['currencyCode'] ?? '');
        if ($priceListCurrencyCode !== null && strtoupper($currencyCode) !== strtoupper($priceListCurrencyCode)) {
            throw \TriCab\Core\Product\Application\Exception\InvalidPriceListCurrency::mismatch(
                $priceListCurrencyCode,
                $currencyCode,
            );
        }

        $currencyId = $this->requireCurrencyId($currencyCode !== '' ? $currencyCode : $priceListCurrencyCode);
        $regionId = null;
        if (($data['regionPublicId'] ?? null) !== null) {
            $regionId = $this->requireRegionId($data['regionPublicId']);
        }
        $marketId = null;
        if (($data['marketPublicId'] ?? null) !== null) {
            $marketId = $this->requireMarketId($data['marketPublicId']);
        }

        return compact('priceListId', 'currencyId', 'regionId', 'marketId');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertNoOverlap(
        string $ownerType,
        ?int $productId,
        ?int $variantId,
        array $context,
        array $data,
    ): void {
        if (($data['createRevision'] ?? false) === true) {
            return;
        }

        $sql = $ownerType === 'product'
            ? <<<'SQL'
SELECT TOP (1) 1 AS [Hit]
FROM [product].[ProductPrices]
WHERE [ProductId] = :ownerId
  AND [CurrencyId] = :currencyId
  AND ([PriceListId] = :priceListId OR ([PriceListId] IS NULL AND :priceListIsNull = 1))
  AND ([RegionId] = :regionId OR ([RegionId] IS NULL AND :regionIsNull = 1))
  AND ([MarketId] = :marketId OR ([MarketId] IS NULL AND :marketIsNull = 1))
  AND [MinimumQuantity] = :minimumQuantity
  AND [DeletedAtUtc] IS NULL
  AND [IsActive] = 1
SQL
            : <<<'SQL'
SELECT TOP (1) 1 AS [Hit]
FROM [product].[ProductVariantPrices]
WHERE [ProductVariantId] = :ownerId
  AND [CurrencyId] = :currencyId
  AND ([PriceListId] = :priceListId OR ([PriceListId] IS NULL AND :priceListIsNull = 1))
  AND ([RegionId] = :regionId OR ([RegionId] IS NULL AND :regionIsNull = 1))
  AND ([MarketId] = :marketId OR ([MarketId] IS NULL AND :marketIsNull = 1))
  AND [MinimumQuantity] = :minimumQuantity
  AND [DeletedAtUtc] IS NULL
  AND [IsActive] = 1
SQL;

        $ownerId = $ownerType === 'product' ? $productId : $variantId;
        $rows = $this->connection->fetchAll(
            $sql,
            [
                ':ownerId' => $ownerId,
                ':currencyId' => $context['currencyId'],
                ':priceListId' => $context['priceListId'] ?? 0,
                ':priceListIsNull' => $context['priceListId'] === null ? 1 : 0,
                ':regionId' => $context['regionId'] ?? 0,
                ':regionIsNull' => $context['regionId'] === null ? 1 : 0,
                ':marketId' => $context['marketId'] ?? 0,
                ':marketIsNull' => $context['marketId'] === null ? 1 : 0,
                ':minimumQuantity' => $data['minimumQuantity'],
            ],
        );

        if ($rows !== []) {
            throw OverlappingBaseCostRecord::forContext($ownerType === 'product' ? 'this product' : 'this variant');
        }
    }

    /**
     * @param array{priceListId: ?int, currencyId: int, regionId: ?int, marketId: ?int} $context
     */
    private function closeCurrentRecords(
        string $ownerType,
        ?int $productId,
        ?int $variantId,
        array $context,
        ?string $effectiveFromUtc,
        UserId $actorUserId,
    ): void {
        $table = $ownerType === 'product' ? '[product].[ProductPrices]' : '[product].[ProductVariantPrices]';
        $ownerColumn = $ownerType === 'product' ? 'ProductId' : 'ProductVariantId';
        $ownerValue = $ownerType === 'product' ? $productId : $variantId;

        $this->connection->execute(
            <<<SQL
UPDATE {$table}
SET [EffectiveToUtc] = COALESCE(:effectiveToUtc, DATEADD(second, -1, SYSUTCDATETIME())),
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [{$ownerColumn}] = :ownerId
  AND [CurrencyId] = :currencyId
  AND ([PriceListId] = :priceListId OR ([PriceListId] IS NULL AND :priceListIsNull = 1))
  AND ([RegionId] = :regionId OR ([RegionId] IS NULL AND :regionIsNull = 1))
  AND ([MarketId] = :marketId OR ([MarketId] IS NULL AND :marketIsNull = 1))
  AND [DeletedAtUtc] IS NULL
  AND [IsActive] = 1
  AND ([EffectiveToUtc] IS NULL OR [EffectiveToUtc] >= SYSUTCDATETIME())
SQL,
            [
                ':ownerId' => $ownerValue,
                ':currencyId' => $context['currencyId'],
                ':priceListId' => $context['priceListId'] ?? 0,
                ':priceListIsNull' => $context['priceListId'] === null ? 1 : 0,
                ':regionId' => $context['regionId'] ?? 0,
                ':regionIsNull' => $context['regionId'] === null ? 1 : 0,
                ':marketId' => $context['marketId'] ?? 0,
                ':marketIsNull' => $context['marketId'] === null ? 1 : 0,
                ':effectiveToUtc' => $effectiveFromUtc,
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
    }

    private function updateActiveFlag(
        string $table,
        string $idColumn,
        int $idValue,
        bool $isActive,
        UserId $actorUserId,
        ?string $expectedRowVersion,
        string $pricingPublicId,
        mixed $rowVersionBinary,
    ): void {
        $expected = $expectedRowVersion !== null ? RowVersionToken::fromBase64($expectedRowVersion) : null;
        $params = [
            ':id' => $idValue,
            ':isActive' => $isActive ? 1 : 0,
            ':updatedByUserId' => $actorUserId->toInt(),
        ];
        $versionClause = '';
        if ($expected !== null) {
            $versionClause = ' AND [RowVersion] = :expectedRowVersion';
            $params[':expectedRowVersion'] = new SqlBinary($expected->toBinary());
        }

        $qualified = $table === 'product.ProductPrices' ? '[product].[ProductPrices]' : '[product].[ProductVariantPrices]';
        $rows = $this->connection->fetchAll(
            <<<SQL
UPDATE {$qualified}
SET [IsActive] = :isActive,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT INSERTED.[PublicId]
WHERE [{$idColumn}] = :id{$versionClause}
SQL,
            $params,
        );

        if ($rows === [] && $expected !== null) {
            throw ProductPricingConcurrencyConflict::forPublicId($pricingPublicId);
        }

        unset($rowVersionBinary);
    }

    private function assertVariantBelongsToProduct(string $variantPublicId, ProductPublicId $productPublicId): void
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP (1) 1 AS [Hit]
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
WHERE v.[PublicId] = :variantPublicId
  AND p.[PublicId] = :productPublicId
  AND v.[DeletedAtUtc] IS NULL
SQL,
            [
                ':variantPublicId' => $variantPublicId,
                ':productPublicId' => $productPublicId->toString(),
            ],
        );
        if ($rows === []) {
            throw ProductPersistenceException::variantNotFound($variantPublicId);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findProductPriceRowByPublicId(string $publicId): ?array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    pp.[ProductPriceId],
    CONVERT(NVARCHAR(36), pp.[PublicId]) AS [PublicId],
    N'product' AS [RecordType],
    NULL AS [VariantPublicId],
    NULL AS [VariantName],
    NULL AS [VariantCode],
    c.[CurrencyCode],
    CONVERT(NVARCHAR(50), pp.[UnitPrice]) AS [BaseCost],
    CONVERT(NVARCHAR(50), pp.[MinimumQuantity]) AS [MinimumQuantity],
    pl.[PriceListCode],
    CONVERT(NVARCHAR(36), pl.[PublicId]) AS [PriceListPublicId],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [RegionPublicId],
    CONVERT(NVARCHAR(36), m.[PublicId]) AS [MarketPublicId],
    pp.[ErpSourceReference],
    CONVERT(VARCHAR(33), pp.[LastErpSyncedAtUtc], 127) AS [LastSourceUpdatedAtUtc],
    CONVERT(VARCHAR(33), pp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), pp.[EffectiveToUtc], 127) AS [EffectiveToUtc],
    pp.[IsActive],
    pp.[RowVersion],
    NULL AS [LegacyItemCode],
    COALESCE(pu_price.[UnitCode], pu_product.[UnitCode], N'M') AS [UnitOfMeasureCode],
    COALESCE(pu_price.[Symbol], pu_product.[Symbol], N'm') AS [UnitOfMeasureSymbol],
    CONVERT(NVARCHAR(36), COALESCE(pu_price.[PublicId], pu_product.[PublicId])) AS [UnitOfMeasurePublicId]
FROM [product].[ProductPrices] pp
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = pp.[CurrencyId]
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = pp.[PriceListId]
LEFT JOIN [product].[Regions] r ON r.[RegionId] = pp.[RegionId]
LEFT JOIN [product].[Markets] m ON m.[MarketId] = pp.[MarketId]
INNER JOIN [product].[Products] p ON p.[ProductId] = pp.[ProductId]
LEFT JOIN [product].[SpecificationUnits] pu_price ON pu_price.[SpecificationUnitId] = pp.[SpecificationUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_product ON pu_product.[SpecificationUnitId] = p.[PricingUnitId]
WHERE pp.[PublicId] = :publicId
  AND pp.[DeletedAtUtc] IS NULL
SQL,
            [':publicId' => $publicId],
        );

        return $rows === [] ? null : $rows[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findVariantPriceRowByPublicId(string $publicId): ?array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    vp.[ProductVariantPriceId],
    CONVERT(NVARCHAR(36), vp.[PublicId]) AS [PublicId],
    N'variant' AS [RecordType],
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantName],
    v.[VariantCode],
    c.[CurrencyCode],
    CONVERT(NVARCHAR(50), vp.[UnitPrice]) AS [BaseCost],
    CONVERT(NVARCHAR(50), vp.[MinimumQuantity]) AS [MinimumQuantity],
    pl.[PriceListCode],
    CONVERT(NVARCHAR(36), pl.[PublicId]) AS [PriceListPublicId],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [RegionPublicId],
    CONVERT(NVARCHAR(36), m.[PublicId]) AS [MarketPublicId],
    vp.[ErpSourceReference],
    CONVERT(VARCHAR(33), vp.[LastErpSyncedAtUtc], 127) AS [LastSourceUpdatedAtUtc],
    CONVERT(VARCHAR(33), vp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), vp.[EffectiveToUtc], 127) AS [EffectiveToUtc],
    vp.[IsActive],
    vp.[RowVersion],
    lm.[SourceProductKey] AS [LegacyItemCode],
    COALESCE(pu_price.[UnitCode], pu_variant.[UnitCode], pu_product.[UnitCode], N'M') AS [UnitOfMeasureCode],
    COALESCE(pu_price.[Symbol], pu_variant.[Symbol], pu_product.[Symbol], N'm') AS [UnitOfMeasureSymbol],
    CONVERT(NVARCHAR(36), COALESCE(pu_price.[PublicId], pu_variant.[PublicId], pu_product.[PublicId])) AS [UnitOfMeasurePublicId]
FROM [product].[ProductVariantPrices] vp
INNER JOIN [product].[ProductVariants] v ON v.[ProductVariantId] = vp.[ProductVariantId]
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = vp.[CurrencyId]
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = vp.[PriceListId]
LEFT JOIN [product].[Regions] r ON r.[RegionId] = vp.[RegionId]
LEFT JOIN [product].[Markets] m ON m.[MarketId] = vp.[MarketId]
LEFT JOIN [product].[SpecificationUnits] pu_price ON pu_price.[SpecificationUnitId] = vp.[SpecificationUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_variant ON pu_variant.[SpecificationUnitId] = v.[PricingUnitId]
LEFT JOIN [product].[SpecificationUnits] pu_product ON pu_product.[SpecificationUnitId] = p.[PricingUnitId]
LEFT JOIN [migration].[LegacyProductMappings] lm
    ON lm.[ProductVariantId] = v.[ProductVariantId]
   AND lm.[SourceSystemCode] = N'tc'
WHERE vp.[PublicId] = :publicId
  AND vp.[DeletedAtUtc] IS NULL
SQL,
            [':publicId' => $publicId],
        );

        return $rows === [] ? null : $rows[0];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSummary(array $row): ProductPricingSummary
    {
        $isActive = AzureSqlValueMapper::intValue($row['IsActive'] ?? 1) === 1;
        $effectiveFrom = AzureSqlValueMapper::nullableString($row['EffectiveFromUtc'] ?? null);
        $effectiveTo = AzureSqlValueMapper::nullableString($row['EffectiveToUtc'] ?? null);
        $erpRef = AzureSqlValueMapper::nullableString($row['ErpSourceReference'] ?? null);

        return new ProductPricingSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            recordType: AzureSqlValueMapper::stringValue($row['RecordType'] ?? 'product'),
            variantPublicId: AzureSqlValueMapper::nullableString($row['VariantPublicId'] ?? null),
            variantName: AzureSqlValueMapper::nullableString($row['VariantName'] ?? null),
            variantCode: AzureSqlValueMapper::nullableString($row['VariantCode'] ?? null),
            currencyCode: AzureSqlValueMapper::stringValue($row['CurrencyCode'] ?? ''),
            baseCost: ProductDecimalMapper::requireString($row['BaseCost'] ?? null, 'BaseCost'),
            minimumQuantity: ProductDecimalMapper::requireString($row['MinimumQuantity'] ?? null, 'MinimumQuantity'),
            priceListCode: AzureSqlValueMapper::nullableString($row['PriceListCode'] ?? null),
            priceListPublicId: AzureSqlValueMapper::nullableString($row['PriceListPublicId'] ?? null),
            regionPublicId: AzureSqlValueMapper::nullableString($row['RegionPublicId'] ?? null),
            marketPublicId: AzureSqlValueMapper::nullableString($row['MarketPublicId'] ?? null),
            unitOfMeasureCode: AzureSqlValueMapper::nullableString($row['UnitOfMeasureCode'] ?? null) ?? PricingUnitDefaults::CODE,
            unitOfMeasureSymbol: AzureSqlValueMapper::nullableString($row['UnitOfMeasureSymbol'] ?? null) ?? PricingUnitDefaults::SYMBOL,
            unitOfMeasurePublicId: AzureSqlValueMapper::nullableString($row['UnitOfMeasurePublicId'] ?? null),
            priceSourceCode: PricingSourceCodec::decode($erpRef),
            effectiveFromUtc: $effectiveFrom,
            effectiveToUtc: $effectiveTo,
            status: PricingRecordStatus::resolve($isActive, $effectiveFrom, $effectiveTo),
            isActive: $isActive,
            rowVersion: $this->rowVersionBase64($row['RowVersion'] ?? null),
            legacyItemCode: AzureSqlValueMapper::nullableString($row['LegacyItemCode'] ?? null),
            erpSourceReference: $erpRef,
            lastSourceUpdatedAtUtc: AzureSqlValueMapper::nullableString($row['LastSourceUpdatedAtUtc'] ?? null),
        );
    }

    private function rowVersionBase64(mixed $binary): ?string
    {
        if ($binary === null) {
            return null;
        }
        if ($binary instanceof SqlBinary) {
            return RowVersionToken::fromBinary($binary->bytes())->toBase64();
        }
        if (!is_string($binary) || $binary === '') {
            return null;
        }

        return RowVersionToken::fromBinary($binary)->toBase64();
    }

    private function requireProductId(ProductPublicId $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId->toString()],
        );
        if ($rows === []) {
            throw ProductPersistenceException::productNotFound($publicId->toString());
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductId'] ?? 0);
    }

    private function requireVariantId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductVariantId] FROM [product].[ProductVariants] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::variantNotFound($publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductVariantId'] ?? 0);
    }

    private function requireCurrencyId(string $code): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [CurrencyId] FROM [product].[Currencies] WHERE [CurrencyCode] = :code AND [DeletedAtUtc] IS NULL',
            [':code' => $code],
        );
        if ($rows === []) {
            throw ProductPersistenceException::lookupNotFound('Currency', $code);
        }

        return AzureSqlValueMapper::intValue($rows[0]['CurrencyId'] ?? 0);
    }

    private function requirePriceListId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [PriceListId] FROM [product].[PriceLists] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Price list', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['PriceListId'] ?? 0);
    }

    private function requirePriceListCurrencyCode(string $publicId): string
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT cur.[CurrencyCode] AS [CurrencyCode]
FROM [product].[PriceLists] pl
INNER JOIN [product].[Currencies] cur ON cur.[CurrencyId] = pl.[CurrencyId]
WHERE pl.[PublicId] = :publicId AND pl.[DeletedAtUtc] IS NULL
SQL,
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Price list', $publicId);
        }

        return AzureSqlValueMapper::stringValue($rows[0]['CurrencyCode'] ?? '');
    }

    private function requireRegionId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [RegionId] FROM [product].[Regions] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Region', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['RegionId'] ?? 0);
    }

    private function requireMarketId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [MarketId] FROM [product].[Markets] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Market', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['MarketId'] ?? 0);
    }

    private function resolveOptionalSpecificationUnitId(?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [SpecificationUnitId]
FROM [product].[SpecificationUnits]
WHERE [PublicId] = :publicId
  AND [DeletedAtUtc] IS NULL
  AND [IsActive] = 1
SQL,
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw InvalidPricingUnit::notFound($publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['SpecificationUnitId'] ?? 0);
    }

    /**
     * @return array{
     *   defaultUnitOfMeasurePublicId: ?string,
     *   defaultUnitOfMeasureCode: string,
     *   defaultUnitOfMeasureSymbol: string,
     *   defaultUnitSource: 'product'|'system-default'
     * }
     */
    private function loadProductDefaultUnitContext(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), su.[PublicId]) AS [PublicId],
    su.[UnitCode],
    su.[Symbol]
FROM [product].[Products] p
LEFT JOIN [product].[SpecificationUnits] su ON su.[SpecificationUnitId] = p.[PricingUnitId]
WHERE p.[PublicId] = :productPublicId
  AND p.[DeletedAtUtc] IS NULL
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );
        if ($rows === []) {
            throw ProductPersistenceException::productNotFound($productPublicId->toString());
        }

        $row = $rows[0];
        $publicId = AzureSqlValueMapper::nullableString($row['PublicId'] ?? null);
        if ($publicId === null) {
            return [
                'defaultUnitOfMeasurePublicId' => null,
                'defaultUnitOfMeasureCode' => PricingUnitDefaults::CODE,
                'defaultUnitOfMeasureSymbol' => PricingUnitDefaults::SYMBOL,
                'defaultUnitSource' => 'system-default',
            ];
        }

        return [
            'defaultUnitOfMeasurePublicId' => $publicId,
            'defaultUnitOfMeasureCode' => AzureSqlValueMapper::stringValue($row['UnitCode'] ?? PricingUnitDefaults::CODE),
            'defaultUnitOfMeasureSymbol' => AzureSqlValueMapper::stringValue($row['Symbol'] ?? PricingUnitDefaults::SYMBOL),
            'defaultUnitSource' => 'product',
        ];
    }
}
