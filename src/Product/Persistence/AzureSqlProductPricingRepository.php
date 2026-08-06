<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Restricted Azure SQL pricing repository (admin-only).
 * Tables: product.ProductPrices, product.ProductVariantPrices, product.Currencies, product.PriceLists.
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
        $productRows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), pp.[PublicId]) AS [PublicId],
    c.[CurrencyCode],
    CONVERT(NVARCHAR(50), pp.[UnitPrice]) AS [UnitPrice],
    CONVERT(NVARCHAR(50), pp.[MinimumQuantity]) AS [MinimumQuantity],
    pl.[PriceListCode],
    CONVERT(VARCHAR(33), pp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), pp.[EffectiveToUtc], 127) AS [EffectiveToUtc]
FROM [product].[ProductPrices] pp
INNER JOIN [product].[Products] p ON p.[ProductId] = pp.[ProductId]
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = pp.[CurrencyId]
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = pp.[PriceListId]
WHERE p.[PublicId] = :productPublicId
  AND pp.[DeletedAtUtc] IS NULL
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        $variantRows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), vp.[PublicId]) AS [PublicId],
    c.[CurrencyCode],
    CONVERT(NVARCHAR(50), vp.[UnitPrice]) AS [UnitPrice],
    CONVERT(NVARCHAR(50), vp.[MinimumQuantity]) AS [MinimumQuantity],
    pl.[PriceListCode],
    CONVERT(VARCHAR(33), vp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), vp.[EffectiveToUtc], 127) AS [EffectiveToUtc]
FROM [product].[ProductVariantPrices] vp
INNER JOIN [product].[ProductVariants] v ON v.[ProductVariantId] = vp.[ProductVariantId]
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = vp.[CurrencyId]
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = vp.[PriceListId]
WHERE p.[PublicId] = :productPublicId
  AND vp.[DeletedAtUtc] IS NULL
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        return array_map(
            fn (array $row): ProductPricingSummary => $this->hydrate($row),
            [...$productRows, ...$variantRows],
        );
    }

    public function upsert(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductPricingSummary {
        return $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $data, $actorUserId): ProductPricingSummary {
                $currencyId = $this->requireCurrencyId($data['currencyCode']);
                $priceListId = null;
                if (($data['priceListPublicId'] ?? null) !== null) {
                    $priceListId = $this->requirePriceListId($data['priceListPublicId']);
                }
                $regionId = null;
                if (($data['regionPublicId'] ?? null) !== null) {
                    $regionId = $this->requireRegionId($data['regionPublicId']);
                }
                $marketId = null;
                if (($data['marketPublicId'] ?? null) !== null) {
                    $marketId = $this->requireMarketId($data['marketPublicId']);
                }

                if (($data['variantPublicId'] ?? null) !== null) {
                    return $this->upsertVariantPrice(
                        $data['variantPublicId'],
                        $currencyId,
                        $priceListId,
                        $regionId,
                        $marketId,
                        $data,
                        $actorUserId,
                    );
                }

                $productId = $this->requireProductId($productPublicId);

                $existing = $this->connection->fetchAll(
                    <<<'SQL'
SELECT [ProductPriceId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId]
FROM [product].[ProductPrices]
WHERE [ProductId] = :productId
  AND [CurrencyId] = :currencyId
  AND ([PriceListId] = :priceListId OR ([PriceListId] IS NULL AND :priceListIsNull = 1))
  AND ([RegionId] = :regionId OR ([RegionId] IS NULL AND :regionIsNull = 1))
  AND ([MarketId] = :marketId OR ([MarketId] IS NULL AND :marketIsNull = 1))
  AND [MinimumQuantity] = :minimumQuantity
  AND [DeletedAtUtc] IS NULL
SQL,
                    [
                        ':productId' => $productId,
                        ':currencyId' => $currencyId,
                        ':priceListId' => $priceListId ?? 0,
                        ':priceListIsNull' => $priceListId === null ? 1 : 0,
                        ':regionId' => $regionId ?? 0,
                        ':regionIsNull' => $regionId === null ? 1 : 0,
                        ':marketId' => $marketId ?? 0,
                        ':marketIsNull' => $marketId === null ? 1 : 0,
                        ':minimumQuantity' => $data['minimumQuantity'],
                    ],
                );

                if ($existing === []) {
                    $rows = $this->connection->fetchAll(
                        <<<'SQL'
INSERT INTO [product].[ProductPrices] (
    [ProductId],
    [PriceListId],
    [CurrencyId],
    [RegionId],
    [MarketId],
    [MinimumQuantity],
    [UnitPrice],
    [DiscountPercent],
    [TaxInclusive],
    [EffectiveFromUtc],
    [EffectiveToUtc],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :productId,
    :priceListId,
    :currencyId,
    :regionId,
    :marketId,
    :minimumQuantity,
    :unitPrice,
    :discountPercent,
    :taxInclusive,
    :effectiveFromUtc,
    :effectiveToUtc,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                        $this->bindPriceParams($productId, $currencyId, $priceListId, $regionId, $marketId, $data, $actorUserId),
                    );
                } else {
                    $this->connection->execute(
                        <<<'SQL'
UPDATE [product].[ProductPrices]
SET
    [UnitPrice] = :unitPrice,
    [DiscountPercent] = :discountPercent,
    [TaxInclusive] = :taxInclusive,
    [EffectiveFromUtc] = :effectiveFromUtc,
    [EffectiveToUtc] = :effectiveToUtc,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [ProductPriceId] = :productPriceId
SQL,
                        [
                            ':productPriceId' => AzureSqlValueMapper::intValue($existing[0]['ProductPriceId'] ?? 0),
                            ':unitPrice' => $data['unitPrice'],
                            ':discountPercent' => $data['discountPercent'] ?? null,
                            ':taxInclusive' => ($data['taxInclusive'] ?? false) ? 1 : 0,
                            ':effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
                            ':effectiveToUtc' => $data['effectiveToUtc'] ?? null,
                            ':updatedByUserId' => $actorUserId->toInt(),
                        ],
                    );
                    $rows = [['PublicId' => $existing[0]['PublicId']]];
                }

                $loaded = $this->findProductPriceByPublicId(
                    AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''),
                );
                if ($loaded === null) {
                    throw ProductPersistenceException::insertFailed('ProductPrice');
                }

                return $loaded;
            },
        );
    }

    /**
     * @param array{
     *   currencyCode: string,
     *   unitPrice: string,
     *   minimumQuantity: string,
     *   priceListPublicId?: ?string,
     *   regionPublicId?: ?string,
     *   marketPublicId?: ?string,
     *   discountPercent?: ?string,
     *   taxInclusive?: bool,
     *   effectiveFromUtc?: ?string,
     *   effectiveToUtc?: ?string,
     *   variantPublicId?: ?string
     * } $data
     */
    private function upsertVariantPrice(
        string $variantPublicId,
        int $currencyId,
        ?int $priceListId,
        ?int $regionId,
        ?int $marketId,
        array $data,
        UserId $actorUserId,
    ): ProductPricingSummary {
        $variantId = $this->requireVariantId($variantPublicId);

        $existing = $this->connection->fetchAll(
            <<<'SQL'
SELECT [ProductVariantPriceId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId]
FROM [product].[ProductVariantPrices]
WHERE [ProductVariantId] = :variantId
  AND [CurrencyId] = :currencyId
  AND ([PriceListId] = :priceListId OR ([PriceListId] IS NULL AND :priceListIsNull = 1))
  AND ([RegionId] = :regionId OR ([RegionId] IS NULL AND :regionIsNull = 1))
  AND ([MarketId] = :marketId OR ([MarketId] IS NULL AND :marketIsNull = 1))
  AND [MinimumQuantity] = :minimumQuantity
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':variantId' => $variantId,
                ':currencyId' => $currencyId,
                ':priceListId' => $priceListId ?? 0,
                ':priceListIsNull' => $priceListId === null ? 1 : 0,
                ':regionId' => $regionId ?? 0,
                ':regionIsNull' => $regionId === null ? 1 : 0,
                ':marketId' => $marketId ?? 0,
                ':marketIsNull' => $marketId === null ? 1 : 0,
                ':minimumQuantity' => $data['minimumQuantity'],
            ],
        );

        if ($existing === []) {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
INSERT INTO [product].[ProductVariantPrices] (
    [ProductVariantId],
    [PriceListId],
    [CurrencyId],
    [RegionId],
    [MarketId],
    [MinimumQuantity],
    [UnitPrice],
    [DiscountPercent],
    [TaxInclusive],
    [EffectiveFromUtc],
    [EffectiveToUtc],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :variantId,
    :priceListId,
    :currencyId,
    :regionId,
    :marketId,
    :minimumQuantity,
    :unitPrice,
    :discountPercent,
    :taxInclusive,
    :effectiveFromUtc,
    :effectiveToUtc,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                [
                    ':variantId' => $variantId,
                    ':priceListId' => $priceListId,
                    ':currencyId' => $currencyId,
                    ':regionId' => $regionId,
                    ':marketId' => $marketId,
                    ':minimumQuantity' => $data['minimumQuantity'],
                    ':unitPrice' => $data['unitPrice'],
                    ':discountPercent' => $data['discountPercent'] ?? null,
                    ':taxInclusive' => ($data['taxInclusive'] ?? false) ? 1 : 0,
                    ':effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
                    ':effectiveToUtc' => $data['effectiveToUtc'] ?? null,
                    ':createdByUserId' => $actorUserId->toInt(),
                    ':updatedByUserId' => $actorUserId->toInt(),
                ],
            );
        } else {
            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[ProductVariantPrices]
SET
    [UnitPrice] = :unitPrice,
    [DiscountPercent] = :discountPercent,
    [TaxInclusive] = :taxInclusive,
    [EffectiveFromUtc] = :effectiveFromUtc,
    [EffectiveToUtc] = :effectiveToUtc,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [ProductVariantPriceId] = :productVariantPriceId
SQL,
                [
                    ':productVariantPriceId' => AzureSqlValueMapper::intValue($existing[0]['ProductVariantPriceId'] ?? 0),
                    ':unitPrice' => $data['unitPrice'],
                    ':discountPercent' => $data['discountPercent'] ?? null,
                    ':taxInclusive' => ($data['taxInclusive'] ?? false) ? 1 : 0,
                    ':effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
                    ':effectiveToUtc' => $data['effectiveToUtc'] ?? null,
                    ':updatedByUserId' => $actorUserId->toInt(),
                ],
            );
            $rows = [['PublicId' => $existing[0]['PublicId']]];
        }

        $loaded = $this->findVariantPriceByPublicId(
            AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''),
        );
        if ($loaded === null) {
            throw ProductPersistenceException::insertFailed('ProductVariantPrice');
        }

        return $loaded;
    }

    /**
     * @param array{
     *   currencyCode: string,
     *   unitPrice: string,
     *   minimumQuantity: string,
     *   priceListPublicId?: ?string,
     *   regionPublicId?: ?string,
     *   marketPublicId?: ?string,
     *   discountPercent?: ?string,
     *   taxInclusive?: bool,
     *   effectiveFromUtc?: ?string,
     *   effectiveToUtc?: ?string,
     *   variantPublicId?: ?string
     * } $data
     * @return array<string, scalar|SqlBinary|null>
     */
    private function bindPriceParams(
        int $productId,
        int $currencyId,
        ?int $priceListId,
        ?int $regionId,
        ?int $marketId,
        array $data,
        UserId $actorUserId,
    ): array {
        return [
            ':productId' => $productId,
            ':priceListId' => $priceListId,
            ':currencyId' => $currencyId,
            ':regionId' => $regionId,
            ':marketId' => $marketId,
            ':minimumQuantity' => $data['minimumQuantity'],
            ':unitPrice' => $data['unitPrice'],
            ':discountPercent' => $data['discountPercent'] ?? null,
            ':taxInclusive' => ($data['taxInclusive'] ?? false) ? 1 : 0,
            ':effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
            ':effectiveToUtc' => $data['effectiveToUtc'] ?? null,
            ':createdByUserId' => $actorUserId->toInt(),
            ':updatedByUserId' => $actorUserId->toInt(),
        ];
    }

    private function findProductPriceByPublicId(string $publicId): ?ProductPricingSummary
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), pp.[PublicId]) AS [PublicId],
    c.[CurrencyCode],
    CONVERT(NVARCHAR(50), pp.[UnitPrice]) AS [UnitPrice],
    CONVERT(NVARCHAR(50), pp.[MinimumQuantity]) AS [MinimumQuantity],
    pl.[PriceListCode],
    CONVERT(VARCHAR(33), pp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), pp.[EffectiveToUtc], 127) AS [EffectiveToUtc]
FROM [product].[ProductPrices] pp
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = pp.[CurrencyId]
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = pp.[PriceListId]
WHERE pp.[PublicId] = :publicId
  AND pp.[DeletedAtUtc] IS NULL
SQL,
            [':publicId' => $publicId],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    private function findVariantPriceByPublicId(string $publicId): ?ProductPricingSummary
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), vp.[PublicId]) AS [PublicId],
    c.[CurrencyCode],
    CONVERT(NVARCHAR(50), vp.[UnitPrice]) AS [UnitPrice],
    CONVERT(NVARCHAR(50), vp.[MinimumQuantity]) AS [MinimumQuantity],
    pl.[PriceListCode],
    CONVERT(VARCHAR(33), vp.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(VARCHAR(33), vp.[EffectiveToUtc], 127) AS [EffectiveToUtc]
FROM [product].[ProductVariantPrices] vp
INNER JOIN [product].[Currencies] c ON c.[CurrencyId] = vp.[CurrencyId]
LEFT JOIN [product].[PriceLists] pl ON pl.[PriceListId] = vp.[PriceListId]
WHERE vp.[PublicId] = :publicId
  AND vp.[DeletedAtUtc] IS NULL
SQL,
            [':publicId' => $publicId],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
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

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProductPricingSummary
    {
        return new ProductPricingSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            currencyCode: AzureSqlValueMapper::stringValue($row['CurrencyCode'] ?? ''),
            unitPrice: ProductDecimalMapper::requireString($row['UnitPrice'] ?? null, 'UnitPrice'),
            minimumQuantity: ProductDecimalMapper::requireString($row['MinimumQuantity'] ?? null, 'MinimumQuantity'),
            priceListCode: AzureSqlValueMapper::nullableString($row['PriceListCode'] ?? null),
            effectiveFromUtc: AzureSqlValueMapper::nullableString($row['EffectiveFromUtc'] ?? null),
            effectiveToUtc: AzureSqlValueMapper::nullableString($row['EffectiveToUtc'] ?? null),
        );
    }
}
