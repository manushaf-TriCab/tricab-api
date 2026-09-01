<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use RuntimeException;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductMediaFileRecord;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

/**
 * In-memory product media repository. {@see archive()} soft-deletes media metadata only.
 */
final class InMemoryProductMediaRepository implements ProductMediaRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        $items = [];
        foreach ($this->store->media() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId->toString() || ($row['archived'] ?? false)) {
                continue;
            }
            $items[] = $this->toSummary($row);
        }

        return $items;
    }

    public function findByPublicId(string $mediaPublicId): ?ProductMediaFileRecord
    {
        foreach ($this->store->media() as $row) {
            if (($row['publicId'] ?? '') === $mediaPublicId && !($row['archived'] ?? false)) {
                return $this->toRecord($row);
            }
        }

        return null;
    }

    public function create(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductMediaSummary {
        $row = [
            'publicId' => $this->store->newPublicId(),
            'productPublicId' => $productPublicId->toString(),
            'mediaCategoryCode' => $data['mediaCategoryCode'],
            'mediaTypeCode' => $data['mediaTypeCode'],
            'title' => $data['title'],
            'publicUrl' => $data['publicUrl'] ?? null,
            'altText' => $data['altText'] ?? null,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isPrimary' => $data['isPrimary'] ?? false,
            'uploadStatusCode' => UploadStatusCode::ACTIVE,
            'scanStatusCode' => ScanStatusCode::NOT_REQUIRED,
            'byteSize' => $data['byteSize'] ?? null,
            'contentType' => $data['contentType'] ?? null,
            'originalFileName' => null,
            'fileExtension' => $data['fileExtension'] ?? null,
            'widthPx' => null,
            'heightPx' => null,
            'thumbnailUrl' => null,
            'storagePath' => $data['storagePath'] ?? null,
            'storageContainer' => $data['storageContainer'] ?? null,
            'pendingStoragePath' => null,
            'pendingStorageContainer' => null,
            'previousStoragePath' => null,
            'isActive' => true,
            'archived' => false,
        ];
        $this->store->addMedia($row);

        if ($row['isPrimary']) {
            $this->setPrimary($productPublicId, $row['publicId'], $actorUserId);
        }

        return $this->toSummary($row);
    }

    public function createUploadSession(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductMediaFileRecord {
        unset($actorUserId);
        $row = [
            'publicId' => $data['publicId'] ?? $this->store->newPublicId(),
            'productPublicId' => $productPublicId->toString(),
            'mediaCategoryCode' => $data['mediaCategoryCode'],
            'mediaTypeCode' => $data['mediaTypeCode'],
            'title' => $data['title'],
            'publicUrl' => null,
            'altText' => $data['altText'] ?? null,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isPrimary' => $data['isPrimary'] ?? false,
            'uploadStatusCode' => $data['uploadStatusCode'],
            'scanStatusCode' => $data['scanStatusCode'],
            'byteSize' => $data['byteSize'],
            'contentType' => $data['contentType'],
            'originalFileName' => $data['originalFileName'],
            'fileExtension' => $data['fileExtension'],
            'widthPx' => null,
            'heightPx' => null,
            'thumbnailUrl' => null,
            'storagePath' => $data['storagePath'],
            'storageContainer' => $data['storageContainer'],
            'pendingStoragePath' => null,
            'pendingStorageContainer' => null,
            'previousStoragePath' => null,
            'isActive' => true,
            'archived' => false,
        ];
        $this->store->addMedia($row);

        return $this->toRecord($row);
    }

    public function markUploadCompleted(
        string $mediaPublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): ProductMediaFileRecord {
        unset($actorUserId);
        $updated = null;
        $found = $this->store->updateMediaByPublicId($mediaPublicId, static function (array $row) use ($blobMeta, &$updated): array {
            $row['uploadStatusCode'] = $blobMeta['uploadStatusCode'];
            $row['scanStatusCode'] = $blobMeta['scanStatusCode'];
            $row['publicUrl'] = $blobMeta['publicUrl'] ?? $row['publicUrl'];
            $row['contentType'] = $blobMeta['contentType'] ?? $row['contentType'];
            $row['byteSize'] = $blobMeta['byteSize'] ?? $row['byteSize'];
            $row['widthPx'] = $blobMeta['widthPx'] ?? $row['widthPx'];
            $row['heightPx'] = $blobMeta['heightPx'] ?? $row['heightPx'];
            $row['thumbnailUrl'] = $blobMeta['thumbnailUrl'] ?? $row['thumbnailUrl'];
            if (isset($blobMeta['storagePath'])) {
                $row['storagePath'] = $blobMeta['storagePath'];
            } elseif (($row['pendingStoragePath'] ?? null) !== null) {
                $row['storagePath'] = $row['pendingStoragePath'];
            }
            if (isset($blobMeta['storageContainer'])) {
                $row['storageContainer'] = $blobMeta['storageContainer'];
            } elseif (($row['pendingStorageContainer'] ?? null) !== null) {
                $row['storageContainer'] = $row['pendingStorageContainer'];
            }
            $row['pendingStoragePath'] = null;
            $row['pendingStorageContainer'] = null;
            $row['previousStoragePath'] = null;
            $updated = $row;

            return $row;
        });
        if (!$found || !is_array($updated)) {
            throw new RuntimeException('media not found');
        }

        return $this->toRecord($updated);
    }

    public function beginReplaceUpload(
        string $mediaPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductMediaFileRecord {
        unset($actorUserId);
        $updated = null;
        $found = $this->store->updateMediaByPublicId($mediaPublicId, static function (array $row) use ($data, &$updated): array {
            $row['previousStoragePath'] = $row['storagePath'] ?? null;
            $row['pendingStoragePath'] = $data['storagePath'];
            $row['pendingStorageContainer'] = $data['storageContainer'];
            $row['originalFileName'] = $data['originalFileName'];
            $row['contentType'] = $data['contentType'];
            $row['byteSize'] = $data['byteSize'];
            $row['fileExtension'] = $data['fileExtension'];
            $row['uploadStatusCode'] = $data['uploadStatusCode'];
            $row['scanStatusCode'] = $data['scanStatusCode'];
            $updated = $row;

            return $row;
        });
        if (!$found || !is_array($updated)) {
            throw new RuntimeException('media not found');
        }

        return $this->toRecord($updated);
    }

    public function clearFile(string $mediaPublicId, UserId $actorUserId): void
    {
        unset($actorUserId);
        $this->store->updateMediaByPublicId($mediaPublicId, static function (array $row): array {
            $row['publicUrl'] = null;
            $row['storagePath'] = null;
            $row['pendingStoragePath'] = null;
            $row['pendingStorageContainer'] = null;
            $row['previousStoragePath'] = null;
            $row['byteSize'] = null;
            $row['contentType'] = null;
            $row['originalFileName'] = null;
            $row['widthPx'] = null;
            $row['heightPx'] = null;
            $row['thumbnailUrl'] = null;
            $row['uploadStatusCode'] = UploadStatusCode::DELETED;

            return $row;
        });
    }

    public function updateImageRole(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        string $imageRoleCode,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        $exclusiveRoles = ['primary', 'cross-section', 'diagram', 'hero', 'thumbnail', 'product-thumbnail'];
        foreach ($this->store->media() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId->toString()) {
                continue;
            }
            $publicId = (string) ($row['publicId'] ?? '');
            if (
                in_array($imageRoleCode, $exclusiveRoles, true)
                && $publicId !== $mediaPublicId
                && (($row['mediaCategoryCode'] ?? '') === $imageRoleCode)
            ) {
                $row['mediaCategoryCode'] = 'gallery';
                $this->store->addMedia($row);
                continue;
            }
            if ($publicId === $mediaPublicId) {
                $row['mediaCategoryCode'] = $imageRoleCode;
                $this->store->addMedia($row);
            }
        }
    }

    public function setPrimary(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        foreach ($this->store->media() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId->toString()) {
                continue;
            }
            $row['isPrimary'] = ($row['publicId'] ?? '') === $mediaPublicId;
            $this->store->addMedia($row);
        }
    }

    public function reorder(
        ProductPublicId $productPublicId,
        array $orderedMediaPublicIds,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        foreach ($orderedMediaPublicIds as $index => $mediaPublicId) {
            foreach ($this->store->media() as $row) {
                if (
                    ($row['productPublicId'] ?? '') === $productPublicId->toString()
                    && ($row['publicId'] ?? '') === $mediaPublicId
                ) {
                    $row['displayOrder'] = $index + 1;
                    $this->store->addMedia($row);
                }
            }
        }
    }

    public function archive(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        foreach ($this->store->media() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId->toString()
                && ($row['publicId'] ?? '') === $mediaPublicId
            ) {
                $row['archived'] = true;
                $this->store->addMedia($row);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSummary(array $row): ProductMediaSummary
    {
        return new ProductMediaSummary(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            mediaCategoryCode: AzureSqlValueMapper::stringValue($row['mediaCategoryCode'] ?? ''),
            mediaTypeCode: AzureSqlValueMapper::stringValue($row['mediaTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['title'] ?? ''),
            publicUrl: AzureSqlValueMapper::nullableString($row['publicUrl'] ?? null),
            altText: AzureSqlValueMapper::nullableString($row['altText'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            isPrimary: AzureSqlValueMapper::boolValue($row['isPrimary'] ?? false),
            uploadStatusCode: AzureSqlValueMapper::nullableString($row['uploadStatusCode'] ?? UploadStatusCode::ACTIVE),
            scanStatusCode: AzureSqlValueMapper::nullableString($row['scanStatusCode'] ?? ScanStatusCode::NOT_REQUIRED),
            byteSize: isset($row['byteSize']) ? AzureSqlValueMapper::intValue($row['byteSize']) : null,
            contentType: AzureSqlValueMapper::nullableString($row['contentType'] ?? null),
            originalFileName: AzureSqlValueMapper::nullableString($row['originalFileName'] ?? null),
            widthPx: isset($row['widthPx']) ? AzureSqlValueMapper::intValue($row['widthPx']) : null,
            heightPx: isset($row['heightPx']) ? AzureSqlValueMapper::intValue($row['heightPx']) : null,
            thumbnailUrl: AzureSqlValueMapper::nullableString($row['thumbnailUrl'] ?? null),
            storagePath: AzureSqlValueMapper::nullableString($row['storagePath'] ?? null),
            storageContainer: AzureSqlValueMapper::nullableString($row['storageContainer'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toRecord(array $row): ProductMediaFileRecord
    {
        return new ProductMediaFileRecord(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            productPublicId: AzureSqlValueMapper::stringValue($row['productPublicId'] ?? ''),
            mediaCategoryCode: AzureSqlValueMapper::stringValue($row['mediaCategoryCode'] ?? ''),
            mediaTypeCode: AzureSqlValueMapper::stringValue($row['mediaTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['title'] ?? ''),
            publicUrl: AzureSqlValueMapper::nullableString($row['publicUrl'] ?? null),
            altText: AzureSqlValueMapper::nullableString($row['altText'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            isPrimary: AzureSqlValueMapper::boolValue($row['isPrimary'] ?? false),
            uploadStatusCode: AzureSqlValueMapper::stringValue($row['uploadStatusCode'] ?? UploadStatusCode::ACTIVE),
            scanStatusCode: AzureSqlValueMapper::stringValue($row['scanStatusCode'] ?? ScanStatusCode::NOT_REQUIRED),
            byteSize: isset($row['byteSize']) ? AzureSqlValueMapper::intValue($row['byteSize']) : null,
            contentType: AzureSqlValueMapper::nullableString($row['contentType'] ?? null),
            originalFileName: AzureSqlValueMapper::nullableString($row['originalFileName'] ?? null),
            fileExtension: AzureSqlValueMapper::nullableString($row['fileExtension'] ?? null),
            widthPx: isset($row['widthPx']) ? AzureSqlValueMapper::intValue($row['widthPx']) : null,
            heightPx: isset($row['heightPx']) ? AzureSqlValueMapper::intValue($row['heightPx']) : null,
            thumbnailUrl: AzureSqlValueMapper::nullableString($row['thumbnailUrl'] ?? null),
            storagePath: AzureSqlValueMapper::nullableString($row['storagePath'] ?? null),
            storageContainer: AzureSqlValueMapper::nullableString($row['storageContainer'] ?? null),
            pendingStoragePath: AzureSqlValueMapper::nullableString($row['pendingStoragePath'] ?? null),
            pendingStorageContainer: AzureSqlValueMapper::nullableString($row['pendingStorageContainer'] ?? null),
            previousStoragePath: AzureSqlValueMapper::nullableString($row['previousStoragePath'] ?? null),
        );
    }
}
