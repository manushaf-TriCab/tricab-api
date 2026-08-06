<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductClassificationRecord;
use TriCab\Core\Product\Application\DTO\ProductClassificationUsage;
use TriCab\Core\Product\Application\Exception\ProductClassificationConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductClassificationNotFound;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Admin SQL operations for Family / Category / Series.
 */
final class AzureSqlProductClassificationAdmin
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function find(string $level, string $publicId, bool $includeDeleted): ?ProductClassificationRecord
    {
        $rows = $this->list($level, [
            'publicId' => $publicId,
            'includeDeleted' => $includeDeleted,
        ]);

        return $rows[0] ?? null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<ProductClassificationRecord>
     */
    public function list(string $level, array $filters = []): array
    {
        $meta = $this->meta($level);
        $sql = sprintf(
            'SELECT
                CONVERT(NVARCHAR(36), t.[PublicId]) AS [PublicId],
                t.[%s] AS [Code],
                t.[%s] AS [Name],
                t.[Description],
                t.[DisplayOrder],
                t.[IsActive],
                CASE WHEN t.[DeletedAtUtc] IS NULL THEN 0 ELSE 1 END AS [IsDeleted],
                CONVERT(VARBINARY(8), t.[RowVersion]) AS [RowVersion],
                CONVERT(NVARCHAR(33), t.[CreatedAtUtc], 126) AS [CreatedAtUtc],
                CONVERT(NVARCHAR(33), t.[UpdatedAtUtc], 126) AS [UpdatedAtUtc]',
            $meta['code'],
            $meta['name'],
        );
        if ($meta['parentJoin'] !== null) {
            $sql .= sprintf(
                ', CONVERT(NVARCHAR(36), p.[PublicId]) AS [ParentPublicId], p.[%s] AS [ParentName]',
                $meta['parentName'],
            );
        } else {
            $sql .= ', CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], CAST(NULL AS NVARCHAR(200)) AS [ParentName]';
        }
        $sql .= sprintf(' FROM %s t', $meta['table']);
        if ($meta['parentJoin'] !== null) {
            $sql .= sprintf(
                ' LEFT JOIN %s p ON p.[%s] = t.[%s]',
                $meta['parentJoin'],
                $meta['parentId'],
                $meta['parentCol'],
            );
        }
        $sql .= ' WHERE 1 = 1';
        $params = [];
        if (!($filters['includeDeleted'] ?? false)) {
            $sql .= ' AND t.[DeletedAtUtc] IS NULL';
        }
        if (isset($filters['publicId']) && is_string($filters['publicId'])) {
            $sql .= ' AND t.[PublicId] = :publicId';
            $params[':publicId'] = $filters['publicId'];
        }
        if (isset($filters['parentPublicId']) && is_string($filters['parentPublicId']) && $meta['parentJoin'] !== null) {
            $sql .= ' AND p.[PublicId] = :parentPublicId';
            $params[':parentPublicId'] = $filters['parentPublicId'];
        }
        if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null) {
            $sql .= ' AND t.[IsActive] = :isActive';
            $params[':isActive'] = $filters['activeOnly'] ? 1 : 0;
        }
        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $sql .= sprintf(' AND (t.[%s] LIKE :search OR t.[%s] LIKE :search)', $meta['code'], $meta['name']);
            $params[':search'] = '%' . trim($filters['search']) . '%';
        }
        $sql .= sprintf(' ORDER BY t.[DisplayOrder], t.[%s]', $meta['name']);

        return array_map(
            fn (array $row): ProductClassificationRecord => $this->hydrate($level, $row),
            $this->connection->fetchAll($sql, $params),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(
        string $level,
        string $publicId,
        array $data,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductClassificationRecord {
        $meta = $this->meta($level);
        $sets = [
            '[UpdatedAtUtc] = SYSUTCDATETIME()',
            '[UpdatedByUserId] = :updatedByUserId',
        ];
        $params = [
            ':publicId' => $publicId,
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
        ];
        if (array_key_exists('name', $data)) {
            $sets[] = sprintf('[%s] = :name', $meta['name']);
            $params[':name'] = $data['name'];
        }
        if (array_key_exists('code', $data)) {
            $sets[] = sprintf('[%s] = :code', $meta['code']);
            $params[':code'] = $data['code'];
        }
        if (array_key_exists('description', $data)) {
            $sets[] = '[Description] = :description';
            $params[':description'] = $data['description'];
        }
        if (array_key_exists('displayOrder', $data)) {
            $sets[] = '[DisplayOrder] = :displayOrder';
            $params[':displayOrder'] = $data['displayOrder'];
        }
        if (array_key_exists('isActive', $data)) {
            $sets[] = '[IsActive] = :isActive';
            $params[':isActive'] = $data['isActive'] ? 1 : 0;
        }
        if ($level === 'category' && isset($data['familyPublicId'])) {
            $sets[] = '[ProductFamilyId] = :parentId';
            $params[':parentId'] = $this->resolveParentId('family', (string) $data['familyPublicId']);
        }
        if ($level === 'series' && isset($data['categoryPublicId'])) {
            $sets[] = '[ProductCategoryId] = :parentId';
            $params[':parentId'] = $this->resolveParentId('category', (string) $data['categoryPublicId']);
        }

        $sql = sprintf(
            'UPDATE %s SET %s OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
             WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion',
            $meta['table'],
            implode(', ', $sets),
        );
        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            if ($this->find($level, $publicId, true) === null) {
                throw ProductClassificationNotFound::forPublicId($publicId);
            }
            throw ProductClassificationConcurrencyConflict::forPublicId($publicId);
        }

        $loaded = $this->find($level, $publicId, true);
        if ($loaded === null) {
            throw ProductClassificationNotFound::forPublicId($publicId);
        }

        return $loaded;
    }

    public function softDelete(
        string $level,
        string $publicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductClassificationRecord {
        return $this->update($level, $publicId, [
            // soft delete via dedicated SQL
        ], $expectedRowVersion, $actorUserId);
    }

    public function restore(
        string $level,
        string $publicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductClassificationRecord {
        $meta = $this->meta($level);
        $params = [
            ':publicId' => $publicId,
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
        ];
        $sql = sprintf(
            'UPDATE %s SET
                [DeletedAtUtc] = NULL,
                [DeletedByUserId] = NULL,
                [IsActive] = 1,
                [UpdatedAtUtc] = SYSUTCDATETIME(),
                [UpdatedByUserId] = :updatedByUserId
             OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
             WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion',
            $meta['table'],
        );
        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            throw ProductClassificationConcurrencyConflict::forPublicId($publicId);
        }
        $loaded = $this->find($level, $publicId, true);
        if ($loaded === null) {
            throw ProductClassificationNotFound::forPublicId($publicId);
        }

        return $loaded;
    }

    /**
     * @param list<string> $orderedPublicIds
     */
    public function reorder(string $level, ?string $parentPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $current = $this->find($level, $publicId, false);
            if ($current === null) {
                continue;
            }
            if ($parentPublicId !== null && $current->parentPublicId !== $parentPublicId) {
                continue;
            }
            $this->update($level, $publicId, ['displayOrder' => $order], $current->rowVersion, $actorUserId);
            $order += 10;
        }
    }

    public function usage(string $level, string $publicId): ProductClassificationUsage
    {
        if ($level === 'family') {
            $active = $this->countChildren('[product].[ProductCategories]', 'ProductFamilyId', 'family', $publicId, true);
            $inactive = $this->countChildren('[product].[ProductCategories]', 'ProductFamilyId', 'family', $publicId, false);
            $products = $this->countProducts('ProductFamilyId', 'family', $publicId);

            return new ProductClassificationUsage($publicId, 'family', $products, $active, $inactive);
        }
        if ($level === 'category') {
            $active = $this->countChildren('[product].[ProductSeries]', 'ProductCategoryId', 'category', $publicId, true);
            $inactive = $this->countChildren('[product].[ProductSeries]', 'ProductCategoryId', 'category', $publicId, false);
            $products = $this->countProducts('ProductCategoryId', 'category', $publicId);

            return new ProductClassificationUsage($publicId, 'category', $products, 0, 0, $active, $inactive);
        }
        $products = $this->countProducts('ProductSeriesId', 'series', $publicId);

        return new ProductClassificationUsage($publicId, 'series', $products);
    }

    public function codeExists(string $level, string $code, ?string $excludePublicId): bool
    {
        $meta = $this->meta($level);
        $sql = sprintf(
            'SELECT TOP 1 1 AS [ExistsFlag] FROM %s WHERE [%s] = :code AND [DeletedAtUtc] IS NULL',
            $meta['table'],
            $meta['code'],
        );
        $params = [':code' => $code];
        if ($excludePublicId !== null) {
            $sql .= ' AND [PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    /**
     * Soft-delete with dedicated columns.
     */
    public function softDeleteFixed(
        string $level,
        string $publicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductClassificationRecord {
        $meta = $this->meta($level);
        $params = [
            ':publicId' => $publicId,
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
        ];
        $sql = sprintf(
            'UPDATE %s SET
                [DeletedAtUtc] = SYSUTCDATETIME(),
                [DeletedByUserId] = :updatedByUserId,
                [IsActive] = 0,
                [UpdatedAtUtc] = SYSUTCDATETIME(),
                [UpdatedByUserId] = :updatedByUserId
             OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
             WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion AND [DeletedAtUtc] IS NULL',
            $meta['table'],
        );
        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            throw ProductClassificationConcurrencyConflict::forPublicId($publicId);
        }
        $loaded = $this->find($level, $publicId, true);
        if ($loaded === null) {
            throw ProductClassificationNotFound::forPublicId($publicId);
        }

        return $loaded;
    }

    /**
     * @return array{table:string,code:string,name:string,parentJoin:?string,parentCol:?string,parentId:?string,parentName:?string}
     */
    private function meta(string $level): array
    {
        return match ($level) {
            'family' => [
                'table' => '[product].[ProductFamilies]',
                'code' => 'FamilyCode',
                'name' => 'FamilyName',
                'parentJoin' => null,
                'parentCol' => null,
                'parentId' => null,
                'parentName' => null,
            ],
            'category' => [
                'table' => '[product].[ProductCategories]',
                'code' => 'CategoryCode',
                'name' => 'CategoryName',
                'parentJoin' => '[product].[ProductFamilies]',
                'parentCol' => 'ProductFamilyId',
                'parentId' => 'ProductFamilyId',
                'parentName' => 'FamilyName',
            ],
            default => [
                'table' => '[product].[ProductSeries]',
                'code' => 'SeriesCode',
                'name' => 'SeriesName',
                'parentJoin' => '[product].[ProductCategories]',
                'parentCol' => 'ProductCategoryId',
                'parentId' => 'ProductCategoryId',
                'parentName' => 'CategoryName',
            ],
        };
    }

    private function resolveParentId(string $level, string $publicId): int
    {
        $meta = $this->meta($level);
        $idCol = match ($level) {
            'family' => 'ProductFamilyId',
            'category' => 'ProductCategoryId',
            default => 'ProductSeriesId',
        };
        $rows = $this->connection->fetchAll(
            sprintf('SELECT [%s] AS [Id] FROM %s WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL', $idCol, $meta['table']),
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductClassificationNotFound::forPublicId($publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['Id'] ?? 0);
    }

    private function countChildren(
        string $childTable,
        string $fkCol,
        string $parentLevel,
        string $parentPublicId,
        bool $active,
    ): int {
        $parentMeta = $this->meta($parentLevel);
        $parentIdCol = match ($parentLevel) {
            'family' => 'ProductFamilyId',
            'category' => 'ProductCategoryId',
            default => 'ProductSeriesId',
        };
        $rows = $this->connection->fetchAll(
            sprintf(
                'SELECT COUNT(1) AS [Cnt]
                 FROM %s c
                 INNER JOIN %s p ON p.[%s] = c.[%s]
                 WHERE p.[PublicId] = :publicId AND c.[DeletedAtUtc] IS NULL AND c.[IsActive] = :isActive',
                $childTable,
                $parentMeta['table'],
                $parentIdCol,
                $fkCol,
            ),
            [':publicId' => $parentPublicId, ':isActive' => $active ? 1 : 0],
        );

        return AzureSqlValueMapper::intValue($rows[0]['Cnt'] ?? 0);
    }

    private function countProducts(string $fkCol, string $parentLevel, string $parentPublicId): int
    {
        $parentMeta = $this->meta($parentLevel);
        $parentIdCol = match ($parentLevel) {
            'family' => 'ProductFamilyId',
            'category' => 'ProductCategoryId',
            default => 'ProductSeriesId',
        };
        $rows = $this->connection->fetchAll(
            sprintf(
                'SELECT COUNT(1) AS [Cnt]
                 FROM [product].[Products] prod
                 INNER JOIN %s p ON p.[%s] = prod.[%s]
                 WHERE p.[PublicId] = :publicId AND prod.[DeletedAtUtc] IS NULL',
                $parentMeta['table'],
                $parentIdCol,
                $fkCol,
            ),
            [':publicId' => $parentPublicId],
        );

        return AzureSqlValueMapper::intValue($rows[0]['Cnt'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(string $level, array $row): ProductClassificationRecord
    {
        $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        $usage = $this->usage($level, $publicId);
        $token = ProductRowVersionMapper::toToken($row['RowVersion'] ?? null);

        return new ProductClassificationRecord(
            publicId: $publicId,
            code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['Description'] ?? null),
            parentPublicId: AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null),
            parentName: AzureSqlValueMapper::nullableString($row['ParentName'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            isDeleted: AzureSqlValueMapper::boolValue($row['IsDeleted'] ?? false),
            rowVersion: $token->toBase64(),
            createdAtUtc: AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null),
            productCount: $usage->productCount,
            activeChildCount: match ($level) {
                'family' => $usage->activeCategoryCount,
                'category' => $usage->activeSeriesCount,
                default => 0,
            },
            inactiveChildCount: match ($level) {
                'family' => $usage->inactiveCategoryCount,
                'category' => $usage->inactiveSeriesCount,
                default => 0,
            },
        );
    }
}
