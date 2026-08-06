<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductRelationshipRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductRelationshipType;

/**
 * Azure SQL repository for product relationships.
 * Tables: ProductAccessories, RelatedProducts, ReplacementProducts, ProductCrossSells, ProductUpSells.
 */
final class AzureSqlProductRelationshipRepository implements ProductRelationshipRepository
{
    use AzureSqlTransaction;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listByProduct(
        ProductPublicId $productPublicId,
        ProductRelationshipType $type,
    ): array {
        $mapping = $this->tableMapping($type);

        if ($type->equals(ProductRelationshipType::accessory())) {
            $sql = sprintf(
                <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), rp.[PublicId]) AS [RelatedProductPublicId],
    rp.[ProductName] AS [RelatedProductName],
    l.[DisplayOrder],
    l.[Notes],
    l.[AccessoryRequirementCode]
FROM %s l
INNER JOIN [product].[Products] p ON p.[ProductId] = l.[ProductId]
INNER JOIN [product].[Products] rp ON rp.[ProductId] = l.[RelatedProductId]
WHERE p.[PublicId] = :productPublicId
  AND l.[DeletedAtUtc] IS NULL
  AND rp.[DeletedAtUtc] IS NULL
ORDER BY l.[DisplayOrder], rp.[ProductName]
SQL,
                $mapping['table'],
            );
        } else {
            $sql = sprintf(
                <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), rp.[PublicId]) AS [RelatedProductPublicId],
    rp.[ProductName] AS [RelatedProductName],
    l.[DisplayOrder],
    l.[Notes]
FROM %s l
INNER JOIN [product].[Products] p ON p.[ProductId] = l.[ProductId]
INNER JOIN [product].[Products] rp ON rp.[ProductId] = l.[RelatedProductId]
WHERE p.[PublicId] = :productPublicId
  AND l.[DeletedAtUtc] IS NULL
  AND rp.[DeletedAtUtc] IS NULL
ORDER BY l.[DisplayOrder], rp.[ProductName]
SQL,
                $mapping['table'],
            );
        }

        $rows = $this->connection->fetchAll($sql, [':productPublicId' => $productPublicId->toString()]);

        return array_map(
            function (array $row) use ($type): array {
                $item = [
                    'relatedProductPublicId' => AzureSqlValueMapper::stringValue($row['RelatedProductPublicId'] ?? ''),
                    'relatedProductName' => AzureSqlValueMapper::stringValue($row['RelatedProductName'] ?? ''),
                    'displayOrder' => AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                    'notes' => AzureSqlValueMapper::nullableString($row['Notes'] ?? null),
                ];
                if ($type->equals(ProductRelationshipType::accessory())) {
                    $item['accessoryRequirementCode'] = AzureSqlValueMapper::nullableString(
                        $row['AccessoryRequirementCode'] ?? null,
                    );
                }

                return $item;
            },
            $rows,
        );
    }

    public function replace(
        ProductPublicId $productPublicId,
        ProductRelationshipType $type,
        array $items,
        UserId $actorUserId,
    ): void {
        $mapping = $this->tableMapping($type);

        $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $type, $items, $actorUserId, $mapping): void {
                $productId = $this->requireProductId($productPublicId);

                $this->connection->execute(
                    sprintf(
                        <<<'SQL'
UPDATE l
SET
    l.[DeletedAtUtc] = SYSUTCDATETIME(),
    l.[DeletedByUserId] = :deletedByUserId,
    l.[UpdatedAtUtc] = SYSUTCDATETIME(),
    l.[UpdatedByUserId] = :updatedByUserId
FROM %s l
WHERE l.[ProductId] = :productId
  AND l.[DeletedAtUtc] IS NULL
SQL,
                        $mapping['table'],
                    ),
                    [
                        ':productId' => $productId,
                        ':deletedByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                foreach ($items as $item) {
                    $relatedPublicId = $item['relatedProductPublicId'];
                    if ($relatedPublicId === $productPublicId->toString()) {
                        throw ProductPersistenceException::invalidRelationship(
                            'A product cannot be related to itself.',
                        );
                    }

                    $relatedProductId = $this->requireRelatedProductId($relatedPublicId);

                    $params = [
                        ':productId' => $productId,
                        ':relatedProductId' => $relatedProductId,
                        ':displayOrder' => $item['displayOrder'],
                        ':notes' => $item['notes'] ?? null,
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ];

                    if ($type->equals(ProductRelationshipType::accessory())) {
                        $this->connection->execute(
                            sprintf(
                                <<<'SQL'
INSERT INTO %s (
    [ProductId],
    [RelatedProductId],
    [DisplayOrder],
    [Notes],
    [AccessoryRequirementCode],
    [CreatedByUserId],
    [UpdatedByUserId]
)
VALUES (
    :productId,
    :relatedProductId,
    :displayOrder,
    :notes,
    :accessoryRequirementCode,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                                $mapping['table'],
                            ),
                            [
                                ...$params,
                                ':accessoryRequirementCode' => $item['accessoryRequirementCode'] ?? 'optional',
                            ],
                        );
                    } else {
                        $this->connection->execute(
                            sprintf(
                                <<<'SQL'
INSERT INTO %s (
    [ProductId],
    [RelatedProductId],
    [DisplayOrder],
    [Notes],
    [CreatedByUserId],
    [UpdatedByUserId]
)
VALUES (
    :productId,
    :relatedProductId,
    :displayOrder,
    :notes,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                                $mapping['table'],
                            ),
                            $params,
                        );
                    }
                }
            },
        );
    }

    /**
     * @return array{table: string}
     */
    private function tableMapping(ProductRelationshipType $type): array
    {
        return match ($type->toString()) {
            ProductRelationshipType::ACCESSORY => ['table' => '[product].[ProductAccessories]'],
            ProductRelationshipType::RELATED => ['table' => '[product].[RelatedProducts]'],
            ProductRelationshipType::REPLACEMENT => ['table' => '[product].[ReplacementProducts]'],
            ProductRelationshipType::CROSS_SELL => ['table' => '[product].[ProductCrossSells]'],
            ProductRelationshipType::UP_SELL => ['table' => '[product].[ProductUpSells]'],
            default => throw ProductPersistenceException::operationFailed(
                sprintf('Unsupported relationship type "%s".', $type->toString()),
            ),
        };
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

    private function requireRelatedProductId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::invalidRelationship(
                sprintf('Related product "%s" was not found or is deleted.', $publicId),
            );
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductId'] ?? 0);
    }
}
