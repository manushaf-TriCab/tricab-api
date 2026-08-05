<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Exception\DuplicateVariantSku;
use TriCab\Core\Product\Contract\ProductVariantRepository;
use TriCab\Core\Product\Domain\Entity\ProductVariant;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Domain\ValueObject\Sku;

/**
 * Azure SQL command repository for the ProductVariant aggregate root.
 * Tables: product.ProductVariants, product.ProductStatuses, product.Products (parent check).
 */
final class AzureSqlProductVariantRepository implements ProductVariantRepository
{
    use AzureSqlTransaction;
    use UniqueConstraintTranslator;

    private const AGGREGATE_SELECT_SQL = <<<'SQL'
SELECT
    v.[ProductVariantId],
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [PublicId],
    v.[ProductId],
    ps.[StatusCode],
    v.[VariantName],
    v.[Sku],
    v.[VariantCode],
    v.[DisplayOrder],
    CONVERT(NVARCHAR(50), v.[MinimumOrderQuantity]) AS [MinimumOrderQuantity],
    CONVERT(NVARCHAR(50), v.[WeightKg]) AS [WeightKg],
    v.[PackagingDescription],
    v.[ErpReference],
    v.[IsActive],
    CONVERT(VARCHAR(33), v.[DeletedAtUtc], 127) AS [DeletedAtUtc],
    v.[DeletedByUserId],
    CONVERT(VARBINARY(8), v.[RowVersion]) AS [RowVersion]
FROM [product].[ProductVariants] v
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = v.[ProductStatusId]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findByPublicId(ProductVariantPublicId $publicId): ?ProductVariant
    {
        $rows = $this->connection->fetchAll(
            self::AGGREGATE_SELECT_SQL . ' WHERE v.[PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findById(ProductVariantId $variantId): ?ProductVariant
    {
        $rows = $this->connection->fetchAll(
            self::AGGREGATE_SELECT_SQL . ' WHERE v.[ProductVariantId] = :variantId',
            [':variantId' => $variantId->toInt()],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findIdByPublicId(ProductVariantPublicId $publicId): ?ProductVariantId
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductVariantId] FROM [product].[ProductVariants] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return ProductVariantId::fromInt(AzureSqlValueMapper::intValue($rows[0]['ProductVariantId'] ?? 0));
    }

    public function skuExists(Sku $sku, ?ProductVariantPublicId $exceptPublicId = null): bool
    {
        $sql = <<<'SQL'
SELECT TOP 1 1 AS [Found]
FROM [product].[ProductVariants]
WHERE [Sku] = :sku
  AND [DeletedAtUtc] IS NULL
SQL;
        $params = [':sku' => $sku->toString()];
        if ($exceptPublicId !== null) {
            $sql .= ' AND [PublicId] <> :exceptPublicId';
            $params[':exceptPublicId'] = $exceptPublicId->toString();
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function findRowVersion(ProductVariantPublicId $publicId): ?RowVersionToken
    {
        $rows = $this->connection->fetchAll(
            'SELECT CONVERT(VARBINARY(8), [RowVersion]) AS [RowVersion] FROM [product].[ProductVariants] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return ProductRowVersionMapper::toToken($rows[0]['RowVersion'] ?? null);
    }

    public function save(
        ProductVariant $variant,
        UserId $actorUserId,
        ?RowVersionToken $expectedRowVersion = null,
    ): ProductVariant {
        $sku = $variant->sku();

        return $this->translatingUniqueConstraintViolation(
            fn () => $this->doSave($variant, $actorUserId, $expectedRowVersion),
            fn () => DuplicateVariantSku::forSku($sku !== null ? $sku->toString() : ''),
        );
    }

    private function doSave(
        ProductVariant $variant,
        UserId $actorUserId,
        ?RowVersionToken $expectedRowVersion = null,
    ): ProductVariant {
        return $this->runInTransaction(
            $this->connection,
            function () use ($variant, $actorUserId, $expectedRowVersion): ProductVariant {
                $this->assertParentProductUsable($variant->productId());

                if ($variant->id() === null) {
                    return $this->insert($variant, $actorUserId);
                }

                if ($expectedRowVersion === null) {
                    throw ProductPersistenceException::expectedRowVersionRequired();
                }

                return $this->update($variant, $actorUserId, $expectedRowVersion);
            },
        );
    }

    private function assertParentProductUsable(ProductId $productId): void
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT [DeletedAtUtc]
FROM [product].[Products]
WHERE [ProductId] = :productId
SQL,
            [':productId' => $productId->toInt()],
        );

        if ($rows === []) {
            throw ProductPersistenceException::parentProductInvalid(
                sprintf('Parent product %d does not exist.', $productId->toInt()),
            );
        }

        if (($rows[0]['DeletedAtUtc'] ?? null) !== null) {
            throw ProductPersistenceException::parentProductInvalid(
                'Cannot save a variant under a soft-deleted parent product.',
            );
        }
    }

    private function insert(ProductVariant $variant, UserId $actorUserId): ProductVariant
    {
        $statusId = $this->requireStatusId($variant->status()->toString());
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[ProductVariants] (
    [ProductId],
    [ProductStatusId],
    [VariantName],
    [Sku],
    [VariantCode],
    [DisplayOrder],
    [MinimumOrderQuantity],
    [WeightKg],
    [PackagingDescription],
    [ErpReference],
    [IsActive],
    [CreatedByUserId],
    [UpdatedByUserId],
    [DeletedAtUtc],
    [DeletedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :productId,
    :statusId,
    :variantName,
    :sku,
    :variantCode,
    :displayOrder,
    :moq,
    :weightKg,
    :packagingDescription,
    :erpReference,
    :isActive,
    :createdByUserId,
    :updatedByUserId,
    :deletedAtUtc,
    :deletedByUserId
)
SQL,
            $this->bindParams($variant, $actorUserId, $statusId),
        );

        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('ProductVariant');
        }

        $publicId = ProductVariantPublicId::fromString(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
        $loaded = $this->findByPublicId($publicId);
        if ($loaded === null) {
            throw ProductPersistenceException::insertFailed('ProductVariant');
        }

        return $loaded;
    }

    private function update(
        ProductVariant $variant,
        UserId $actorUserId,
        RowVersionToken $expectedRowVersion,
    ): ProductVariant {
        $variantId = $variant->id();
        if ($variantId === null) {
            throw ProductPersistenceException::expectedRowVersionRequired();
        }

        $statusId = $this->requireStatusId($variant->status()->toString());
        $params = $this->bindParams($variant, $actorUserId, $statusId);
        unset($params[':createdByUserId']);
        $params[':variantId'] = $variantId->toInt();
        $params[':expectedRowVersion'] = ProductRowVersionMapper::toSqlBinary($expectedRowVersion);

        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [product].[ProductVariants]
SET
    [ProductId] = :productId,
    [ProductStatusId] = :statusId,
    [VariantName] = :variantName,
    [Sku] = :sku,
    [VariantCode] = :variantCode,
    [DisplayOrder] = :displayOrder,
    [MinimumOrderQuantity] = :moq,
    [WeightKg] = :weightKg,
    [PackagingDescription] = :packagingDescription,
    [ErpReference] = :erpReference,
    [IsActive] = :isActive,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId,
    [DeletedAtUtc] = :deletedAtUtc,
    [DeletedByUserId] = :deletedByUserId
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
WHERE [ProductVariantId] = :variantId
  AND [RowVersion] = :expectedRowVersion
SQL,
            $params,
        );

        if ($rows === []) {
            if ($this->findIdByPublicId($variant->publicId()) === null) {
                throw ProductPersistenceException::variantNotFound($variant->publicId()->toString());
            }

            throw ProductConcurrencyException::forPublicId($variant->publicId()->toString());
        }

        $loaded = $this->findByPublicId($variant->publicId());
        if ($loaded === null) {
            throw ProductPersistenceException::variantNotFound($variant->publicId()->toString());
        }

        return $loaded;
    }

    /**
     * @return array<string, scalar|SqlBinary|null>
     */
    private function bindParams(ProductVariant $variant, UserId $actorUserId, int $statusId): array
    {
        return [
            ':productId' => $variant->productId()->toInt(),
            ':statusId' => $statusId,
            ':variantName' => $variant->variantName(),
            ':sku' => $variant->sku()?->toString(),
            ':variantCode' => $variant->variantCode()?->toString(),
            ':displayOrder' => $variant->displayOrder()->toInt(),
            ':moq' => $variant->minimumOrderQuantity(),
            ':weightKg' => $variant->weightKg(),
            ':packagingDescription' => $variant->packagingDescription(),
            ':erpReference' => $variant->erpReference(),
            ':isActive' => $variant->isActive() ? 1 : 0,
            ':createdByUserId' => $actorUserId->toInt(),
            ':updatedByUserId' => $actorUserId->toInt(),
            ':deletedAtUtc' => $variant->deletedAtUtc() !== null
                ? AzureSqlValueMapper::formatDateTime($variant->deletedAtUtc())
                : null,
            ':deletedByUserId' => $variant->deletedByUserId()?->toInt(),
        ];
    }

    private function requireStatusId(string $statusCode): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductStatusId] FROM [product].[ProductStatuses] WHERE [StatusCode] = :code AND [DeletedAtUtc] IS NULL',
            [':code' => $statusCode],
        );
        if ($rows === []) {
            throw ProductPersistenceException::statusNotFound($statusCode);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductStatusId'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProductVariant
    {
        $deletedBy = AzureSqlValueMapper::intValue($row['DeletedByUserId'] ?? 0);
        $sku = AzureSqlValueMapper::nullableString($row['Sku'] ?? null);
        $variantCode = AzureSqlValueMapper::nullableString($row['VariantCode'] ?? null);

        return new ProductVariant(
            id: ProductVariantId::fromInt(AzureSqlValueMapper::intValue($row['ProductVariantId'] ?? 0)),
            publicId: ProductVariantPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            productId: ProductId::fromInt(AzureSqlValueMapper::intValue($row['ProductId'] ?? 0)),
            variantName: AzureSqlValueMapper::stringValue($row['VariantName'] ?? ''),
            status: ProductStatusCode::fromString(AzureSqlValueMapper::stringValue($row['StatusCode'] ?? '')),
            displayOrder: DisplayOrder::fromInt(AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0)),
            sku: $sku !== null ? Sku::fromString($sku) : null,
            variantCode: $variantCode !== null ? ProductCodeValue::fromString($variantCode) : null,
            minimumOrderQuantity: ProductDecimalMapper::toNullableString($row['MinimumOrderQuantity'] ?? null),
            weightKg: ProductDecimalMapper::toNullableString($row['WeightKg'] ?? null),
            packagingDescription: AzureSqlValueMapper::nullableString($row['PackagingDescription'] ?? null),
            erpReference: AzureSqlValueMapper::nullableString($row['ErpReference'] ?? null),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            deletedAtUtc: AzureSqlValueMapper::nullableDateTime($row['DeletedAtUtc'] ?? null),
            deletedByUserId: $deletedBy > 0 ? UserId::fromInt($deletedBy) : null,
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
        );
    }
}
