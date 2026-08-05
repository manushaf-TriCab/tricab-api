<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductAuditEntry;
use TriCab\Core\Product\Contract\ProductAuditRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Azure SQL audit trail for product bounded-context changes.
 * Table: product.ProductAuditEvents.
 */
final class AzureSqlProductAuditRepository implements ProductAuditRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));

        $rows = $this->connection->fetchAll(
            sprintf(
                <<<'SQL'
SELECT TOP (%d)
    CONVERT(NVARCHAR(36), ae.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    ae.[EntityTypeCode],
    ae.[EntityId],
    ae.[ActionCode],
    u.[DisplayName] AS [ActorDisplayName],
    ae.[CorrelationId],
    ae.[SourceCode],
    ae.[PreviousValuesJson],
    ae.[NewValuesJson],
    CONVERT(VARCHAR(33), ae.[CreatedAtUtc], 127) AS [CreatedAtUtc]
FROM [product].[ProductAuditEvents] ae
INNER JOIN [product].[Products] p ON p.[ProductId] = ae.[ProductId]
LEFT JOIN [identity].[Users] u ON u.[UserId] = ae.[ActorUserId]
WHERE p.[PublicId] = :productPublicId
ORDER BY ae.[CreatedAtUtc] DESC, ae.[ProductAuditEventId] DESC
SQL,
                $limit,
            ),
            [':productPublicId' => $productPublicId->toString()],
        );

        return array_map(fn (array $row): ProductAuditEntry => $this->hydrate($row), $rows);
    }

    public function record(
        ?ProductPublicId $productPublicId,
        string $entityTypeCode,
        ?int $entityId,
        string $actionCode,
        ?UserId $actorUserId,
        ?string $correlationId,
        ?string $sourceCode,
        ?array $previousValues,
        ?array $newValues,
    ): void {
        $productId = null;
        if ($productPublicId !== null) {
            $productId = $this->requireProductId($productPublicId);
        }

        $this->connection->execute(
            <<<'SQL'
INSERT INTO [product].[ProductAuditEvents] (
    [ProductId],
    [EntityTypeCode],
    [EntityId],
    [ActionCode],
    [ActorUserId],
    [CorrelationId],
    [SourceCode],
    [PreviousValuesJson],
    [NewValuesJson]
)
VALUES (
    :productId,
    :entityTypeCode,
    :entityId,
    :actionCode,
    :actorUserId,
    :correlationId,
    :sourceCode,
    :previousValuesJson,
    :newValuesJson
)
SQL,
            [
                ':productId' => $productId,
                ':entityTypeCode' => $entityTypeCode,
                ':entityId' => $entityId,
                ':actionCode' => $actionCode,
                ':actorUserId' => $actorUserId?->toInt(),
                ':correlationId' => $correlationId,
                ':sourceCode' => $sourceCode,
                ':previousValuesJson' => $previousValues !== null ? json_encode($previousValues, JSON_THROW_ON_ERROR) : null,
                ':newValuesJson' => $newValues !== null ? json_encode($newValues, JSON_THROW_ON_ERROR) : null,
            ],
        );
    }

    private function requireProductId(ProductPublicId $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId->toString()],
        );
        if ($rows === []) {
            throw ProductPersistenceException::productNotFound($publicId->toString());
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductId'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProductAuditEntry
    {
        return new ProductAuditEntry(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            productPublicId: AzureSqlValueMapper::nullableString($row['ProductPublicId'] ?? null),
            entityTypeCode: AzureSqlValueMapper::stringValue($row['EntityTypeCode'] ?? ''),
            entityId: AzureSqlValueMapper::intValue($row['EntityId'] ?? 0) ?: null,
            actionCode: AzureSqlValueMapper::stringValue($row['ActionCode'] ?? ''),
            actorDisplayName: AzureSqlValueMapper::nullableString($row['ActorDisplayName'] ?? null),
            correlationId: AzureSqlValueMapper::nullableString($row['CorrelationId'] ?? null),
            sourceCode: AzureSqlValueMapper::nullableString($row['SourceCode'] ?? null),
            previousValuesJson: AzureSqlValueMapper::nullableString($row['PreviousValuesJson'] ?? null),
            newValuesJson: AzureSqlValueMapper::nullableString($row['NewValuesJson'] ?? null),
            createdAtUtc: AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
        );
    }
}
