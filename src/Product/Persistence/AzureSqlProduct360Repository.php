<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\Product360FrameSummary;
use TriCab\Core\Product\Application\DTO\Product360SetSummary;
use TriCab\Core\Product\Contract\Product360Repository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

final class AzureSqlProduct360Repository implements Product360Repository
{
    use AzureSqlTransaction;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findSetByPublicId(string $setPublicId): ?Product360SetSummary
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    s.[Title],
    s.[FrameCount] AS [ExpectedFrameCount],
    s.[ActivationStatusCode] AS [StatusCode],
    s.[IsActive]
FROM [product].[Product360Sets] s
INNER JOIN [product].[Products] p ON p.[ProductId] = s.[ProductId]
WHERE s.[PublicId] = :setPublicId
  AND s.[DeletedAtUtc] IS NULL
SQL,
            [':setPublicId' => $setPublicId],
        );

        return $rows === [] ? null : $this->hydrateSet($rows[0]);
    }

    public function listSetsByProduct(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    s.[Title],
    s.[FrameCount] AS [ExpectedFrameCount],
    s.[ActivationStatusCode] AS [StatusCode],
    s.[IsActive]
FROM [product].[Product360Sets] s
INNER JOIN [product].[Products] p ON p.[ProductId] = s.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND s.[DeletedAtUtc] IS NULL
ORDER BY s.[DisplayOrder], s.[Title]
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        return array_map(fn (array $row): Product360SetSummary => $this->hydrateSet($row), $rows);
    }

    public function createSet(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): Product360SetSummary {
        return $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $data, $actorUserId): Product360SetSummary {
                $productId = $this->requireProductId($productPublicId);
                $mediaTypeId = $this->requireMediaTypeId('360-set');

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [product].[Product360Sets] (
    [ProductId],
    [MediaTypeId],
    [Title],
    [DisplayOrder],
    [FrameCount],
    [ActivationStatusCode],
    [IsActive],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :productId,
    :mediaTypeId,
    :title,
    :displayOrder,
    :frameCount,
    N'draft',
    0,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                    [
                        ':productId' => $productId,
                        ':mediaTypeId' => $mediaTypeId,
                        ':title' => $data['title'],
                        ':displayOrder' => $data['displayOrder'] ?? 0,
                        ':frameCount' => $data['expectedFrameCount'],
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                if ($rows === []) {
                    throw ProductPersistenceException::insertFailed('Product360Set');
                }

                $loaded = $this->findSetByPublicId(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
                if ($loaded === null) {
                    throw ProductPersistenceException::insertFailed('Product360Set');
                }

                return $loaded;
            },
        );
    }

    public function createFrameUploadSession(
        string $setPublicId,
        array $data,
        UserId $actorUserId,
    ): Product360FrameSummary {
        $setId = $this->requireSetId($setPublicId);
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[Product360Frames] (
    [Product360SetId],
    [FrameIndex],
    [StorageContainer],
    [StoragePath],
    [ContentType],
    [ByteSize],
    [OriginalFileName],
    [UploadStatusCode],
    [ScanStatusCode],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :setId,
    :frameIndex,
    :storageContainer,
    :storagePath,
    :contentType,
    :byteSize,
    :originalFileName,
    :uploadStatusCode,
    :scanStatusCode,
    :createdByUserId,
    :updatedByUserId
)
SQL,
            [
                ':setId' => $setId,
                ':frameIndex' => $data['frameNumber'],
                ':storageContainer' => $data['storageContainer'],
                ':storagePath' => $data['storagePath'],
                ':contentType' => $data['contentType'],
                ':byteSize' => $data['byteSize'],
                ':originalFileName' => $data['originalFileName'],
                ':uploadStatusCode' => $data['uploadStatusCode'],
                ':scanStatusCode' => $data['scanStatusCode'],
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );

        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('Product360Frame');
        }

        $loaded = $this->findFrameByPublicId(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
        if ($loaded === null) {
            throw ProductPersistenceException::insertFailed('Product360Frame');
        }

        return $loaded;
    }

    public function findFrameByPublicId(string $framePublicId): ?Product360FrameSummary
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), f.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [SetPublicId],
    f.[FrameIndex] AS [FrameNumber],
    f.[UploadStatusCode],
    f.[ScanStatusCode],
    f.[PublicUrl],
    f.[StoragePath],
    f.[StorageContainer],
    f.[ContentType],
    f.[ByteSize],
    f.[OriginalFileName]
FROM [product].[Product360Frames] f
INNER JOIN [product].[Product360Sets] s ON s.[Product360SetId] = f.[Product360SetId]
WHERE f.[PublicId] = :framePublicId
  AND f.[DeletedAtUtc] IS NULL
SQL,
            [':framePublicId' => $framePublicId],
        );

        return $rows === [] ? null : $this->hydrateFrame($rows[0]);
    }

    public function markFrameUploadCompleted(
        string $framePublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): Product360FrameSummary {
        $this->connection->execute(
            <<<'SQL'
UPDATE [product].[Product360Frames]
SET
    [UploadStatusCode] = :uploadStatusCode,
    [ScanStatusCode] = :scanStatusCode,
    [PublicUrl] = COALESCE(:publicUrl, [PublicUrl]),
    [ContentType] = COALESCE(:contentType, [ContentType]),
    [ByteSize] = COALESCE(:byteSize, [ByteSize]),
    [ETag] = COALESCE(:etag, [ETag]),
    [UploadCompletedAtUtc] = SYSUTCDATETIME(),
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [PublicId] = :framePublicId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':uploadStatusCode' => $blobMeta['uploadStatusCode'],
                ':scanStatusCode' => $blobMeta['scanStatusCode'],
                ':publicUrl' => $blobMeta['publicUrl'] ?? null,
                ':contentType' => $blobMeta['contentType'] ?? null,
                ':byteSize' => $blobMeta['byteSize'] ?? null,
                ':etag' => $blobMeta['etag'] ?? null,
                ':updatedByUserId' => $actorUserId->toInt(),
                ':framePublicId' => $framePublicId,
            ],
        );

        $loaded = $this->findFrameByPublicId($framePublicId);
        if ($loaded === null) {
            throw ProductPersistenceException::referenceNotFound('Product360Frame', $framePublicId);
        }

        return $loaded;
    }

    public function activateSet(string $setPublicId, UserId $actorUserId): Product360SetSummary
    {
        $this->connection->execute(
            <<<'SQL'
UPDATE [product].[Product360Sets]
SET
    [ActivationStatusCode] = N'active',
    [IsActive] = 1,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [PublicId] = :setPublicId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':updatedByUserId' => $actorUserId->toInt(),
                ':setPublicId' => $setPublicId,
            ],
        );

        $loaded = $this->findSetByPublicId($setPublicId);
        if ($loaded === null) {
            throw ProductPersistenceException::referenceNotFound('Product360Set', $setPublicId);
        }

        return $loaded;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSet(array $row): Product360SetSummary
    {
        $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        $frames = $this->listFrames($publicId);
        $uploaded = count(array_filter(
            $frames,
            static fn (Product360FrameSummary $f): bool => $f->uploadStatusCode === UploadStatusCode::ACTIVE
                || $f->uploadStatusCode === UploadStatusCode::UPLOADED,
        ));

        return new Product360SetSummary(
            publicId: $publicId,
            productPublicId: AzureSqlValueMapper::stringValue($row['ProductPublicId'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            expectedFrameCount: AzureSqlValueMapper::intValue($row['ExpectedFrameCount'] ?? 0),
            uploadedFrameCount: $uploaded,
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? 'draft'),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            frames: $frames,
        );
    }

    /**
     * @return list<Product360FrameSummary>
     */
    private function listFrames(string $setPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), f.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [SetPublicId],
    f.[FrameIndex] AS [FrameNumber],
    f.[UploadStatusCode],
    f.[ScanStatusCode],
    f.[PublicUrl],
    f.[StoragePath],
    f.[StorageContainer],
    f.[ContentType],
    f.[ByteSize],
    f.[OriginalFileName]
FROM [product].[Product360Frames] f
INNER JOIN [product].[Product360Sets] s ON s.[Product360SetId] = f.[Product360SetId]
WHERE s.[PublicId] = :setPublicId
  AND f.[DeletedAtUtc] IS NULL
ORDER BY f.[FrameIndex]
SQL,
            [':setPublicId' => $setPublicId],
        );

        return array_map(fn (array $row): Product360FrameSummary => $this->hydrateFrame($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateFrame(array $row): Product360FrameSummary
    {
        return new Product360FrameSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            setPublicId: AzureSqlValueMapper::stringValue($row['SetPublicId'] ?? ''),
            frameNumber: AzureSqlValueMapper::intValue($row['FrameNumber'] ?? 0),
            uploadStatusCode: AzureSqlValueMapper::stringValue($row['UploadStatusCode'] ?? UploadStatusCode::ACTIVE),
            scanStatusCode: AzureSqlValueMapper::stringValue($row['ScanStatusCode'] ?? 'not_required'),
            publicUrl: AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
            storagePath: AzureSqlValueMapper::nullableString($row['StoragePath'] ?? null),
            storageContainer: AzureSqlValueMapper::nullableString($row['StorageContainer'] ?? null),
            contentType: AzureSqlValueMapper::nullableString($row['ContentType'] ?? null),
            byteSize: isset($row['ByteSize']) && $row['ByteSize'] !== null ? AzureSqlValueMapper::intValue($row['ByteSize']) : null,
            originalFileName: AzureSqlValueMapper::nullableString($row['OriginalFileName'] ?? null),
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

    private function requireSetId(string $setPublicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [Product360SetId] FROM [product].[Product360Sets] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $setPublicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Product360Set', $setPublicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['Product360SetId'] ?? 0);
    }

    private function requireMediaTypeId(string $code): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [MediaTypeId] FROM [product].[MediaTypes] WHERE [MediaTypeCode] = :code AND [DeletedAtUtc] IS NULL',
            [':code' => $code],
        );
        if ($rows === []) {
            throw ProductPersistenceException::lookupNotFound('Media type', $code);
        }

        return AzureSqlValueMapper::intValue($rows[0]['MediaTypeId'] ?? 0);
    }
}
