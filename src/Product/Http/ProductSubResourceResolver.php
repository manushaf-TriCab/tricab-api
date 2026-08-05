<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Http;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\InMemory\InMemoryProductStore;
use TriCab\Core\Product\Application\Exception\ProductNotFound;

/**
 * Resolves a product public id from standalone media/document route placeholders.
 */
final class ProductSubResourceResolver
{
    public function __construct(
        private readonly ?InMemoryProductStore $memoryStore,
        private readonly ?SqlConnection $connection,
    ) {
    }

    public function requireProductPublicIdForMedia(string $mediaPublicId): string
    {
        $productPublicId = $this->productPublicIdForMedia($mediaPublicId);
        if ($productPublicId === null) {
            throw ProductNotFound::forPublicId($mediaPublicId);
        }

        return $productPublicId;
    }

    public function requireProductPublicIdForDocument(string $documentPublicId): string
    {
        $productPublicId = $this->productPublicIdForDocument($documentPublicId);
        if ($productPublicId === null) {
            throw ProductNotFound::forPublicId($documentPublicId);
        }

        return $productPublicId;
    }

    public function productPublicIdForMedia(string $mediaPublicId): ?string
    {
        if ($this->memoryStore !== null) {
            return $this->memoryStore->findProductPublicIdForMedia($mediaPublicId);
        }

        if ($this->connection === null) {
            return null;
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId]
FROM [product].[ProductImages] i
INNER JOIN [product].[Products] p ON p.[ProductId] = i.[ProductId]
WHERE i.[PublicId] = :mediaPublicId
  AND i.[DeletedAtUtc] IS NULL
  AND p.[DeletedAtUtc] IS NULL
SQL,
            [':mediaPublicId' => $mediaPublicId],
        );

        if ($rows === []) {
            return null;
        }

        $value = AzureSqlValueMapper::stringValue($rows[0]['ProductPublicId'] ?? '');

        return $value === '' ? null : $value;
    }

    public function productPublicIdForDocument(string $documentPublicId): ?string
    {
        if ($this->memoryStore !== null) {
            return $this->memoryStore->findProductPublicIdForDocument($documentPublicId);
        }

        if ($this->connection === null) {
            return null;
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId]
FROM [product].[ProductDocuments] d
INNER JOIN [product].[Products] p ON p.[ProductId] = d.[ProductId]
WHERE d.[PublicId] = :documentPublicId
  AND d.[DeletedAtUtc] IS NULL
  AND p.[DeletedAtUtc] IS NULL
SQL,
            [':documentPublicId' => $documentPublicId],
        );

        if ($rows === []) {
            return null;
        }

        $value = AzureSqlValueMapper::stringValue($rows[0]['ProductPublicId'] ?? '');

        return $value === '' ? null : $value;
    }
}
