<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use RuntimeException;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductMediaFileRecord;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Contract\ProductMediaRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

/** @internal */
final class InMemoryFakeProductMediaRepository implements ProductMediaRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        return array_values(array_map(
            fn (array $row): ProductMediaSummary => $this->toSummary($row),
            array_filter(
                $this->store->mediaFor($productPublicId->toString()),
                static fn (array $row): bool => empty($row['archived']),
            ),
        ));
    }

    public function findByPublicId(string $mediaPublicId): ?ProductMediaFileRecord
    {
        $row = $this->store->mediaByPublicIdMap()[$mediaPublicId] ?? null;

        return $row === null ? null : self::toRecord($row);
    }

    public function create(ProductPublicId $productPublicId, array $data, UserId $actorUserId): ProductMediaSummary
    {
        $publicId = bin2hex(random_bytes(16));
        $row = [
            'publicId' => $publicId,
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
            'archived' => false,
        ];
        $this->persistRow($productPublicId->toString(), $row);

        return self::toSummary($row);
    }

    public function createUploadSession(ProductPublicId $productPublicId, array $data, UserId $actorUserId): ProductMediaFileRecord
    {
        $publicId = $data['publicId'] ?? bin2hex(random_bytes(16));
        $row = [
            'publicId' => $publicId,
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
            'archived' => false,
        ];
        $this->persistRow($productPublicId->toString(), $row);

        return self::toRecord($row);
    }

    public function markUploadCompleted(string $mediaPublicId, array $blobMeta, UserId $actorUserId): ProductMediaFileRecord
    {
        $map = $this->store->mediaByPublicIdMap();
        if (!isset($map[$mediaPublicId])) {
            throw new RuntimeException('media not found');
        }
        $row = $map[$mediaPublicId];
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
        } elseif ($row['pendingStoragePath'] !== null) {
            $row['storagePath'] = $row['pendingStoragePath'];
        }
        if (isset($blobMeta['storageContainer'])) {
            $row['storageContainer'] = $blobMeta['storageContainer'];
        } elseif ($row['pendingStorageContainer'] !== null) {
            $row['storageContainer'] = $row['pendingStorageContainer'];
        }
        $row['pendingStoragePath'] = null;
        $row['pendingStorageContainer'] = null;
        $row['previousStoragePath'] = null;
        $this->persistRow($row['productPublicId'], $row);

        return self::toRecord($row);
    }

    public function beginReplaceUpload(string $mediaPublicId, array $data, UserId $actorUserId): ProductMediaFileRecord
    {
        $map = $this->store->mediaByPublicIdMap();
        if (!isset($map[$mediaPublicId])) {
            throw new RuntimeException('media not found');
        }
        $row = $map[$mediaPublicId];
        $row['previousStoragePath'] = $row['storagePath'];
        $row['pendingStoragePath'] = $data['storagePath'];
        $row['pendingStorageContainer'] = $data['storageContainer'];
        $row['originalFileName'] = $data['originalFileName'];
        $row['contentType'] = $data['contentType'];
        $row['byteSize'] = $data['byteSize'];
        $row['fileExtension'] = $data['fileExtension'];
        $row['uploadStatusCode'] = $data['uploadStatusCode'];
        $row['scanStatusCode'] = $data['scanStatusCode'];
        $this->persistRow($row['productPublicId'], $row);

        return self::toRecord($row);
    }

    public function clearFile(string $mediaPublicId, UserId $actorUserId): void
    {
        $map = $this->store->mediaByPublicIdMap();
        if (!isset($map[$mediaPublicId])) {
            return;
        }
        $row = $map[$mediaPublicId];
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
        $this->persistRow($row['productPublicId'], $row);
    }

    public function updateImageRole(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        string $imageRoleCode,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        $exclusiveRoles = ['primary', 'cross-section', 'diagram', 'hero', 'thumbnail'];
        $rows = $this->store->mediaFor($productPublicId->toString());
        $map = $this->store->mediaByPublicIdMap();
        foreach ($rows as &$row) {
            if (
                in_array($imageRoleCode, $exclusiveRoles, true)
                && $row['publicId'] !== $mediaPublicId
                && (($row['mediaCategoryCode'] ?? '') === $imageRoleCode)
            ) {
                $row['mediaCategoryCode'] = 'gallery';
            }
            if ($row['publicId'] === $mediaPublicId) {
                $row['mediaCategoryCode'] = $imageRoleCode;
            }
            $map[$row['publicId']] = $row;
        }
        unset($row);
        $this->store->setMediaFor($productPublicId->toString(), $rows);
        $this->store->setMediaByPublicIdMap($map);
    }

    public function setPrimary(ProductPublicId $productPublicId, string $mediaPublicId, UserId $actorUserId): void
    {
        $rows = $this->store->mediaFor($productPublicId->toString());
        $map = $this->store->mediaByPublicIdMap();
        foreach ($rows as &$row) {
            $row['isPrimary'] = $row['publicId'] === $mediaPublicId;
            if (isset($map[$row['publicId']])) {
                $map[$row['publicId']]['isPrimary'] = $row['isPrimary'];
            }
        }
        unset($row);
        $this->store->setMediaFor($productPublicId->toString(), $rows);
        $this->store->setMediaByPublicIdMap($map);
    }

    public function reorder(ProductPublicId $productPublicId, array $orderedMediaPublicIds, UserId $actorUserId): void
    {
        $rows = $this->store->mediaFor($productPublicId->toString());
        $byPublicId = [];
        foreach ($rows as $row) {
            $byPublicId[$row['publicId']] = $row;
        }

        $reordered = [];
        foreach ($orderedMediaPublicIds as $index => $publicId) {
            if (isset($byPublicId[$publicId])) {
                $byPublicId[$publicId]['displayOrder'] = $index;
                $reordered[] = $byPublicId[$publicId];
                unset($byPublicId[$publicId]);
            }
        }
        foreach ($byPublicId as $row) {
            $reordered[] = $row;
        }

        $this->store->setMediaFor($productPublicId->toString(), $reordered);
        $map = $this->store->mediaByPublicIdMap();
        foreach ($reordered as $row) {
            $map[$row['publicId']] = $row;
        }
        $this->store->setMediaByPublicIdMap($map);
    }

    public function archive(ProductPublicId $productPublicId, string $mediaPublicId, UserId $actorUserId): void
    {
        $rows = $this->store->mediaFor($productPublicId->toString());
        foreach ($rows as &$row) {
            if ($row['publicId'] === $mediaPublicId) {
                $row['archived'] = true;
            }
        }
        unset($row);
        $this->store->setMediaFor($productPublicId->toString(), $rows);
        $map = $this->store->mediaByPublicIdMap();
        if (isset($map[$mediaPublicId])) {
            $map[$mediaPublicId]['archived'] = true;
            $this->store->setMediaByPublicIdMap($map);
        }
    }

    public function listPresentationRolesByMedia(ProductPublicId $productPublicId): array
    {
        $byRole = $this->listPresentationRoleAssignments($productPublicId);
        $map = [];
        foreach ($byRole as $role => $mediaPublicId) {
            $map[$mediaPublicId] ??= [];
            $map[$mediaPublicId][] = $role;
        }

        return $map;
    }

    public function listPresentationRoleAssignments(ProductPublicId $productPublicId): array
    {
        return $this->presentationAssignments[$productPublicId->toString()] ?? [];
    }

    public function assignPresentationRole(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        string $roleCode,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        $map = $this->store->mediaByPublicIdMap();
        $row = $map[$mediaPublicId] ?? null;
        if ($row === null || ($row['productPublicId'] ?? '') !== $productPublicId->toString() || !empty($row['archived'])) {
            throw new RuntimeException('media not found for product');
        }
        $pid = $productPublicId->toString();
        $this->presentationAssignments[$pid] ??= [];
        $this->presentationAssignments[$pid][strtolower(trim($roleCode))] = $mediaPublicId;
    }

    public function clearPresentationRole(
        ProductPublicId $productPublicId,
        string $roleCode,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        unset($this->presentationAssignments[$productPublicId->toString()][strtolower(trim($roleCode))]);
    }

    /** @var array<string, array<string, string>> */
    private array $presentationAssignments = [];

    /** @param array<string, mixed> $row */
    private function persistRow(string $productPublicId, array $row): void
    {
        $rows = $this->store->mediaFor($productPublicId);
        $found = false;
        foreach ($rows as $i => $existing) {
            if ($existing['publicId'] === $row['publicId']) {
                $rows[$i] = $row;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $rows[] = $row;
        }
        $this->store->setMediaFor($productPublicId, $rows);
        $map = $this->store->mediaByPublicIdMap();
        $map[$row['publicId']] = $row;
        $this->store->setMediaByPublicIdMap($map);
    }

    /** @param array<string, mixed> $row */
    private function toSummary(array $row): ProductMediaSummary
    {
        $summary = new ProductMediaSummary(
            $row['publicId'],
            $row['mediaCategoryCode'],
            $row['mediaTypeCode'],
            $row['title'],
            $row['publicUrl'] ?? null,
            $row['altText'] ?? null,
            $row['displayOrder'] ?? 0,
            $row['isPrimary'] ?? false,
            $row['uploadStatusCode'] ?? UploadStatusCode::ACTIVE,
            $row['scanStatusCode'] ?? ScanStatusCode::NOT_REQUIRED,
            $row['byteSize'] ?? null,
            $row['contentType'] ?? null,
            $row['originalFileName'] ?? null,
            $row['widthPx'] ?? null,
            $row['heightPx'] ?? null,
            $row['thumbnailUrl'] ?? null,
            $row['storagePath'] ?? null,
            $row['storageContainer'] ?? null,
        );
        $roles = [];
        foreach ($this->presentationAssignments[$row['productPublicId'] ?? ''] ?? [] as $role => $mediaId) {
            if ($mediaId === $summary->publicId) {
                $roles[] = $role;
            }
        }

        return $roles === [] ? $summary : $summary->withPresentationRoles($roles);
    }

    /** @param array<string, mixed> $row */
    private static function toRecord(array $row): ProductMediaFileRecord
    {
        return new ProductMediaFileRecord(
            publicId: $row['publicId'],
            productPublicId: $row['productPublicId'],
            mediaCategoryCode: $row['mediaCategoryCode'],
            mediaTypeCode: $row['mediaTypeCode'],
            title: $row['title'],
            publicUrl: $row['publicUrl'] ?? null,
            altText: $row['altText'] ?? null,
            displayOrder: $row['displayOrder'] ?? 0,
            isPrimary: $row['isPrimary'] ?? false,
            uploadStatusCode: $row['uploadStatusCode'],
            scanStatusCode: $row['scanStatusCode'],
            byteSize: $row['byteSize'] ?? null,
            contentType: $row['contentType'] ?? null,
            originalFileName: $row['originalFileName'] ?? null,
            fileExtension: $row['fileExtension'] ?? null,
            widthPx: $row['widthPx'] ?? null,
            heightPx: $row['heightPx'] ?? null,
            thumbnailUrl: $row['thumbnailUrl'] ?? null,
            storagePath: $row['storagePath'] ?? null,
            storageContainer: $row['storageContainer'] ?? null,
            pendingStoragePath: $row['pendingStoragePath'] ?? null,
            pendingStorageContainer: $row['pendingStorageContainer'] ?? null,
            previousStoragePath: $row['previousStoragePath'] ?? null,
        );
    }
}
