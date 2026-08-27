<?php

declare(strict_types=1);

namespace TriCab\Api\Commerce\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\ProductRowVersionMapper;
use TriCab\Core\Commerce\Contract\CartRepository;
use TriCab\Core\Commerce\Domain\Entity\Cart;
use TriCab\Core\Commerce\Domain\Entity\CartLine;
use TriCab\Core\Commerce\Domain\Exception\CartConcurrencyConflict;
use TriCab\Core\Commerce\Domain\Exception\CartNotFound;
use TriCab\Core\Commerce\Domain\ValueObject\CartId;
use TriCab\Core\Commerce\Domain\ValueObject\CartLineId;
use TriCab\Core\Commerce\Domain\ValueObject\CartLinePublicId;
use TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode;
use TriCab\Core\Commerce\Domain\ValueObject\CartPublicId;
use TriCab\Core\Commerce\Domain\ValueObject\CartStatus;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class AzureSqlCartRepository implements CartRepository
{
    use AzureSqlTransaction;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findOpenCartForActor(
        int $ownerUserId,
        ?int $effectiveCustomerId,
        CartPricingMode $pricingMode,
        string $currencyCode,
    ): ?Cart {
        $sql = <<<'SQL'
SELECT TOP 1
    c.[CartId],
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [PublicId],
    c.[OwnerUserId],
    c.[EffectiveCustomerId],
    c.[PricingMode],
    c.[CurrencyCode],
    c.[Status],
    c.[PricingFingerprint],
    CONVERT(VARBINARY(8), c.[RowVersion]) AS [RowVersion]
FROM [commerce].[Carts] c
WHERE c.[OwnerUserId] = :ownerUserId
  AND c.[PricingMode] = :pricingMode
  AND c.[CurrencyCode] = :currencyCode
  AND c.[Status] = N'OPEN'
  AND c.[DeletedAtUtc] IS NULL
SQL;
        $params = [
            ':ownerUserId' => $ownerUserId,
            ':pricingMode' => $pricingMode->toString(),
            ':currencyCode' => strtoupper($currencyCode),
        ];
        if ($effectiveCustomerId === null) {
            $sql .= ' AND c.[EffectiveCustomerId] IS NULL';
        } else {
            $sql .= ' AND c.[EffectiveCustomerId] = :effectiveCustomerId';
            $params[':effectiveCustomerId'] = $effectiveCustomerId;
        }

        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            return null;
        }

        return $this->hydrateCart($rows[0], true);
    }

    public function getByPublicId(CartPublicId $cartPublicId, int $ownerUserId): ?Cart
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    c.[CartId],
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [PublicId],
    c.[OwnerUserId],
    c.[EffectiveCustomerId],
    c.[PricingMode],
    c.[CurrencyCode],
    c.[Status],
    c.[PricingFingerprint],
    CONVERT(VARBINARY(8), c.[RowVersion]) AS [RowVersion]
FROM [commerce].[Carts] c
WHERE c.[PublicId] = :publicId
  AND c.[OwnerUserId] = :ownerUserId
  AND c.[DeletedAtUtc] IS NULL
SQL,
            [
                ':publicId' => $cartPublicId->toString(),
                ':ownerUserId' => $ownerUserId,
            ],
        );

        return $rows === [] ? null : $this->hydrateCart($rows[0], true);
    }

    public function createCart(
        int $ownerUserId,
        ?int $effectiveCustomerId,
        CartPricingMode $pricingMode,
        string $currencyCode,
        ?int $actorUserId = null,
    ): Cart {
        return $this->runInTransaction($this->connection, function () use (
            $ownerUserId,
            $effectiveCustomerId,
            $pricingMode,
            $currencyCode,
            $actorUserId,
        ): Cart {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
INSERT INTO [commerce].[Carts] (
    [OwnerUserId],
    [EffectiveCustomerId],
    [PricingMode],
    [CurrencyCode],
    [Status],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT
    INSERTED.[CartId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[OwnerUserId],
    INSERTED.[EffectiveCustomerId],
    INSERTED.[PricingMode],
    INSERTED.[CurrencyCode],
    INSERTED.[Status],
    INSERTED.[PricingFingerprint],
    CONVERT(VARBINARY(8), INSERTED.[RowVersion]) AS [RowVersion]
VALUES (
    :ownerUserId,
    :effectiveCustomerId,
    :pricingMode,
    :currencyCode,
    N'OPEN',
    :createdByUserId,
    :updatedByUserId
)
SQL,
                [
                    ':ownerUserId' => $ownerUserId,
                    ':effectiveCustomerId' => $effectiveCustomerId,
                    ':pricingMode' => $pricingMode->toString(),
                    ':currencyCode' => strtoupper($currencyCode),
                    ':createdByUserId' => $actorUserId,
                    ':updatedByUserId' => $actorUserId,
                ],
            );

            return $this->hydrateCart($rows[0], false);
        });
    }

    public function listLines(CartId $cartId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    l.[CartLineId],
    CONVERT(NVARCHAR(36), l.[PublicId]) AS [PublicId],
    l.[CartId],
    l.[ProductVariantId],
    CONVERT(NVARCHAR(36), pv.[PublicId]) AS [VariantPublicId],
    l.[PurchaseOptionKey],
    l.[Quantity],
    CONVERT(VARCHAR(32), l.[Length]) AS [Length],
    l.[UomCode],
    l.[Availability],
    l.[PricingStatus],
    CONVERT(VARCHAR(32), l.[UnitSellPrice]) AS [UnitSellPrice],
    CONVERT(VARCHAR(32), l.[LineTotal]) AS [LineTotal],
    l.[CurrencyCode],
    l.[MarginSource],
    l.[PricingFingerprint],
    CONVERT(VARCHAR(33), l.[ResolvedAtUtc], 127) AS [ResolvedAtUtc],
    CONVERT(VARBINARY(8), l.[RowVersion]) AS [RowVersion],
    pv.[VariantCode],
    pv.[VariantName] AS [DisplayName]
FROM [commerce].[CartLines] l
INNER JOIN [product].[ProductVariants] pv ON pv.[ProductVariantId] = l.[ProductVariantId]
WHERE l.[CartId] = :cartId
  AND l.[DeletedAtUtc] IS NULL
ORDER BY l.[CartLineId]
SQL,
            [':cartId' => $cartId->toInt()],
        );

        return array_map(fn (array $row): CartLine => $this->hydrateLine($row), $rows);
    }

    public function addOrMergeLine(CartId $cartId, CartLine $line, ?int $actorUserId = null): CartLine
    {
        return $this->runInTransaction($this->connection, function () use ($cartId, $line, $actorUserId): CartLine {
            $existingRows = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    l.[CartLineId],
    CONVERT(NVARCHAR(36), l.[PublicId]) AS [PublicId],
    l.[CartId],
    l.[ProductVariantId],
    CONVERT(NVARCHAR(36), pv.[PublicId]) AS [VariantPublicId],
    l.[PurchaseOptionKey],
    l.[Quantity],
    CONVERT(VARCHAR(32), l.[Length]) AS [Length],
    l.[UomCode],
    l.[Availability],
    l.[PricingStatus],
    CONVERT(VARCHAR(32), l.[UnitSellPrice]) AS [UnitSellPrice],
    CONVERT(VARCHAR(32), l.[LineTotal]) AS [LineTotal],
    l.[CurrencyCode],
    l.[MarginSource],
    l.[PricingFingerprint],
    CONVERT(VARCHAR(33), l.[ResolvedAtUtc], 127) AS [ResolvedAtUtc],
    CONVERT(VARBINARY(8), l.[RowVersion]) AS [RowVersion],
    pv.[VariantCode],
    pv.[VariantName] AS [DisplayName]
FROM [commerce].[CartLines] l
INNER JOIN [product].[ProductVariants] pv ON pv.[ProductVariantId] = l.[ProductVariantId]
WHERE l.[CartId] = :cartId
  AND l.[ProductVariantId] = :productVariantId
  AND l.[DeletedAtUtc] IS NULL
  AND ISNULL(l.[PurchaseOptionKey], N'') = ISNULL(:purchaseOptionKey, N'')
  AND (
        (:lengthIsNullA = 1 AND l.[Length] IS NULL)
     OR (:lengthIsNullB = 0 AND l.[Length] = CONVERT(DECIMAL(18,3), :lengthValue))
  )
  AND l.[PricingStatus] = :pricingStatus
  AND ISNULL(l.[PricingFingerprint], N'') = ISNULL(:pricingFingerprint, N'')
SQL,
                [
                    ':cartId' => $cartId->toInt(),
                    ':productVariantId' => $line->productVariantId(),
                    ':purchaseOptionKey' => $line->purchaseOptionKey(),
                    ':lengthIsNullA' => $line->length() === null ? 1 : 0,
                    ':lengthIsNullB' => $line->length() === null ? 1 : 0,
                    ':lengthValue' => $line->length() ?? '0',
                    ':pricingStatus' => $line->pricingStatus(),
                    ':pricingFingerprint' => $line->pricingFingerprint(),
                ],
            );

            if ($existingRows !== []) {
                $existing = $this->hydrateLine($existingRows[0]);
                $newQty = $existing->quantity() + $line->quantity();
                $lineTotal = $line->unitSellPrice() !== null && $line->length() !== null
                    ? bcmul($line->unitSellPrice(), bcmul((string) $newQty, $line->length(), 6), 2)
                    : null;

                $updated = $this->connection->fetchAll(
                    <<<'SQL'
UPDATE [commerce].[CartLines]
SET
    [Quantity] = :quantity,
    [UnitSellPrice] = :unitSellPrice,
    [LineTotal] = :lineTotal,
    [PricingStatus] = :pricingStatus,
    [MarginSource] = :marginSource,
    [PricingFingerprint] = :pricingFingerprint,
    [Availability] = :availability,
    [UomCode] = :uomCode,
    [ResolvedAtUtc] = SYSUTCDATETIME(),
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actorUserId
OUTPUT INSERTED.[CartLineId]
WHERE [CartLineId] = :cartLineId
  AND [DeletedAtUtc] IS NULL
SQL,
                    [
                        ':quantity' => $newQty,
                        ':unitSellPrice' => $line->unitSellPrice(),
                        ':lineTotal' => $lineTotal,
                        ':pricingStatus' => $line->pricingStatus(),
                        ':marginSource' => $line->marginSource(),
                        ':pricingFingerprint' => $line->pricingFingerprint(),
                        ':availability' => $line->availability(),
                        ':uomCode' => $line->uomCode(),
                        ':actorUserId' => $actorUserId,
                        ':cartLineId' => $existing->id()?->toInt(),
                    ],
                );

                return $this->requireLineById(AzureSqlValueMapper::intValue($updated[0]['CartLineId'] ?? 0));
            }

            $inserted = $this->connection->fetchAll(
                <<<'SQL'
INSERT INTO [commerce].[CartLines] (
    [CartId],
    [ProductVariantId],
    [PurchaseOptionKey],
    [Quantity],
    [Length],
    [UomCode],
    [Availability],
    [PricingStatus],
    [UnitSellPrice],
    [LineTotal],
    [CurrencyCode],
    [MarginSource],
    [PricingFingerprint],
    [ResolvedAtUtc],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT INSERTED.[CartLineId]
VALUES (
    :cartId,
    :productVariantId,
    :purchaseOptionKey,
    :quantity,
    :length,
    :uomCode,
    :availability,
    :pricingStatus,
    :unitSellPrice,
    :lineTotal,
    :currencyCode,
    :marginSource,
    :pricingFingerprint,
    SYSUTCDATETIME(),
    :createdByUserId,
    :updatedByUserId
)
SQL,
                [
                    ':cartId' => $cartId->toInt(),
                    ':productVariantId' => $line->productVariantId(),
                    ':purchaseOptionKey' => $line->purchaseOptionKey(),
                    ':quantity' => $line->quantity(),
                    ':length' => $line->length(),
                    ':uomCode' => $line->uomCode(),
                    ':availability' => $line->availability(),
                    ':pricingStatus' => $line->pricingStatus(),
                    ':unitSellPrice' => $line->unitSellPrice(),
                    ':lineTotal' => $line->lineTotal(),
                    ':currencyCode' => $line->currencyCode(),
                    ':marginSource' => $line->marginSource(),
                    ':pricingFingerprint' => $line->pricingFingerprint(),
                    ':createdByUserId' => $actorUserId,
                    ':updatedByUserId' => $actorUserId,
                ],
            );

            $lineId = AzureSqlValueMapper::intValue($inserted[0]['CartLineId'] ?? 0);

            return $this->requireLineById($lineId);
        });
    }

    public function updateLine(
        CartId $cartId,
        CartLinePublicId $linePublicId,
        CartLine $line,
        RowVersionToken $expectedRowVersion,
        ?int $actorUserId = null,
    ): CartLine {
        return $this->runInTransaction($this->connection, function () use (
            $cartId,
            $linePublicId,
            $line,
            $expectedRowVersion,
            $actorUserId,
        ): CartLine {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
UPDATE [commerce].[CartLines]
SET
    [Quantity] = :quantity,
    [Length] = :length,
    [UomCode] = :uomCode,
    [Availability] = :availability,
    [PricingStatus] = :pricingStatus,
    [UnitSellPrice] = :unitSellPrice,
    [LineTotal] = :lineTotal,
    [CurrencyCode] = :currencyCode,
    [MarginSource] = :marginSource,
    [PricingFingerprint] = :pricingFingerprint,
    [ResolvedAtUtc] = SYSUTCDATETIME(),
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actorUserId
OUTPUT INSERTED.[CartLineId]
WHERE [CartId] = :cartId
  AND [PublicId] = :linePublicId
  AND [DeletedAtUtc] IS NULL
  AND [RowVersion] = :expectedRowVersion
SQL,
                [
                    ':quantity' => $line->quantity(),
                    ':length' => $line->length(),
                    ':uomCode' => $line->uomCode(),
                    ':availability' => $line->availability(),
                    ':pricingStatus' => $line->pricingStatus(),
                    ':unitSellPrice' => $line->unitSellPrice(),
                    ':lineTotal' => $line->lineTotal(),
                    ':currencyCode' => $line->currencyCode(),
                    ':marginSource' => $line->marginSource(),
                    ':pricingFingerprint' => $line->pricingFingerprint(),
                    ':actorUserId' => $actorUserId,
                    ':cartId' => $cartId->toInt(),
                    ':linePublicId' => $linePublicId->toString(),
                    ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expectedRowVersion),
                ],
            );

            if ($rows === []) {
                $exists = $this->connection->fetchAll(
                    'SELECT TOP 1 1 AS [Found] FROM [commerce].[CartLines] WHERE [PublicId] = :id AND [DeletedAtUtc] IS NULL',
                    [':id' => $linePublicId->toString()],
                );
                if ($exists === []) {
                    throw CartNotFound::forLinePublicId($linePublicId->toString());
                }

                throw CartConcurrencyConflict::forLine();
            }

            return $this->requireLineById(AzureSqlValueMapper::intValue($rows[0]['CartLineId'] ?? 0));
        });
    }

    public function softRemoveLine(
        CartId $cartId,
        CartLinePublicId $linePublicId,
        RowVersionToken $expectedRowVersion,
        ?int $actorUserId = null,
    ): void {
        $this->runInTransaction($this->connection, function () use (
            $cartId,
            $linePublicId,
            $expectedRowVersion,
            $actorUserId,
        ): void {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
UPDATE [commerce].[CartLines]
SET
    [IsActive] = 0,
    [DeletedAtUtc] = SYSUTCDATETIME(),
    [DeletedByUserId] = :deletedByUserId,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT INSERTED.[CartLineId]
WHERE [CartId] = :cartId
  AND [PublicId] = :linePublicId
  AND [DeletedAtUtc] IS NULL
  AND [RowVersion] = :expectedRowVersion
SQL,
                [
                    ':deletedByUserId' => $actorUserId,
                    ':updatedByUserId' => $actorUserId,
                    ':cartId' => $cartId->toInt(),
                    ':linePublicId' => $linePublicId->toString(),
                    ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expectedRowVersion),
                ],
            );

            if ($rows === []) {
                $exists = $this->connection->fetchAll(
                    'SELECT TOP 1 1 AS [Found] FROM [commerce].[CartLines] WHERE [PublicId] = :id AND [DeletedAtUtc] IS NULL',
                    [':id' => $linePublicId->toString()],
                );
                if ($exists === []) {
                    throw CartNotFound::forLinePublicId($linePublicId->toString());
                }

                throw CartConcurrencyConflict::forLine();
            }
        });
    }

    public function touchCartUpdatedAt(CartId $cartId, ?int $actorUserId = null): void
    {
        $this->connection->execute(
            <<<'SQL'
UPDATE [commerce].[Carts]
SET
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actorUserId
WHERE [CartId] = :cartId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':actorUserId' => $actorUserId,
                ':cartId' => $cartId->toInt(),
            ],
        );
    }

    public function markSubmitted(CartId $cartId, ?int $actorUserId = null): Cart
    {
        return $this->setStatus($cartId, CartStatus::SUBMITTED, $actorUserId);
    }

    public function abandon(CartId $cartId, ?int $actorUserId = null): Cart
    {
        return $this->setStatus($cartId, CartStatus::ABANDONED, $actorUserId);
    }

    public function resolveProductVariantId(string $variantPublicId): ?int
    {
        $rows = $this->connection->fetchAll(
            'SELECT TOP 1 [ProductVariantId] FROM [product].[ProductVariants] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $variantPublicId],
        );

        return $rows === [] ? null : AzureSqlValueMapper::nullableInt($rows[0]['ProductVariantId'] ?? null);
    }

    public function resolveVariantDisplay(int $productVariantId): ?array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 1
    CONVERT(NVARCHAR(36), pv.[PublicId]) AS [PublicId],
    pv.[VariantCode] AS [Code],
    pv.[VariantName] AS [DisplayName]
FROM [product].[ProductVariants] pv
WHERE pv.[ProductVariantId] = :id
SQL,
            [':id' => $productVariantId],
        );
        if ($rows === []) {
            return null;
        }

        return [
            'publicId' => AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''),
            'code' => AzureSqlValueMapper::nullableString($rows[0]['Code'] ?? null),
            'displayName' => AzureSqlValueMapper::nullableString($rows[0]['DisplayName'] ?? null),
        ];
    }

    public function findLineByPublicId(CartLinePublicId $linePublicId, int $ownerUserId): ?CartLine
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    l.[CartLineId],
    CONVERT(NVARCHAR(36), l.[PublicId]) AS [PublicId],
    l.[CartId],
    l.[ProductVariantId],
    CONVERT(NVARCHAR(36), pv.[PublicId]) AS [VariantPublicId],
    l.[PurchaseOptionKey],
    l.[Quantity],
    CONVERT(VARCHAR(32), l.[Length]) AS [Length],
    l.[UomCode],
    l.[Availability],
    l.[PricingStatus],
    CONVERT(VARCHAR(32), l.[UnitSellPrice]) AS [UnitSellPrice],
    CONVERT(VARCHAR(32), l.[LineTotal]) AS [LineTotal],
    l.[CurrencyCode],
    l.[MarginSource],
    l.[PricingFingerprint],
    CONVERT(VARCHAR(33), l.[ResolvedAtUtc], 127) AS [ResolvedAtUtc],
    CONVERT(VARBINARY(8), l.[RowVersion]) AS [RowVersion],
    pv.[VariantCode],
    pv.[VariantName] AS [DisplayName]
FROM [commerce].[CartLines] l
INNER JOIN [commerce].[Carts] c ON c.[CartId] = l.[CartId]
INNER JOIN [product].[ProductVariants] pv ON pv.[ProductVariantId] = l.[ProductVariantId]
WHERE l.[PublicId] = :linePublicId
  AND c.[OwnerUserId] = :ownerUserId
  AND l.[DeletedAtUtc] IS NULL
  AND c.[DeletedAtUtc] IS NULL
SQL,
            [
                ':linePublicId' => $linePublicId->toString(),
                ':ownerUserId' => $ownerUserId,
            ],
        );

        return $rows === [] ? null : $this->hydrateLine($rows[0]);
    }

    private function setStatus(CartId $cartId, string $status, ?int $actorUserId): Cart
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [commerce].[Carts]
SET
    [Status] = :status,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actorUserId
OUTPUT
    INSERTED.[CartId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[OwnerUserId],
    INSERTED.[EffectiveCustomerId],
    INSERTED.[PricingMode],
    INSERTED.[CurrencyCode],
    INSERTED.[Status],
    INSERTED.[PricingFingerprint],
    CONVERT(VARBINARY(8), INSERTED.[RowVersion]) AS [RowVersion]
WHERE [CartId] = :cartId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':status' => $status,
                ':actorUserId' => $actorUserId,
                ':cartId' => $cartId->toInt(),
            ],
        );
        if ($rows === []) {
            throw CartNotFound::forPublicId((string) $cartId->toInt());
        }

        return $this->hydrateCart($rows[0], true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCart(array $row, bool $withLines): Cart
    {
        $cartId = CartId::fromInt(AzureSqlValueMapper::intValue($row['CartId'] ?? 0));

        return new Cart(
            id: $cartId,
            publicId: CartPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            ownerUserId: AzureSqlValueMapper::intValue($row['OwnerUserId'] ?? 0),
            effectiveCustomerId: AzureSqlValueMapper::nullableInt($row['EffectiveCustomerId'] ?? null),
            pricingMode: CartPricingMode::fromString(AzureSqlValueMapper::stringValue($row['PricingMode'] ?? '')),
            currencyCode: AzureSqlValueMapper::stringValue($row['CurrencyCode'] ?? ''),
            status: CartStatus::fromString(AzureSqlValueMapper::stringValue($row['Status'] ?? '')),
            pricingFingerprint: AzureSqlValueMapper::nullableString($row['PricingFingerprint'] ?? null),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
            lines: $withLines ? $this->listLines($cartId) : [],
        );
    }

    private function requireLineById(int $cartLineId): CartLine
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    l.[CartLineId],
    CONVERT(NVARCHAR(36), l.[PublicId]) AS [PublicId],
    l.[CartId],
    l.[ProductVariantId],
    CONVERT(NVARCHAR(36), pv.[PublicId]) AS [VariantPublicId],
    l.[PurchaseOptionKey],
    l.[Quantity],
    CONVERT(VARCHAR(32), l.[Length]) AS [Length],
    l.[UomCode],
    l.[Availability],
    l.[PricingStatus],
    CONVERT(VARCHAR(32), l.[UnitSellPrice]) AS [UnitSellPrice],
    CONVERT(VARCHAR(32), l.[LineTotal]) AS [LineTotal],
    l.[CurrencyCode],
    l.[MarginSource],
    l.[PricingFingerprint],
    CONVERT(VARCHAR(33), l.[ResolvedAtUtc], 127) AS [ResolvedAtUtc],
    CONVERT(VARBINARY(8), l.[RowVersion]) AS [RowVersion],
    pv.[VariantCode],
    pv.[VariantName] AS [DisplayName]
FROM [commerce].[CartLines] l
INNER JOIN [product].[ProductVariants] pv ON pv.[ProductVariantId] = l.[ProductVariantId]
WHERE l.[CartLineId] = :cartLineId
  AND l.[DeletedAtUtc] IS NULL
SQL,
            [':cartLineId' => $cartLineId],
        );

        if ($rows === []) {
            throw CartNotFound::forLinePublicId((string) $cartLineId);
        }

        return $this->hydrateLine($rows[0]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateLine(array $row): CartLine
    {
        return new CartLine(
            id: CartLineId::fromInt(AzureSqlValueMapper::intValue($row['CartLineId'] ?? 0)),
            publicId: CartLinePublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            cartId: CartId::fromInt(AzureSqlValueMapper::intValue($row['CartId'] ?? 0)),
            productVariantId: AzureSqlValueMapper::intValue($row['ProductVariantId'] ?? 0),
            variantPublicId: AzureSqlValueMapper::stringValue($row['VariantPublicId'] ?? ''),
            purchaseOptionKey: AzureSqlValueMapper::nullableString($row['PurchaseOptionKey'] ?? null),
            quantity: AzureSqlValueMapper::intValue($row['Quantity'] ?? 0),
            length: AzureSqlValueMapper::nullableString($row['Length'] ?? null),
            uomCode: AzureSqlValueMapper::nullableString($row['UomCode'] ?? null),
            availability: AzureSqlValueMapper::nullableString($row['Availability'] ?? null),
            pricingStatus: AzureSqlValueMapper::stringValue($row['PricingStatus'] ?? ''),
            unitSellPrice: AzureSqlValueMapper::nullableString($row['UnitSellPrice'] ?? null),
            lineTotal: AzureSqlValueMapper::nullableString($row['LineTotal'] ?? null),
            currencyCode: AzureSqlValueMapper::stringValue($row['CurrencyCode'] ?? ''),
            marginSource: AzureSqlValueMapper::nullableString($row['MarginSource'] ?? null),
            pricingFingerprint: AzureSqlValueMapper::nullableString($row['PricingFingerprint'] ?? null),
            resolvedAtUtc: AzureSqlValueMapper::nullableString($row['ResolvedAtUtc'] ?? null),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
            variantCode: AzureSqlValueMapper::nullableString($row['VariantCode'] ?? null),
            displayName: AzureSqlValueMapper::nullableString($row['DisplayName'] ?? null),
        );
    }
}
