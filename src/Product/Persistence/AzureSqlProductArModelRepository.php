<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductArModelSummary;
use TriCab\Core\Product\Contract\ProductArModelRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class AzureSqlProductArModelRepository implements ProductArModelRepository
{
    use AzureSqlTransaction;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findByPublicId(string $arPublicId): ?ProductArModelSummary
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), a.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    a.[Title],
    a.[UploadStatusCode],
    a.[ScanStatusCode],
    a.[PublicUrl],
    a.[StoragePath],
    a.[StorageContainer],
    a.[ContentType],
    a.[FileExtension],
    a.[ByteSize],
    a.[OriginalFileName],
    a.[IsPrimary]
FROM [product].[ProductARModels] a
INNER JOIN [product].[Products] p ON p.[ProductId] = a.[ProductId]
WHERE a.[PublicId] = :arPublicId
  AND a.[DeletedAtUtc] IS NULL
SQL,
            [':arPublicId' => $arPublicId],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), a.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    a.[Title],
    a.[UploadStatusCode],
    a.[ScanStatusCode],
    a.[PublicUrl],
    a.[StoragePath],
    a.[StorageContainer],
    a.[ContentType],
    a.[FileExtension],
    a.[ByteSize],
    a.[OriginalFileName],
    a.[IsPrimary]
FROM [product].[ProductARModels] a
INNER JOIN [product].[Products] p ON p.[ProductId] = a.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND a.[DeletedAtUtc] IS NULL
ORDER BY a.[DisplayOrder], a.[Title]
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        return array_map(fn (array $row): ProductArModelSummary => $this->hydrate($row), $rows);
    }

    public function createUploadSession(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductArModelSummary {
        return $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $data, $actorUserId): ProductArModelSummary {
                $productId = $this->requireProductId($productPublicId);
                $mediaTypeId = $this->requireMediaTypeId('ar-model');
                $extension = strtolower((string) $data['fileExtension']);
                $format = in_array($extension, ['glb', 'usdz', 'gltf'], true) ? $extension : 'glb';
                $publicId = $data['publicId'] ?? null;

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [product].[ProductARModels] (
    [PublicId],
    [ProductId],
    [MediaTypeId],
    [Title],
    [StorageContainer],
    [StoragePath],
    [ContentType],
    [FileExtension],
    [ByteSize],
    [OriginalFileName],
    [IsPrimary],
    [ModelFormatCode],
    [UploadStatusCode],
    [ScanStatusCode],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    COALESCE(TRY_CONVERT(UNIQUEIDENTIFIER, :publicId), NEWSEQUENTIALID()),
    :productId,
    :mediaTypeId,
    :title,
    :storageContainer,
    :storagePath,
    :contentType,
    :fileExtension,
    :byteSize,
    :originalFileName,
    :isPrimary,
    :modelFormatCode,
    :uploadStatusCode,
    :scanStatusCode,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                    [
                        ':publicId' => $publicId,
                        ':productId' => $productId,
                        ':mediaTypeId' => $mediaTypeId,
                        ':title' => $data['title'],
                        ':storageContainer' => $data['storageContainer'],
                        ':storagePath' => $data['storagePath'],
                        ':contentType' => $data['contentType'],
                        ':fileExtension' => $data['fileExtension'],
                        ':byteSize' => $data['byteSize'],
                        ':originalFileName' => $data['originalFileName'],
                        ':isPrimary' => ($data['isPrimary'] ?? false) ? 1 : 0,
                        ':modelFormatCode' => $format,
                        ':uploadStatusCode' => $data['uploadStatusCode'],
                        ':scanStatusCode' => $data['scanStatusCode'],
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                if ($rows === []) {
                    throw ProductPersistenceException::insertFailed('ProductARModel');
                }

                $loaded = $this->findByPublicId(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
                if ($loaded === null) {
                    throw ProductPersistenceException::insertFailed('ProductARModel');
                }

                return $loaded;
            },
        );
    }

    public function markUploadCompleted(
        string $arPublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): ProductArModelSummary {
        $this->connection->execute(
            <<<'SQL'
UPDATE [product].[ProductARModels]
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
WHERE [PublicId] = :arPublicId
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
                ':arPublicId' => $arPublicId,
            ],
        );

        $loaded = $this->findByPublicId($arPublicId);
        if ($loaded === null) {
            throw ProductPersistenceException::referenceNotFound('ProductARModel', $arPublicId);
        }

        return $loaded;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProductArModelSummary
    {
        return new ProductArModelSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            productPublicId: AzureSqlValueMapper::stringValue($row['ProductPublicId'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            uploadStatusCode: AzureSqlValueMapper::stringValue($row['UploadStatusCode'] ?? 'active'),
            scanStatusCode: AzureSqlValueMapper::stringValue($row['ScanStatusCode'] ?? 'not_required'),
            publicUrl: AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
            storagePath: AzureSqlValueMapper::nullableString($row['StoragePath'] ?? null),
            storageContainer: AzureSqlValueMapper::nullableString($row['StorageContainer'] ?? null),
            contentType: AzureSqlValueMapper::nullableString($row['ContentType'] ?? null),
            fileExtension: AzureSqlValueMapper::nullableString($row['FileExtension'] ?? null),
            byteSize: isset($row['ByteSize']) && $row['ByteSize'] !== null ? AzureSqlValueMapper::intValue($row['ByteSize']) : null,
            originalFileName: AzureSqlValueMapper::nullableString($row['OriginalFileName'] ?? null),
            isPrimary: AzureSqlValueMapper::boolValue($row['IsPrimary'] ?? false),
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
