<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Azure SQL repository for product image media metadata.
 * Tables: product.ProductImages, product.ProductMedia (registry), product.MediaTypes.
 *
 * mediaCategoryCode maps to ProductImages.ImageRoleCode; mediaTypeCode joins MediaTypes.
 *
 * {@see archive()} soft-deletes media metadata only — it does not change product archive status.
 */
final class AzureSqlProductMediaRepository implements ProductMediaRepository
{
    use AzureSqlTransaction;

    private const LIST_SELECT_SQL = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), i.[PublicId]) AS [PublicId],
    i.[ImageRoleCode] AS [MediaCategoryCode],
    mt.[MediaTypeCode],
    i.[Title],
    i.[PublicUrl],
    i.[AltText],
    i.[DisplayOrder],
    i.[IsPrimary]
FROM [product].[ProductImages] i
INNER JOIN [product].[MediaTypes] mt ON mt.[MediaTypeId] = i.[MediaTypeId]
INNER JOIN [product].[Products] p ON p.[ProductId] = i.[ProductId]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            self::LIST_SELECT_SQL . ' WHERE p.[PublicId] = :productPublicId AND i.[DeletedAtUtc] IS NULL ORDER BY i.[DisplayOrder], i.[Title]',
            [':productPublicId' => $productPublicId->toString()],
        );

        return array_map(fn (array $row): ProductMediaSummary => $this->hydrate($row), $rows);
    }

    public function create(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductMediaSummary {
        return $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $data, $actorUserId): ProductMediaSummary {
                $productId = $this->requireProductId($productPublicId);
                $mediaTypeId = $this->requireMediaTypeId($data['mediaTypeCode']);

                $imageRows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [product].[ProductImages] (
    [ProductId],
    [MediaTypeId],
    [Title],
    [AltText],
    [Caption],
    [StorageContainer],
    [StoragePath],
    [PublicUrl],
    [ContentType],
    [FileExtension],
    [ByteSize],
    [DisplayOrder],
    [IsPrimary],
    [ImageRoleCode],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT
    INSERTED.[ProductImagesId] AS [SourceRecordId],
    CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :productId,
    :mediaTypeId,
    :title,
    :altText,
    :caption,
    :storageContainer,
    :storagePath,
    :publicUrl,
    :contentType,
    :fileExtension,
    :byteSize,
    :displayOrder,
    :isPrimary,
    :imageRoleCode,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                    [
                        ':productId' => $productId,
                        ':mediaTypeId' => $mediaTypeId,
                        ':title' => $data['title'],
                        ':altText' => $data['altText'] ?? null,
                        ':caption' => $data['caption'] ?? null,
                        ':storageContainer' => $data['storageContainer'] ?? null,
                        ':storagePath' => $data['storagePath'],
                        ':publicUrl' => $data['publicUrl'] ?? null,
                        ':contentType' => $data['contentType'] ?? null,
                        ':fileExtension' => $data['fileExtension'] ?? null,
                        ':byteSize' => $data['byteSize'] ?? null,
                        ':displayOrder' => $data['displayOrder'] ?? 0,
                        ':isPrimary' => ($data['isPrimary'] ?? false) ? 1 : 0,
                        ':imageRoleCode' => $data['mediaCategoryCode'],
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                if ($imageRows === []) {
                    throw ProductPersistenceException::insertFailed('ProductImage');
                }

                $sourceRecordId = AzureSqlValueMapper::intValue($imageRows[0]['SourceRecordId'] ?? 0);
                $publicId = AzureSqlValueMapper::stringValue($imageRows[0]['PublicId'] ?? '');

                $this->connection->execute(
                    <<<'SQL'
INSERT INTO [product].[ProductMedia] (
    [ProductId],
    [MediaCategoryCode],
    [MediaTypeId],
    [SourceTableCode],
    [SourceRecordId],
    [DisplayOrder],
    [IsPrimary],
    [CreatedByUserId],
    [UpdatedByUserId]
)
VALUES (
    :productId,
    N'image',
    :mediaTypeId,
    N'ProductImages',
    :sourceRecordId,
    :displayOrder,
    :isPrimary,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                    [
                        ':productId' => $productId,
                        ':mediaTypeId' => $mediaTypeId,
                        ':sourceRecordId' => $sourceRecordId,
                        ':displayOrder' => $data['displayOrder'] ?? 0,
                        ':isPrimary' => ($data['isPrimary'] ?? false) ? 1 : 0,
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                if (($data['isPrimary'] ?? false) === true) {
                    $this->clearPrimaryExcept($productPublicId, $publicId, $actorUserId);
                }

                $loaded = $this->findByPublicId($publicId);
                if ($loaded === null) {
                    throw ProductPersistenceException::insertFailed('ProductImage');
                }

                return $loaded;
            },
        );
    }

    public function setPrimary(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $mediaPublicId, $actorUserId): void {
                $this->clearPrimaryExcept($productPublicId, $mediaPublicId, $actorUserId);

                $this->connection->execute(
                    <<<'SQL'
UPDATE i
SET
    i.[IsPrimary] = 1,
    i.[UpdatedAtUtc] = SYSUTCDATETIME(),
    i.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductImages] i
INNER JOIN [product].[Products] p ON p.[ProductId] = i.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND i.[PublicId] = :mediaPublicId
  AND i.[DeletedAtUtc] IS NULL
SQL,
                    [
                        ':productPublicId' => $productPublicId->toString(),
                        ':mediaPublicId' => $mediaPublicId,
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                $this->connection->execute(
                    <<<'SQL'
UPDATE pm
SET
    pm.[IsPrimary] = CASE WHEN i.[PublicId] = :mediaPublicId THEN 1 ELSE 0 END,
    pm.[UpdatedAtUtc] = SYSUTCDATETIME(),
    pm.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductMedia] pm
INNER JOIN [product].[ProductImages] i ON i.[ProductImagesId] = pm.[SourceRecordId]
    AND pm.[SourceTableCode] = N'ProductImages'
INNER JOIN [product].[Products] p ON p.[ProductId] = pm.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND pm.[DeletedAtUtc] IS NULL
SQL,
                    [
                        ':productPublicId' => $productPublicId->toString(),
                        ':mediaPublicId' => $mediaPublicId,
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );
            },
        );
    }

    public function reorder(
        ProductPublicId $productPublicId,
        array $orderedMediaPublicIds,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $orderedMediaPublicIds, $actorUserId): void {
                foreach ($orderedMediaPublicIds as $index => $mediaPublicId) {
                    $displayOrder = $index + 1;
                    $this->connection->execute(
                        <<<'SQL'
UPDATE i
SET
    i.[DisplayOrder] = :displayOrder,
    i.[UpdatedAtUtc] = SYSUTCDATETIME(),
    i.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductImages] i
INNER JOIN [product].[Products] p ON p.[ProductId] = i.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND i.[PublicId] = :mediaPublicId
  AND i.[DeletedAtUtc] IS NULL
SQL,
                        [
                            ':displayOrder' => $displayOrder,
                            ':productPublicId' => $productPublicId->toString(),
                            ':mediaPublicId' => $mediaPublicId,
                            ':updatedByUserId' => $actorUserId->toInt(),
                        ],
                    );

                    $this->connection->execute(
                        <<<'SQL'
UPDATE pm
SET
    pm.[DisplayOrder] = :displayOrder,
    pm.[UpdatedAtUtc] = SYSUTCDATETIME(),
    pm.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductMedia] pm
INNER JOIN [product].[ProductImages] i ON i.[ProductImagesId] = pm.[SourceRecordId]
    AND pm.[SourceTableCode] = N'ProductImages'
INNER JOIN [product].[Products] p ON p.[ProductId] = pm.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND i.[PublicId] = :mediaPublicId
  AND pm.[DeletedAtUtc] IS NULL
SQL,
                        [
                            ':displayOrder' => $displayOrder,
                            ':productPublicId' => $productPublicId->toString(),
                            ':mediaPublicId' => $mediaPublicId,
                            ':updatedByUserId' => $actorUserId->toInt(),
                        ],
                    );
                }
            },
        );
    }

    public function archive(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        UserId $actorUserId,
    ): void {
        $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $mediaPublicId, $actorUserId): void {
                $this->connection->execute(
                    <<<'SQL'
UPDATE i
SET
    i.[DeletedAtUtc] = SYSUTCDATETIME(),
    i.[DeletedByUserId] = :deletedByUserId,
    i.[UpdatedAtUtc] = SYSUTCDATETIME(),
    i.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductImages] i
INNER JOIN [product].[Products] p ON p.[ProductId] = i.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND i.[PublicId] = :mediaPublicId
  AND i.[DeletedAtUtc] IS NULL
SQL,
                    [
                        ':productPublicId' => $productPublicId->toString(),
                        ':mediaPublicId' => $mediaPublicId,
                        ':deletedByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                $this->connection->execute(
                    <<<'SQL'
UPDATE pm
SET
    pm.[DeletedAtUtc] = SYSUTCDATETIME(),
    pm.[DeletedByUserId] = :deletedByUserId,
    pm.[UpdatedAtUtc] = SYSUTCDATETIME(),
    pm.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductMedia] pm
INNER JOIN [product].[ProductImages] i ON i.[ProductImagesId] = pm.[SourceRecordId]
    AND pm.[SourceTableCode] = N'ProductImages'
INNER JOIN [product].[Products] p ON p.[ProductId] = pm.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND i.[PublicId] = :mediaPublicId
  AND pm.[DeletedAtUtc] IS NULL
SQL,
                    [
                        ':productPublicId' => $productPublicId->toString(),
                        ':mediaPublicId' => $mediaPublicId,
                        ':deletedByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );
            },
        );
    }

    private function clearPrimaryExcept(
        ProductPublicId $productPublicId,
        string $exceptMediaPublicId,
        UserId $actorUserId,
    ): void {
        $this->connection->execute(
            <<<'SQL'
UPDATE i
SET
    i.[IsPrimary] = 0,
    i.[UpdatedAtUtc] = SYSUTCDATETIME(),
    i.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductImages] i
INNER JOIN [product].[Products] p ON p.[ProductId] = i.[ProductId]
WHERE p.[PublicId] = :productPublicId
  AND i.[DeletedAtUtc] IS NULL
  AND i.[PublicId] <> :exceptMediaPublicId
SQL,
            [
                ':productPublicId' => $productPublicId->toString(),
                ':exceptMediaPublicId' => $exceptMediaPublicId,
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
    }

    private function findByPublicId(string $publicId): ?ProductMediaSummary
    {
        $rows = $this->connection->fetchAll(
            self::LIST_SELECT_SQL . ' WHERE i.[PublicId] = :publicId AND i.[DeletedAtUtc] IS NULL',
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

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ProductMediaSummary
    {
        return new ProductMediaSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            mediaCategoryCode: AzureSqlValueMapper::stringValue($row['MediaCategoryCode'] ?? ''),
            mediaTypeCode: AzureSqlValueMapper::stringValue($row['MediaTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['Title'] ?? ''),
            publicUrl: AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
            altText: AzureSqlValueMapper::nullableString($row['AltText'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isPrimary: AzureSqlValueMapper::boolValue($row['IsPrimary'] ?? false),
        );
    }
}
