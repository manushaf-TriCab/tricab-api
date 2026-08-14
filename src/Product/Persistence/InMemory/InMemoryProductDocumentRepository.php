<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use RuntimeException;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductDocumentFileRecord;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

/**
 * In-memory product document repository. {@see archive()} soft-deletes document metadata only.
 */
final class InMemoryProductDocumentRepository implements ProductDocumentRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listByProduct(ProductPublicId $productPublicId, bool $publicOnly = false): array
    {
        $items = [];
        foreach ($this->store->documents() as $row) {
            if (($row['productPublicId'] ?? '') !== $productPublicId->toString() || ($row['archived'] ?? false)) {
                continue;
            }
            if ($publicOnly && ($row['accessCode'] ?? '') !== 'public') {
                continue;
            }
            $items[] = $this->toSummary($row);
        }

        return $items;
    }

    public function findByPublicId(string $documentPublicId): ?ProductDocumentFileRecord
    {
        foreach ($this->store->documents() as $row) {
            if (($row['publicId'] ?? '') === $documentPublicId && !($row['archived'] ?? false)) {
                return $this->toRecord($row);
            }
        }

        return null;
    }

    public function create(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductDocumentSummary {
        unset($actorUserId);
        $row = [
            'publicId' => $this->store->newPublicId(),
            'productPublicId' => $productPublicId->toString(),
            'documentTypeCode' => $data['documentTypeCode'],
            'title' => $data['title'],
            'versionLabel' => $data['versionLabel'] ?? null,
            'languageCode' => $data['languageCode'] ?? null,
            'publicUrl' => $data['publicUrl'] ?? null,
            'accessCode' => $data['accessCode'],
            'displayOrder' => $data['displayOrder'] ?? 0,
            'uploadStatusCode' => UploadStatusCode::ACTIVE,
            'scanStatusCode' => ScanStatusCode::NOT_REQUIRED,
            'byteSize' => null,
            'contentType' => null,
            'originalFileName' => null,
            'fileExtension' => null,
            'storagePath' => $data['storagePath'] ?? null,
            'storageContainer' => $data['storageContainer'] ?? null,
            'pendingStoragePath' => null,
            'pendingStorageContainer' => null,
            'previousStoragePath' => null,
            'isActive' => true,
            'archived' => false,
        ];
        $this->store->addDocument($row);

        return $this->toSummary($row);
    }

    public function createUploadSession(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductDocumentFileRecord {
        unset($actorUserId);
        $row = [
            'publicId' => $data['publicId'] ?? $this->store->newPublicId(),
            'productPublicId' => $productPublicId->toString(),
            'documentTypeCode' => $data['documentTypeCode'],
            'title' => $data['title'],
            'versionLabel' => $data['versionLabel'] ?? null,
            'languageCode' => $data['languageCode'] ?? null,
            'publicUrl' => null,
            'accessCode' => $data['accessCode'],
            'displayOrder' => $data['displayOrder'] ?? 0,
            'uploadStatusCode' => $data['uploadStatusCode'],
            'scanStatusCode' => $data['scanStatusCode'],
            'byteSize' => $data['byteSize'],
            'contentType' => $data['contentType'],
            'originalFileName' => $data['originalFileName'],
            'fileExtension' => $data['fileExtension'],
            'storagePath' => $data['storagePath'],
            'storageContainer' => $data['storageContainer'],
            'pendingStoragePath' => null,
            'pendingStorageContainer' => null,
            'previousStoragePath' => null,
            'isActive' => true,
            'archived' => false,
        ];
        $this->store->addDocument($row);

        return $this->toRecord($row);
    }

    public function markUploadCompleted(
        string $documentPublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): ProductDocumentFileRecord {
        unset($actorUserId);
        $updated = null;
        $found = $this->store->updateDocumentByPublicId($documentPublicId, static function (array $row) use ($blobMeta, &$updated): array {
            $row['uploadStatusCode'] = $blobMeta['uploadStatusCode'];
            $row['scanStatusCode'] = $blobMeta['scanStatusCode'];
            $row['publicUrl'] = $blobMeta['publicUrl'] ?? $row['publicUrl'];
            $row['contentType'] = $blobMeta['contentType'] ?? $row['contentType'];
            $row['byteSize'] = $blobMeta['byteSize'] ?? $row['byteSize'];
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
            throw new RuntimeException('document not found');
        }

        return $this->toRecord($updated);
    }

    public function beginReplaceUpload(
        string $documentPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductDocumentFileRecord {
        unset($actorUserId);
        $updated = null;
        $found = $this->store->updateDocumentByPublicId($documentPublicId, static function (array $row) use ($data, &$updated): array {
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
            throw new RuntimeException('document not found');
        }

        return $this->toRecord($updated);
    }

    public function clearFile(string $documentPublicId, UserId $actorUserId): void
    {
        unset($actorUserId);
        $this->store->updateDocumentByPublicId($documentPublicId, static function (array $row): array {
            $row['publicUrl'] = null;
            $row['storagePath'] = null;
            $row['pendingStoragePath'] = null;
            $row['pendingStorageContainer'] = null;
            $row['previousStoragePath'] = null;
            $row['byteSize'] = null;
            $row['contentType'] = null;
            $row['originalFileName'] = null;
            $row['fileExtension'] = null;
            $row['uploadStatusCode'] = UploadStatusCode::DELETED;

            return $row;
        });
    }

    public function archive(
        ProductPublicId $productPublicId,
        string $documentPublicId,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        foreach ($this->store->documents() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId->toString()
                && ($row['publicId'] ?? '') === $documentPublicId
            ) {
                $row['archived'] = true;
                $this->store->addDocument($row);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSummary(array $row): ProductDocumentSummary
    {
        return new ProductDocumentSummary(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            documentTypeCode: AzureSqlValueMapper::stringValue($row['documentTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['title'] ?? ''),
            versionLabel: AzureSqlValueMapper::nullableString($row['versionLabel'] ?? null),
            languageCode: AzureSqlValueMapper::nullableString($row['languageCode'] ?? null),
            publicUrl: AzureSqlValueMapper::nullableString($row['publicUrl'] ?? null),
            accessCode: AzureSqlValueMapper::stringValue($row['accessCode'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            uploadStatusCode: AzureSqlValueMapper::nullableString($row['uploadStatusCode'] ?? UploadStatusCode::ACTIVE),
            scanStatusCode: AzureSqlValueMapper::nullableString($row['scanStatusCode'] ?? ScanStatusCode::NOT_REQUIRED),
            byteSize: isset($row['byteSize']) ? AzureSqlValueMapper::intValue($row['byteSize']) : null,
            contentType: AzureSqlValueMapper::nullableString($row['contentType'] ?? null),
            originalFileName: AzureSqlValueMapper::nullableString($row['originalFileName'] ?? null),
            fileExtension: AzureSqlValueMapper::nullableString($row['fileExtension'] ?? null),
            storagePath: AzureSqlValueMapper::nullableString($row['storagePath'] ?? null),
            storageContainer: AzureSqlValueMapper::nullableString($row['storageContainer'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toRecord(array $row): ProductDocumentFileRecord
    {
        return new ProductDocumentFileRecord(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            productPublicId: AzureSqlValueMapper::stringValue($row['productPublicId'] ?? ''),
            documentTypeCode: AzureSqlValueMapper::stringValue($row['documentTypeCode'] ?? ''),
            title: AzureSqlValueMapper::stringValue($row['title'] ?? ''),
            versionLabel: AzureSqlValueMapper::nullableString($row['versionLabel'] ?? null),
            languageCode: AzureSqlValueMapper::nullableString($row['languageCode'] ?? null),
            publicUrl: AzureSqlValueMapper::nullableString($row['publicUrl'] ?? null),
            accessCode: AzureSqlValueMapper::stringValue($row['accessCode'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            uploadStatusCode: AzureSqlValueMapper::stringValue($row['uploadStatusCode'] ?? UploadStatusCode::ACTIVE),
            scanStatusCode: AzureSqlValueMapper::stringValue($row['scanStatusCode'] ?? ScanStatusCode::NOT_REQUIRED),
            byteSize: isset($row['byteSize']) ? AzureSqlValueMapper::intValue($row['byteSize']) : null,
            contentType: AzureSqlValueMapper::nullableString($row['contentType'] ?? null),
            originalFileName: AzureSqlValueMapper::nullableString($row['originalFileName'] ?? null),
            fileExtension: AzureSqlValueMapper::nullableString($row['fileExtension'] ?? null),
            storagePath: AzureSqlValueMapper::nullableString($row['storagePath'] ?? null),
            storageContainer: AzureSqlValueMapper::nullableString($row['storageContainer'] ?? null),
            pendingStoragePath: AzureSqlValueMapper::nullableString($row['pendingStoragePath'] ?? null),
            pendingStorageContainer: AzureSqlValueMapper::nullableString($row['pendingStorageContainer'] ?? null),
            previousStoragePath: AzureSqlValueMapper::nullableString($row['previousStoragePath'] ?? null),
        );
    }
}
