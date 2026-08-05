<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\ValueObject\InternalProductCode;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryId;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyId;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSeriesId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Azure SQL command repository for the Product aggregate root.
 *
 * Tables: product.Products, product.ProductStatuses, product.ProductLifecycleStatuses,
 * product.ProductCodes (uniqueness), product.ProductSlugs (uniqueness).
 */
final class AzureSqlProductRepository implements ProductRepository
{
    use AzureSqlTransaction;

    private const AGGREGATE_SELECT_SQL = <<<'SQL'
SELECT
    p.[ProductId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[ProductFamilyId],
    p.[ProductCategoryId],
    p.[ProductSeriesId],
    ps.[StatusCode],
    pls.[LifecycleStatusCode],
    p.[ProductName],
    p.[InternalName],
    p.[InternalCode],
    p.[ShortDescription],
    p.[FullDescription],
    p.[LegacyProductId],
    p.[ErpReference],
    p.[IsFeatured],
    CONVERT(VARCHAR(33), p.[PublishAtUtc], 127) AS [PublishAtUtc],
    CONVERT(VARCHAR(33), p.[UnpublishAtUtc], 127) AS [UnpublishAtUtc],
    CONVERT(VARCHAR(33), p.[DiscontinueAtUtc], 127) AS [DiscontinueAtUtc],
    p.[IsActive],
    CONVERT(VARCHAR(33), p.[DeletedAtUtc], 127) AS [DeletedAtUtc],
    p.[DeletedByUserId],
    CONVERT(VARBINARY(8), p.[RowVersion]) AS [RowVersion]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
INNER JOIN [product].[ProductLifecycleStatuses] pls ON pls.[ProductLifecycleStatusId] = p.[ProductLifecycleStatusId]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findByPublicId(ProductPublicId $publicId): ?Product
    {
        $rows = $this->connection->fetchAll(
            self::AGGREGATE_SELECT_SQL . ' WHERE p.[PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findById(ProductId $productId): ?Product
    {
        $rows = $this->connection->fetchAll(
            self::AGGREGATE_SELECT_SQL . ' WHERE p.[ProductId] = :productId',
            [':productId' => $productId->toInt()],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function findIdByPublicId(ProductPublicId $publicId): ?ProductId
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return ProductId::fromInt(AzureSqlValueMapper::intValue($rows[0]['ProductId'] ?? 0));
    }

    public function productCodeExists(ProductCodeValue $code, ?ProductPublicId $exceptPublicId = null): bool
    {
        $sql = <<<'SQL'
SELECT TOP 1 1 AS [Found]
FROM [product].[ProductCodes] pc
WHERE pc.[CodeValue] = :codeValue
  AND pc.[DeletedAtUtc] IS NULL
SQL;
        $params = [':codeValue' => $code->toString()];

        if ($exceptPublicId !== null) {
            $sql .= <<<'SQL'

  AND NOT EXISTS (
        SELECT 1
        FROM [product].[Products] p
        WHERE p.[ProductId] = pc.[ProductId]
          AND p.[PublicId] = :exceptPublicId
  )
SQL;
            $params[':exceptPublicId'] = $exceptPublicId->toString();
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function internalCodeExists(string $internalCode, ?ProductPublicId $exceptPublicId = null): bool
    {
        $sql = <<<'SQL'
SELECT TOP 1 1 AS [Found]
FROM [product].[Products]
WHERE [InternalCode] = :internalCode
  AND [DeletedAtUtc] IS NULL
SQL;
        $params = [':internalCode' => $internalCode];

        if ($exceptPublicId !== null) {
            $sql .= ' AND [PublicId] <> :exceptPublicId';
            $params[':exceptPublicId'] = $exceptPublicId->toString();
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function slugExists(ProductSlug $slug, ?string $regionPublicId = null, ?ProductPublicId $exceptPublicId = null): bool
    {
        $sql = <<<'SQL'
SELECT TOP 1 1 AS [Found]
FROM [product].[ProductSlugs] ps
LEFT JOIN [product].[Regions] r ON r.[RegionId] = ps.[RegionId]
WHERE ps.[Slug] = :slug
  AND ps.[DeletedAtUtc] IS NULL
SQL;
        $params = [':slug' => $slug->toString()];

        if ($regionPublicId === null) {
            $sql .= ' AND ps.[RegionId] IS NULL';
        } else {
            $sql .= ' AND CONVERT(NVARCHAR(36), r.[PublicId]) = :regionPublicId';
            $params[':regionPublicId'] = $regionPublicId;
        }

        if ($exceptPublicId !== null) {
            $sql .= <<<'SQL'

  AND NOT EXISTS (
        SELECT 1 FROM [product].[Products] p
        WHERE p.[ProductId] = ps.[ProductId] AND p.[PublicId] = :exceptPublicId
  )
SQL;
            $params[':exceptPublicId'] = $exceptPublicId->toString();
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function findRowVersion(ProductPublicId $publicId): ?RowVersionToken
    {
        $rows = $this->connection->fetchAll(
            'SELECT CONVERT(VARBINARY(8), [RowVersion]) AS [RowVersion] FROM [product].[Products] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        return ProductRowVersionMapper::toToken($rows[0]['RowVersion'] ?? null);
    }

    public function save(
        Product $product,
        UserId $actorUserId,
        ?RowVersionToken $expectedRowVersion = null,
    ): Product {
        return $this->runInTransaction(
            $this->connection,
            function () use ($product, $actorUserId, $expectedRowVersion): Product {
                if ($product->id() === null) {
                    return $this->insert($product, $actorUserId);
                }

                if ($expectedRowVersion === null) {
                    throw ProductPersistenceException::expectedRowVersionRequired();
                }

                return $this->update($product, $actorUserId, $expectedRowVersion);
            },
        );
    }

    private function insert(Product $product, UserId $actorUserId): Product
    {
        $statusId = $this->requireStatusId($product->status()->toString());
        $lifecycleId = $this->requireLifecycleId($product->lifecycleStatus()->toString());

        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[Products] (
    [ProductFamilyId],
    [ProductCategoryId],
    [ProductSeriesId],
    [ProductStatusId],
    [ProductLifecycleStatusId],
    [ProductName],
    [InternalName],
    [InternalCode],
    [ShortDescription],
    [FullDescription],
    [LegacyProductId],
    [ErpReference],
    [IsFeatured],
    [PublishAtUtc],
    [UnpublishAtUtc],
    [DiscontinueAtUtc],
    [IsActive],
    [CreatedByUserId],
    [UpdatedByUserId],
    [DeletedAtUtc],
    [DeletedByUserId]
)
OUTPUT
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :familyId,
    :categoryId,
    :seriesId,
    :statusId,
    :lifecycleId,
    :productName,
    :internalName,
    :internalCode,
    :shortDescription,
    :fullDescription,
    :legacyProductId,
    :erpReference,
    :isFeatured,
    :publishAtUtc,
    :unpublishAtUtc,
    :discontinueAtUtc,
    :isActive,
    :createdByUserId,
    :updatedByUserId,
    :deletedAtUtc,
    :deletedByUserId
)
SQL,
            $this->bindProductParams($product, $actorUserId, $statusId, $lifecycleId),
        );

        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('Product');
        }

        $publicId = ProductPublicId::fromString(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
        $loaded = $this->findByPublicId($publicId);
        if ($loaded === null) {
            throw ProductPersistenceException::insertFailed('Product');
        }

        return $loaded;
    }

    private function update(
        Product $product,
        UserId $actorUserId,
        RowVersionToken $expectedRowVersion,
    ): Product {
        $productId = $product->id();
        if ($productId === null) {
            throw ProductPersistenceException::expectedRowVersionRequired();
        }

        $statusId = $this->requireStatusId($product->status()->toString());
        $lifecycleId = $this->requireLifecycleId($product->lifecycleStatus()->toString());

        $params = $this->bindProductParams($product, $actorUserId, $statusId, $lifecycleId);
        unset($params[':createdByUserId']);
        $params[':productId'] = $productId->toInt();
        $params[':expectedRowVersion'] = ProductRowVersionMapper::toSqlBinary($expectedRowVersion);

        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [product].[Products]
SET
    [ProductFamilyId] = :familyId,
    [ProductCategoryId] = :categoryId,
    [ProductSeriesId] = :seriesId,
    [ProductStatusId] = :statusId,
    [ProductLifecycleStatusId] = :lifecycleId,
    [ProductName] = :productName,
    [InternalName] = :internalName,
    [InternalCode] = :internalCode,
    [ShortDescription] = :shortDescription,
    [FullDescription] = :fullDescription,
    [LegacyProductId] = :legacyProductId,
    [ErpReference] = :erpReference,
    [IsFeatured] = :isFeatured,
    [PublishAtUtc] = :publishAtUtc,
    [UnpublishAtUtc] = :unpublishAtUtc,
    [DiscontinueAtUtc] = :discontinueAtUtc,
    [IsActive] = :isActive,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId,
    [DeletedAtUtc] = :deletedAtUtc,
    [DeletedByUserId] = :deletedByUserId
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
WHERE [ProductId] = :productId
  AND [RowVersion] = :expectedRowVersion
SQL,
            $params,
        );

        if ($rows === []) {
            if ($this->findIdByPublicId($product->publicId()) === null) {
                throw ProductPersistenceException::productNotFound($product->publicId()->toString());
            }

            throw ProductConcurrencyException::forPublicId($product->publicId()->toString());
        }

        $loaded = $this->findByPublicId($product->publicId());
        if ($loaded === null) {
            throw ProductPersistenceException::productNotFound($product->publicId()->toString());
        }

        return $loaded;
    }

    /**
     * @return array<string, scalar|SqlBinary|null>
     */
    private function bindProductParams(
        Product $product,
        UserId $actorUserId,
        int $statusId,
        int $lifecycleId,
    ): array {
        return [
            ':familyId' => $product->familyId()?->toInt(),
            ':categoryId' => $product->categoryId()?->toInt(),
            ':seriesId' => $product->seriesId()?->toInt(),
            ':statusId' => $statusId,
            ':lifecycleId' => $lifecycleId,
            ':productName' => $product->productName(),
            ':internalName' => $product->internalName(),
            ':internalCode' => $product->internalCode()?->toString(),
            ':shortDescription' => $product->shortDescription(),
            ':fullDescription' => $product->fullDescription(),
            ':legacyProductId' => $product->legacyProductId(),
            ':erpReference' => $product->erpReference(),
            ':isFeatured' => $product->isFeatured() ? 1 : 0,
            ':publishAtUtc' => $product->publishAtUtc() !== null
                ? AzureSqlValueMapper::formatDateTime($product->publishAtUtc())
                : null,
            ':unpublishAtUtc' => $product->unpublishAtUtc() !== null
                ? AzureSqlValueMapper::formatDateTime($product->unpublishAtUtc())
                : null,
            ':discontinueAtUtc' => $product->discontinueAtUtc() !== null
                ? AzureSqlValueMapper::formatDateTime($product->discontinueAtUtc())
                : null,
            ':isActive' => $product->isActive() ? 1 : 0,
            ':createdByUserId' => $actorUserId->toInt(),
            ':updatedByUserId' => $actorUserId->toInt(),
            ':deletedAtUtc' => $product->deletedAtUtc() !== null
                ? AzureSqlValueMapper::formatDateTime($product->deletedAtUtc())
                : null,
            ':deletedByUserId' => $product->deletedByUserId()?->toInt(),
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

    private function requireLifecycleId(string $lifecycleCode): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductLifecycleStatusId] FROM [product].[ProductLifecycleStatuses] WHERE [LifecycleStatusCode] = :code AND [DeletedAtUtc] IS NULL',
            [':code' => $lifecycleCode],
        );
        if ($rows === []) {
            throw ProductPersistenceException::lifecycleNotFound($lifecycleCode);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductLifecycleStatusId'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Product
    {
        $deletedBy = AzureSqlValueMapper::intValue($row['DeletedByUserId'] ?? 0);

        return new Product(
            id: ProductId::fromInt(AzureSqlValueMapper::intValue($row['ProductId'] ?? 0)),
            publicId: ProductPublicId::fromString(AzureSqlValueMapper::stringValue($row['PublicId'] ?? '')),
            productName: AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
            status: ProductStatusCode::fromString(AzureSqlValueMapper::stringValue($row['StatusCode'] ?? '')),
            lifecycleStatus: ProductLifecycleStatusCode::fromString(
                AzureSqlValueMapper::stringValue($row['LifecycleStatusCode'] ?? ''),
            ),
            familyId: $this->nullableFamilyId($row['ProductFamilyId'] ?? null),
            categoryId: $this->nullableCategoryId($row['ProductCategoryId'] ?? null),
            seriesId: $this->nullableSeriesId($row['ProductSeriesId'] ?? null),
            internalName: AzureSqlValueMapper::nullableString($row['InternalName'] ?? null),
            internalCode: ($code = AzureSqlValueMapper::nullableString($row['InternalCode'] ?? null)) !== null
                ? InternalProductCode::fromString($code)
                : null,
            shortDescription: AzureSqlValueMapper::nullableString($row['ShortDescription'] ?? null),
            fullDescription: AzureSqlValueMapper::nullableString($row['FullDescription'] ?? null),
            legacyProductId: AzureSqlValueMapper::nullableString($row['LegacyProductId'] ?? null),
            erpReference: AzureSqlValueMapper::nullableString($row['ErpReference'] ?? null),
            isFeatured: AzureSqlValueMapper::boolValue($row['IsFeatured'] ?? false),
            publishAtUtc: AzureSqlValueMapper::nullableDateTime($row['PublishAtUtc'] ?? null),
            unpublishAtUtc: AzureSqlValueMapper::nullableDateTime($row['UnpublishAtUtc'] ?? null),
            discontinueAtUtc: AzureSqlValueMapper::nullableDateTime($row['DiscontinueAtUtc'] ?? null),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            deletedAtUtc: AzureSqlValueMapper::nullableDateTime($row['DeletedAtUtc'] ?? null),
            deletedByUserId: $deletedBy > 0 ? UserId::fromInt($deletedBy) : null,
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null),
        );
    }

    private function nullableFamilyId(mixed $value): ?ProductFamilyId
    {
        $id = AzureSqlValueMapper::intValue($value ?? 0);

        return $id < 1 ? null : ProductFamilyId::fromInt($id);
    }

    private function nullableCategoryId(mixed $value): ?ProductCategoryId
    {
        $id = AzureSqlValueMapper::intValue($value ?? 0);

        return $id < 1 ? null : ProductCategoryId::fromInt($id);
    }

    private function nullableSeriesId(mixed $value): ?ProductSeriesId
    {
        $id = AzureSqlValueMapper::intValue($value ?? 0);

        return $id < 1 ? null : ProductSeriesId::fromInt($id);
    }
}
