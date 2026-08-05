<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Contract\ProductMarketRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Azure SQL repository for market lookups and product market assignments.
 * Tables: product.Regions, product.Markets, product.Industries, product.Applications,
 * product.ProductRegions, product.ProductMarkets, product.ProductIndustries, product.ProductApplications.
 */
final class AzureSqlProductMarketRepository implements ProductMarketRepository
{
    use AzureSqlTransaction;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listRegions(bool $activeOnly = true): array
    {
        return $this->listLookup(
            table: '[product].[Regions]',
            codeColumn: '[RegionCode]',
            nameColumn: '[RegionName]',
            activeOnly: $activeOnly,
        );
    }

    public function listMarkets(?string $regionPublicId = null, bool $activeOnly = true): array
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), m.[PublicId]) AS [PublicId],
    m.[MarketCode] AS [Code],
    m.[MarketName] AS [Name],
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [ParentPublicId],
    m.[DisplayOrder],
    m.[IsActive]
FROM [product].[Markets] m
LEFT JOIN [product].[Regions] r ON r.[RegionId] = m.[RegionId]
WHERE m.[DeletedAtUtc] IS NULL
SQL;
        $params = [];
        if ($regionPublicId !== null) {
            $sql .= ' AND r.[PublicId] = :regionPublicId';
            $params[':regionPublicId'] = $regionPublicId;
        }
        if ($activeOnly) {
            $sql .= ' AND m.[IsActive] = 1';
        }
        $sql .= ' ORDER BY m.[DisplayOrder], m.[MarketName]';

        return array_map(
            fn (array $row): ProductLookupItem => $this->hydrateLookup($row),
            $this->connection->fetchAll($sql, $params),
        );
    }

    public function listIndustries(bool $activeOnly = true): array
    {
        return $this->listLookup(
            table: '[product].[Industries]',
            codeColumn: '[IndustryCode]',
            nameColumn: '[IndustryName]',
            activeOnly: $activeOnly,
        );
    }

    public function listApplications(bool $activeOnly = true): array
    {
        return $this->listLookup(
            table: '[product].[Applications]',
            codeColumn: '[ApplicationCode]',
            nameColumn: '[ApplicationName]',
            activeOnly: $activeOnly,
        );
    }

    public function replaceProductRegions(
        ProductPublicId $productPublicId,
        array $regionPublicIds,
        UserId $actorUserId,
    ): void {
        $this->replaceSoftDeletedLinks(
            productPublicId: $productPublicId,
            linkTable: '[product].[ProductRegions]',
            linkIdColumn: '[ProductRegionId]',
            targetTable: '[product].[Regions]',
            targetIdColumn: '[RegionId]',
            targetPublicIds: $regionPublicIds,
            actorUserId: $actorUserId,
        );
    }

    public function replaceProductMarkets(
        ProductPublicId $productPublicId,
        array $marketPublicIds,
        UserId $actorUserId,
    ): void {
        $this->replaceSoftDeletedLinks(
            productPublicId: $productPublicId,
            linkTable: '[product].[ProductMarkets]',
            linkIdColumn: '[ProductMarketId]',
            targetTable: '[product].[Markets]',
            targetIdColumn: '[MarketId]',
            targetPublicIds: $marketPublicIds,
            actorUserId: $actorUserId,
        );
    }

    public function replaceProductIndustries(
        ProductPublicId $productPublicId,
        array $industryPublicIds,
        UserId $actorUserId,
    ): void {
        $this->replaceHardDeletedLinks(
            productPublicId: $productPublicId,
            linkTable: '[product].[ProductIndustries]',
            targetTable: '[product].[Industries]',
            targetIdColumn: '[IndustryId]',
            targetPublicIds: $industryPublicIds,
            actorUserId: $actorUserId,
        );
    }

    public function replaceProductApplications(
        ProductPublicId $productPublicId,
        array $applicationPublicIds,
        UserId $actorUserId,
    ): void {
        $this->replaceHardDeletedLinks(
            productPublicId: $productPublicId,
            linkTable: '[product].[ProductApplications]',
            targetTable: '[product].[Applications]',
            targetIdColumn: '[ApplicationId]',
            targetPublicIds: $applicationPublicIds,
            actorUserId: $actorUserId,
        );
    }

    /**
     * @return list<ProductLookupItem>
     */
    private function listLookup(
        string $table,
        string $codeColumn,
        string $nameColumn,
        bool $activeOnly,
    ): array {
        $sql = sprintf(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), t.[PublicId]) AS [PublicId],
    t.%s AS [Code],
    t.%s AS [Name],
    t.[DisplayOrder],
    t.[IsActive]
FROM %s t
WHERE t.[DeletedAtUtc] IS NULL
SQL,
            $codeColumn,
            $nameColumn,
            $table,
        );
        if ($activeOnly) {
            $sql .= ' AND t.[IsActive] = 1';
        }
        $sql .= sprintf(' ORDER BY t.[DisplayOrder], t.%s', $nameColumn);

        return array_map(
            fn (array $row): ProductLookupItem => $this->hydrateLookup($row),
            $this->connection->fetchAll($sql),
        );
    }

    /**
     * @param list<string> $targetPublicIds
     */
    private function replaceSoftDeletedLinks(
        ProductPublicId $productPublicId,
        string $linkTable,
        string $linkIdColumn,
        string $targetTable,
        string $targetIdColumn,
        array $targetPublicIds,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use (
                $productPublicId,
                $linkTable,
                $targetTable,
                $targetIdColumn,
                $targetPublicIds,
                $actorUserId,
            ): void {
                $productId = $this->requireProductId($productPublicId);

                // ProductRegions / ProductMarkets unique keys are not soft-delete filtered.
                // Hard-delete current links before insert to avoid UQ collisions on reassignment.
                $this->connection->execute(
                    sprintf('DELETE FROM %s WHERE [ProductId] = :productId', $linkTable),
                    [':productId' => $productId],
                );

                $displayOrder = 0;
                foreach ($targetPublicIds as $targetPublicId) {
                    ++$displayOrder;
                    $targetId = $this->requireTargetId($targetTable, $targetIdColumn, $targetPublicId);
                    $this->connection->execute(
                        sprintf(
                            <<<'SQL'
INSERT INTO %s (
    [ProductId],
    [%s],
    [CreatedByUserId],
    [UpdatedByUserId]
)
VALUES (
    :productId,
    :targetId,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                            $linkTable,
                            trim($targetIdColumn, '[]'),
                        ),
                        [
                            ':productId' => $productId,
                            ':targetId' => $targetId,
                            ':createdByUserId' => $actorUserId->toInt(),
                            ':updatedByUserId' => $actorUserId->toInt(),
                        ],
                    );
                }
            },
        );
    }

    /**
     * @param list<string> $targetPublicIds
     */
    private function replaceHardDeletedLinks(
        ProductPublicId $productPublicId,
        string $linkTable,
        string $targetTable,
        string $targetIdColumn,
        array $targetPublicIds,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use (
                $productPublicId,
                $linkTable,
                $targetTable,
                $targetIdColumn,
                $targetPublicIds,
                $actorUserId,
            ): void {
                $productId = $this->requireProductId($productPublicId);

                $this->connection->execute(
                    sprintf('DELETE FROM %s WHERE [ProductId] = :productId', $linkTable),
                    [':productId' => $productId],
                );

                $displayOrder = 0;
                foreach ($targetPublicIds as $targetPublicId) {
                    ++$displayOrder;
                    $targetId = $this->requireTargetId($targetTable, $targetIdColumn, $targetPublicId);
                    $this->connection->execute(
                        sprintf(
                            <<<'SQL'
INSERT INTO %s (
    [ProductId],
    [%s],
    [DisplayOrder],
    [CreatedByUserId]
)
VALUES (
    :productId,
    :targetId,
    :displayOrder,
    :createdByUserId
)
SQL,
                            $linkTable,
                            trim($targetIdColumn, '[]'),
                        ),
                        [
                            ':productId' => $productId,
                            ':targetId' => $targetId,
                            ':displayOrder' => $displayOrder,
                            ':createdByUserId' => $actorUserId->toInt(),
                        ],
                    );
                }
            },
        );
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

    private function requireTargetId(string $table, string $idColumn, string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            sprintf(
                'SELECT %s AS [TargetId] FROM %s WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
                $idColumn,
                $table,
            ),
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Lookup item', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['TargetId'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateLookup(array $row): ProductLookupItem
    {
        return new ProductLookupItem(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            parentPublicId: AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
        );
    }
}
