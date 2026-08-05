<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Azure SQL repository for product document metadata.
 * Table: product.ProductDocuments.
 *
 * {@see archive()} soft-deletes document metadata only — it does not change product archive status.
 */
final class AzureSqlProductDocumentRepository implements ProductDocumentRepository
{
    use AzureSqlTransaction;

    private const LIST_SELECT_SQL = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [PublicId],
    dt.[DocumentTypeCode],
    d.[Title],
    d.[VersionLabel],
    d.[LanguageCode],
    d.[PublicUrl],
    d.[AccessCode],
    d.[DisplayOrder]
FROM [product].[ProductDocuments] d
INNER JOIN [product].[DocumentTypes] dt ON dt.[DocumentTypeId] = d.[DocumentTypeId]
INNER JOIN [product].[Products] p ON p.[ProductId] = d.[ProductId]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId, bool $publicOnly = false): array
    {
        $sql = self::LIST_SELECT_SQL . ' WHERE p.[PublicId] = :productPublicId AND d.[DeletedAtUtc] IS NULL';
        if ($publicOnly) {
            $sql .= " AND d.[AccessCode] = N'public'";
        }
        $sql .= ' ORDER BY d.[DisplayOrder], d.[Title]';

        $rows = $this->connection->fetchAll($sql, [':productPublicId' => $productPublicId->toString()]);

        return array_map(fn (array $row): ProductDocumentSummary => $this->hydrate($row), $rows);
    }

    public function create(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductDocumentSummary {
        return $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $data, $actorUserId): ProductDocumentSummary {
                $productId = $this->requireProductId($productPublicId);
                $documentTypeId = $this->requireDocumentTypeId($data['documentTypeCode']);
                $regionId = null;
                if (($data['regionPublicId'] ?? null) !== null) {
                    $regionId = $this->requireRegionId($data['regionPublicId']);
                }
                $marketId = null;
                if (($data['marketPublicId'] ?? null) !== null) {
                    $marketId = $this->requireMarketId($data['marketPublicId']);
                }

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [product].[ProductDocuments] (
    [ProductId],
    [DocumentTypeId],
    [Title],
    [VersionLabel],
    [LanguageCode],
    [RegionId],
    [MarketId],
    [StoragePath],
    [PublicUrl],
    [AccessCode],
    [EffectiveFromUtc],
    [EffectiveToUtc],
    [DisplayOrder],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :productId,
    :documentTypeId,
    :title,
    :versionLabel,
    :languageCode,
    :regionId,
    :marketId,
    :storagePath,
    :publicUrl,
    :accessCode,
    :effectiveFromUtc,
    :effectiveToUtc,
    :displayOrder,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                    [
                        ':productId' => $productId,
                        ':documentTypeId' => $documentTypeId,
                        ':title' => $data['title'],
                        ':versionLabel' => $data['versionLabel'] ?? null,
                        ':languageCode' => $data['languageCode'] ?? null,
                        ':regionId' => $regionId,
                        ':marketId' => $marketId,
                        ':storagePath' => $data['storagePath'],
                        ':publicUrl' => $data['publicUrl'] ?? null,
                        ':accessCode' => $data['accessCode'],
                        ':effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
                        ':effectiveToUtc' => $data['effectiveToUtc'] ?? null,
                        ':displayOrder' => $data['displayOrder'] ?? 0,
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                if ($rows === []) {
                    throw ProductPersistenceException::insertFailed('ProductDocument');
                }

                $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
                $loaded = $this->findByPublicId($publicId);
                if ($loaded === null) {
                    throw ProductPersistenceException::insertFailed('ProductDocument');
                }

                return $loaded;
            },
        );
    }

    public function archive(
        ProductPublicId $productPublicId,
        string $documentPublicId,
        UserId $actorUserId,
    ): void {
        $this->connection->execute(
            <<<'SQL'
UPDATE d
SET
    d.[DeletedAtUtc] = SYSUTCDATETIME(),
    d.[DeletedByUserId] = :deletedByUserId,
    d.[UpdatedAtUtc] = SYSUTCDATETIME(),
    d.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductDocuments] d
INNER JOIN [product].[Products] p ON p.[ProductId] = d.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND d.[PublicId] = :documentPublicId
  AND d.[DeletedAtUtc] IS NULL
SQL,
            [
                ':productPublicId' => $productPublicId->toString(),
                ':documentPublicId' => $documentPublicId,
                ':deletedByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
    }

    private function findByPublicId(string $publicId): ?ProductDocumentSummary
    {
        $rows = $this->connection->fetchAll(
            self::LIST_SELECT_SQL . ' WHERE d.[PublicId] = :publicId AND d.[DeletedAtUtc] IS NULL',
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

    private function requireDocumentTypeId(string $code): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [DocumentTypeId] FROM [product].[DocumentTypes] WHERE [DocumentTypeCode] = :code AND [DeletedAtUtc] IS NULL',
            [':code' => $code],
        );
        if ($rows === []) {
            throw ProductPersistenceException::lookupNotFound('Document type', $code);
        }

        return AzureSqlValueMapper::intValue($rows[0]['DocumentTypeId'] ?? 0);
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
    private function hydrate(array $row): ProductDocumentSummary
    {
        return new ProductDocumentSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            documentTypeCode: AzureSqlValueMapper::stringValue($row['DocumentTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            versionLabel: AzureSqlValueMapper::nullableString($row['VersionLabel'] ?? null),
            languageCode: AzureSqlValueMapper::nullableString($row['LanguageCode'] ?? null),
            publicUrl: AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
            accessCode: AzureSqlValueMapper::stringValue($row['AccessCode'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
        );
    }
}
