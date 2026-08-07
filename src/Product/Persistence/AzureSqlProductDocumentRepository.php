<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductDocumentFileRecord;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

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
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    dt.[DocumentTypeCode],
    d.[Title],
    d.[VersionLabel],
    d.[LanguageCode],
    d.[PublicUrl],
    d.[AccessCode],
    d.[DisplayOrder],
    d.[UploadStatusCode],
    d.[ScanStatusCode],
    d.[ByteSize],
    d.[ContentType],
    d.[OriginalFileName],
    d.[FileExtension],
    d.[StoragePath],
    d.[StorageContainer],
    d.[ChecksumSha256]
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
                $loaded = $this->findSummaryByPublicId($publicId);
                if ($loaded === null) {
                    throw ProductPersistenceException::insertFailed('ProductDocument');
                }

                return $loaded;
            },
        );
    }


    public function findByPublicId(string $documentPublicId): ?ProductDocumentFileRecord
    {
        $rows = $this->connection->fetchAll(
            self::LIST_SELECT_SQL . ' WHERE d.[PublicId] = :publicId AND d.[DeletedAtUtc] IS NULL',
            [':publicId' => $documentPublicId],
        );

        return $rows === [] ? null : $this->hydrateRecord($rows[0]);
    }

    public function createUploadSession(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductDocumentFileRecord {
        return $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $data, $actorUserId): ProductDocumentFileRecord {
                $productId = $this->requireProductId($productPublicId);
                $documentTypeId = $this->requireDocumentTypeId($data['documentTypeCode']);
                $publicId = $data['publicId'] ?? null;

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [product].[ProductDocuments] (
    [PublicId],
    [ProductId],
    [DocumentTypeId],
    [Title],
    [VersionLabel],
    [LanguageCode],
    [StorageContainer],
    [StoragePath],
    [AccessCode],
    [ContentType],
    [FileExtension],
    [ByteSize],
    [OriginalFileName],
    [DisplayOrder],
    [UploadStatusCode],
    [ScanStatusCode],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    CONVERT(UNIQUEIDENTIFIER, :publicId),
    :productId,
    :documentTypeId,
    :title,
    :versionLabel,
    :languageCode,
    :storageContainer,
    :storagePath,
    :accessCode,
    :contentType,
    :fileExtension,
    :byteSize,
    :originalFileName,
    :displayOrder,
    :uploadStatusCode,
    :scanStatusCode,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                    [
                        ':publicId' => $publicId,
                        ':productId' => $productId,
                        ':documentTypeId' => $documentTypeId,
                        ':title' => $data['title'],
                        ':versionLabel' => $data['versionLabel'] ?? null,
                        ':languageCode' => $data['languageCode'] ?? null,
                        ':storageContainer' => $data['storageContainer'],
                        ':storagePath' => $data['storagePath'],
                        ':accessCode' => $data['accessCode'],
                        ':contentType' => $data['contentType'],
                        ':fileExtension' => $data['fileExtension'],
                        ':byteSize' => $data['byteSize'],
                        ':originalFileName' => $data['originalFileName'],
                        ':displayOrder' => $data['displayOrder'] ?? 0,
                        ':uploadStatusCode' => $data['uploadStatusCode'],
                        ':scanStatusCode' => $data['scanStatusCode'],
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                if ($rows === []) {
                    throw ProductPersistenceException::insertFailed('ProductDocument');
                }

                $insertedPublicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
                $loaded = $this->findByPublicId($insertedPublicId);
                if ($loaded === null) {
                    throw ProductPersistenceException::insertFailed('ProductDocument');
                }

                return $loaded;
            },
        );
    }

    public function markUploadCompleted(
        string $documentPublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): ProductDocumentFileRecord {
        $this->connection->execute(
            <<<'SQL'
UPDATE [product].[ProductDocuments]
SET
    [UploadStatusCode] = :uploadStatusCode,
    [ScanStatusCode] = :scanStatusCode,
    [PublicUrl] = COALESCE(:publicUrl, [PublicUrl]),
    [ContentType] = COALESCE(:contentType, [ContentType]),
    [ByteSize] = COALESCE(:byteSize, [ByteSize]),
    [ETag] = COALESCE(:etag, [ETag]),
    [StoragePath] = CASE
        WHEN :storagePath IS NOT NULL THEN :storagePath
        WHEN LEFT(ISNULL([ChecksumSha256], N''), 8) = N'pending:' THEN SUBSTRING([ChecksumSha256], 9, 1000)
        ELSE [StoragePath]
    END,
    [StorageContainer] = COALESCE(:storageContainer, [StorageContainer]),
    [ChecksumSha256] = NULL,
    [UploadCompletedAtUtc] = SYSUTCDATETIME(),
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [PublicId] = :documentPublicId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':uploadStatusCode' => $blobMeta['uploadStatusCode'],
                ':scanStatusCode' => $blobMeta['scanStatusCode'],
                ':publicUrl' => $blobMeta['publicUrl'] ?? null,
                ':contentType' => $blobMeta['contentType'] ?? null,
                ':byteSize' => $blobMeta['byteSize'] ?? null,
                ':etag' => $blobMeta['etag'] ?? null,
                ':storagePath' => $blobMeta['storagePath'] ?? null,
                ':storageContainer' => $blobMeta['storageContainer'] ?? null,
                ':updatedByUserId' => $actorUserId->toInt(),
                ':documentPublicId' => $documentPublicId,
            ],
        );

        $loaded = $this->findByPublicId($documentPublicId);
        if ($loaded === null) {
            throw ProductPersistenceException::referenceNotFound('ProductDocument', $documentPublicId);
        }

        return $loaded;
    }

    public function beginReplaceUpload(
        string $documentPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductDocumentFileRecord {
            // Pending path encoded in ChecksumSha256 as pending:{path}; StoragePath stays previous.
        $this->connection->execute(
            <<<'SQL'
UPDATE [product].[ProductDocuments]
SET
    [ChecksumSha256] = CONCAT(N'pending:', :pendingStoragePath),
    [OriginalFileName] = :originalFileName,
    [ContentType] = :contentType,
    [ByteSize] = :byteSize,
    [FileExtension] = :fileExtension,
    [UploadStatusCode] = :uploadStatusCode,
    [ScanStatusCode] = :scanStatusCode,
    [StorageContainer] = COALESCE(:storageContainer, [StorageContainer]),
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [PublicId] = :documentPublicId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':pendingStoragePath' => $data['storagePath'],
                ':originalFileName' => $data['originalFileName'],
                ':contentType' => $data['contentType'],
                ':byteSize' => $data['byteSize'],
                ':fileExtension' => $data['fileExtension'],
                ':uploadStatusCode' => $data['uploadStatusCode'],
                ':scanStatusCode' => $data['scanStatusCode'],
                ':storageContainer' => $data['storageContainer'],
                ':updatedByUserId' => $actorUserId->toInt(),
                ':documentPublicId' => $documentPublicId,
            ],
        );

        $loaded = $this->findByPublicId($documentPublicId);
        if ($loaded === null) {
            throw ProductPersistenceException::referenceNotFound('ProductDocument', $documentPublicId);
        }

        return $loaded;
    }

    public function clearFile(string $documentPublicId, UserId $actorUserId): void
    {
        $this->connection->execute(
            <<<'SQL'
UPDATE [product].[ProductDocuments]
SET
    [PublicUrl] = NULL,
    [StoragePath] = N'__deleted__',
    [ChecksumSha256] = NULL,
    [ByteSize] = NULL,
    [ContentType] = NULL,
    [OriginalFileName] = NULL,
    [FileExtension] = NULL,
    [UploadStatusCode] = N'deleted',
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [PublicId] = :documentPublicId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':updatedByUserId' => $actorUserId->toInt(),
                ':documentPublicId' => $documentPublicId,
            ],
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

    private function findSummaryByPublicId(string $publicId): ?ProductDocumentSummary
    {
        $record = $this->findByPublicId($publicId);

        return $record?->toSummary(true);
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
        return $this->hydrateRecord($row)->toSummary(true);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRecord(array $row): ProductDocumentFileRecord
    {
        $checksum = AzureSqlValueMapper::nullableString($row['ChecksumSha256'] ?? null);
        $pendingPath = null;
        if (is_string($checksum) && str_starts_with($checksum, 'pending:')) {
            $pendingPath = substr($checksum, strlen('pending:'));
        }
        $storagePath = AzureSqlValueMapper::nullableString($row['StoragePath'] ?? null);

        return new ProductDocumentFileRecord(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            productPublicId: AzureSqlValueMapper::stringValue($row['ProductPublicId'] ?? ''),
            documentTypeCode: AzureSqlValueMapper::stringValue($row['DocumentTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            versionLabel: AzureSqlValueMapper::nullableString($row['VersionLabel'] ?? null),
            languageCode: AzureSqlValueMapper::nullableString($row['LanguageCode'] ?? null),
            publicUrl: AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
            accessCode: AzureSqlValueMapper::stringValue($row['AccessCode'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            uploadStatusCode: AzureSqlValueMapper::stringValue($row['UploadStatusCode'] ?? UploadStatusCode::ACTIVE),
            scanStatusCode: AzureSqlValueMapper::stringValue($row['ScanStatusCode'] ?? ScanStatusCode::NOT_REQUIRED),
            byteSize: isset($row['ByteSize']) && $row['ByteSize'] !== null ? AzureSqlValueMapper::intValue($row['ByteSize']) : null,
            contentType: AzureSqlValueMapper::nullableString($row['ContentType'] ?? null),
            originalFileName: AzureSqlValueMapper::nullableString($row['OriginalFileName'] ?? null),
            fileExtension: AzureSqlValueMapper::nullableString($row['FileExtension'] ?? null),
            storagePath: $storagePath,
            storageContainer: AzureSqlValueMapper::nullableString($row['StorageContainer'] ?? null),
            pendingStoragePath: $pendingPath,
            pendingStorageContainer: $pendingPath !== null
                ? AzureSqlValueMapper::nullableString($row['StorageContainer'] ?? null)
                : null,
            previousStoragePath: $pendingPath !== null ? $storagePath : null,
        );
    }
}
