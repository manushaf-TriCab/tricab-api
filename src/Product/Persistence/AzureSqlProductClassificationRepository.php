<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Application\Exception\InvalidProductClassification;
use TriCab\Core\Product\Contract\ProductClassificationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryId;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyId;
use TriCab\Core\Product\Domain\ValueObject\ProductSeriesId;

/**
 * Azure SQL repository for product classification lookups (family / category / series).
 * Tables: product.ProductFamilies, product.ProductCategories, product.ProductSeries.
 */
final class AzureSqlProductClassificationRepository implements ProductClassificationRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listFamilies(bool $activeOnly = true): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), f.[PublicId]) AS [PublicId],
    f.[FamilyCode] AS [Code],
    f.[FamilyName] AS [Name],
    f.[DisplayOrder],
    f.[IsActive]
FROM [product].[ProductFamilies] f
WHERE f.[DeletedAtUtc] IS NULL
SQL;
        if ($activeOnly) {
            $sql .= ' AND f.[IsActive] = 1';
        }
        $sql .= ' ORDER BY f.[DisplayOrder], f.[FamilyName]';

        return array_map(
            fn (array $row): ProductLookupItem => $this->hydrateLookup($row),
            $this->connection->fetchAll($sql),
        );
    }

    public function listCategories(?string $familyPublicId = null, bool $activeOnly = true): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [PublicId],
    c.[CategoryCode] AS [Code],
    c.[CategoryName] AS [Name],
    CONVERT(NVARCHAR(36), pf.[PublicId]) AS [ParentPublicId],
    c.[DisplayOrder],
    c.[IsActive]
FROM [product].[ProductCategories] c
LEFT JOIN [product].[ProductFamilies] pf ON pf.[ProductFamilyId] = c.[ProductFamilyId]
WHERE c.[DeletedAtUtc] IS NULL
SQL;
        $params = [];
        if ($familyPublicId !== null) {
            $sql .= ' AND pf.[PublicId] = :familyPublicId';
            $params[':familyPublicId'] = $familyPublicId;
        }
        if ($activeOnly) {
            $sql .= ' AND c.[IsActive] = 1';
        }
        $sql .= ' ORDER BY c.[DisplayOrder], c.[CategoryName]';

        return array_map(
            fn (array $row): ProductLookupItem => $this->hydrateLookup($row),
            $this->connection->fetchAll($sql, $params),
        );
    }

    public function listSeries(?string $categoryPublicId = null, bool $activeOnly = true): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [PublicId],
    s.[SeriesCode] AS [Code],
    s.[SeriesName] AS [Name],
    CONVERT(NVARCHAR(36), pc.[PublicId]) AS [ParentPublicId],
    s.[DisplayOrder],
    s.[IsActive]
FROM [product].[ProductSeries] s
LEFT JOIN [product].[ProductCategories] pc ON pc.[ProductCategoryId] = s.[ProductCategoryId]
WHERE s.[DeletedAtUtc] IS NULL
SQL;
        $params = [];
        if ($categoryPublicId !== null) {
            $sql .= ' AND pc.[PublicId] = :categoryPublicId';
            $params[':categoryPublicId'] = $categoryPublicId;
        }
        if ($activeOnly) {
            $sql .= ' AND s.[IsActive] = 1';
        }
        $sql .= ' ORDER BY s.[DisplayOrder], s.[SeriesName]';

        return array_map(
            fn (array $row): ProductLookupItem => $this->hydrateLookup($row),
            $this->connection->fetchAll($sql, $params),
        );
    }

    public function createFamily(array $data, UserId $actorUserId): ProductLookupItem
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[ProductFamilies] (
    [FamilyCode],
    [FamilyName],
    [Description],
    [DisplayOrder],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[FamilyCode] AS [Code],
    INSERTED.[FamilyName] AS [Name],
    INSERTED.[DisplayOrder],
    INSERTED.[IsActive]
VALUES (
    :code,
    :name,
    :description,
    :displayOrder,
    :createdByUserId,
    :updatedByUserId
)
SQL,
            [
                ':code' => $data['code'],
                ':name' => $data['name'],
                ':description' => $data['description'] ?? null,
                ':displayOrder' => $data['displayOrder'] ?? 0,
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );

        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('ProductFamily');
        }

        return $this->hydrateLookup($rows[0]);
    }

    public function createCategory(array $data, UserId $actorUserId): ProductLookupItem
    {
        $familyId = null;
        $parentPublicId = $data['familyPublicId'] ?? null;
        if ($parentPublicId !== null) {
            $familyId = $this->requireFamilyId($parentPublicId);
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[ProductCategories] (
    [ProductFamilyId],
    [CategoryCode],
    [CategoryName],
    [Description],
    [DisplayOrder],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[CategoryCode] AS [Code],
    INSERTED.[CategoryName] AS [Name],
    INSERTED.[DisplayOrder],
    INSERTED.[IsActive]
VALUES (
    :familyId,
    :code,
    :name,
    :description,
    :displayOrder,
    :createdByUserId,
    :updatedByUserId
)
SQL,
            [
                ':familyId' => $familyId,
                ':code' => $data['code'],
                ':name' => $data['name'],
                ':description' => $data['description'] ?? null,
                ':displayOrder' => $data['displayOrder'] ?? 0,
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );

        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('ProductCategory');
        }

        $item = $this->hydrateLookup($rows[0], $parentPublicId);

        return $item;
    }

    public function createSeries(array $data, UserId $actorUserId): ProductLookupItem
    {
        $categoryId = null;
        $parentPublicId = $data['categoryPublicId'] ?? null;
        if ($parentPublicId !== null) {
            $categoryId = $this->requireCategoryId($parentPublicId);
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[ProductSeries] (
    [ProductCategoryId],
    [SeriesCode],
    [SeriesName],
    [Description],
    [DisplayOrder],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId],
    INSERTED.[SeriesCode] AS [Code],
    INSERTED.[SeriesName] AS [Name],
    INSERTED.[DisplayOrder],
    INSERTED.[IsActive]
VALUES (
    :categoryId,
    :code,
    :name,
    :description,
    :displayOrder,
    :createdByUserId,
    :updatedByUserId
)
SQL,
            [
                ':categoryId' => $categoryId,
                ':code' => $data['code'],
                ':name' => $data['name'],
                ':description' => $data['description'] ?? null,
                ':displayOrder' => $data['displayOrder'] ?? 0,
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );

        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('ProductSeries');
        }

        return $this->hydrateLookup($rows[0], $parentPublicId);
    }

    public function findFamilyIdByPublicId(string $publicId): ?ProductFamilyId
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductFamilyId] FROM [product].[ProductFamilies] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL AND [IsActive] = 1',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            return null;
        }

        return ProductFamilyId::fromInt(AzureSqlValueMapper::intValue($rows[0]['ProductFamilyId'] ?? 0));
    }

    public function findCategoryIdByPublicId(string $publicId): ?ProductCategoryId
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductCategoryId] FROM [product].[ProductCategories] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL AND [IsActive] = 1',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            return null;
        }

        return ProductCategoryId::fromInt(AzureSqlValueMapper::intValue($rows[0]['ProductCategoryId'] ?? 0));
    }

    public function findSeriesIdByPublicId(string $publicId): ?ProductSeriesId
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductSeriesId] FROM [product].[ProductSeries] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL AND [IsActive] = 1',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            return null;
        }

        return ProductSeriesId::fromInt(AzureSqlValueMapper::intValue($rows[0]['ProductSeriesId'] ?? 0));
    }

    public function assertHierarchyValid(
        ?string $familyPublicId,
        ?string $categoryPublicId,
        ?string $seriesPublicId,
    ): void {
        if ($familyPublicId !== null && $this->findFamilyIdByPublicId($familyPublicId) === null) {
            throw InvalidProductClassification::forPublicId($familyPublicId);
        }

        if ($categoryPublicId !== null) {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    c.[ProductCategoryId],
    CONVERT(NVARCHAR(36), pf.[PublicId]) AS [FamilyPublicId]
FROM [product].[ProductCategories] c
LEFT JOIN [product].[ProductFamilies] pf ON pf.[ProductFamilyId] = c.[ProductFamilyId]
WHERE c.[PublicId] = :publicId AND c.[DeletedAtUtc] IS NULL AND c.[IsActive] = 1
SQL,
                [':publicId' => $categoryPublicId],
            );
            if ($rows === []) {
                throw InvalidProductClassification::forPublicId($categoryPublicId);
            }
            $categoryFamilyPublicId = AzureSqlValueMapper::nullableString($rows[0]['FamilyPublicId'] ?? null);

            if ($familyPublicId !== null && $categoryFamilyPublicId !== $familyPublicId) {
                throw InvalidProductClassification::invalidHierarchy(
                    sprintf('Category "%s" does not belong to family "%s".', $categoryPublicId, $familyPublicId),
                );
            }
        }

        if ($seriesPublicId !== null) {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
SELECT
    s.[ProductSeriesId],
    CONVERT(NVARCHAR(36), pc.[PublicId]) AS [CategoryPublicId]
FROM [product].[ProductSeries] s
LEFT JOIN [product].[ProductCategories] pc ON pc.[ProductCategoryId] = s.[ProductCategoryId]
WHERE s.[PublicId] = :publicId AND s.[DeletedAtUtc] IS NULL AND s.[IsActive] = 1
SQL,
                [':publicId' => $seriesPublicId],
            );
            if ($rows === []) {
                throw InvalidProductClassification::forPublicId($seriesPublicId);
            }
            $seriesCategoryPublicId = AzureSqlValueMapper::nullableString($rows[0]['CategoryPublicId'] ?? null);

            if ($categoryPublicId !== null && $seriesCategoryPublicId !== $categoryPublicId) {
                throw InvalidProductClassification::invalidHierarchy(
                    sprintf('Series "%s" does not belong to category "%s".', $seriesPublicId, $categoryPublicId),
                );
            }
        }
    }

    private function requireFamilyId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductFamilyId] FROM [product].[ProductFamilies] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Product family', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductFamilyId'] ?? 0);
    }

    private function requireCategoryId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductCategoryId] FROM [product].[ProductCategories] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Product category', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductCategoryId'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateLookup(array $row, ?string $parentPublicId = null): ProductLookupItem
    {
        return new ProductLookupItem(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            parentPublicId: AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null) ?? $parentPublicId,
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
        );
    }
}
