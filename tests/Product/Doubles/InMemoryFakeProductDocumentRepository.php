<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use RuntimeException;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductDocumentFileRecord;
use TriCab\Core\Product\Application\DTO\ProductDocumentSummary;
use TriCab\Core\Product\Contract\ProductDocumentRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ScanStatusCode;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

/** @internal */
final class InMemoryFakeProductDocumentRepository implements ProductDocumentRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
    }

    public function listByProduct(ProductPublicId $productPublicId, bool $publicOnly = false): array
    {
        return array_values(array_map(
            static fn (array $row): ProductDocumentSummary => self::toSummary($row),
            array_filter(
                $this->store->documentsFor($productPublicId->toString()),
                static fn (array $row): bool => empty($row['archived']) && (!$publicOnly || $row['accessCode'] === 'public'),
            ),
        ));
    }

    public function findByPublicId(string $documentPublicId): ?ProductDocumentFileRecord
    {
        $row = $this->store->documentsByPublicIdMap()[$documentPublicId] ?? null;

        return $row === null ? null : self::toRecord($row);
    }

    public function create(ProductPublicId $productPublicId, array $data, UserId $actorUserId): ProductDocumentSummary
    {
        $publicId = bin2hex(random_bytes(16));
        $row = [
            'publicId' => $publicId,
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
            'archived' => false,
        ];
        $this->persistRow($productPublicId->toString(), $row);

        return self::toSummary($row);
    }

    public function createUploadSession(ProductPublicId $productPublicId, array $data, UserId $actorUserId): ProductDocumentFileRecord
    {
        $publicId = $data['publicId'] ?? bin2hex(random_bytes(16));
        $row = [
            'publicId' => $publicId,
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
            'archived' => false,
        ];
        $this->persistRow($productPublicId->toString(), $row);

        return self::toRecord($row);
    }

    public function markUploadCompleted(string $documentPublicId, array $blobMeta, UserId $actorUserId): ProductDocumentFileRecord
    {
        $map = $this->store->documentsByPublicIdMap();
        if (!isset($map[$documentPublicId])) {
            throw new RuntimeException('document not found');
        }
        $row = $map[$documentPublicId];
        $row['uploadStatusCode'] = $blobMeta['uploadStatusCode'];
        $row['scanStatusCode'] = $blobMeta['scanStatusCode'];
        $row['publicUrl'] = $blobMeta['publicUrl'] ?? $row['publicUrl'];
        $row['contentType'] = $blobMeta['contentType'] ?? $row['contentType'];
        $row['byteSize'] = $blobMeta['byteSize'] ?? $row['byteSize'];
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

    public function beginReplaceUpload(string $documentPublicId, array $data, UserId $actorUserId): ProductDocumentFileRecord
    {
        $map = $this->store->documentsByPublicIdMap();
        if (!isset($map[$documentPublicId])) {
            throw new RuntimeException('document not found');
        }
        $row = $map[$documentPublicId];
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

    public function clearFile(string $documentPublicId, UserId $actorUserId): void
    {
        $map = $this->store->documentsByPublicIdMap();
        if (!isset($map[$documentPublicId])) {
            return;
        }
        $row = $map[$documentPublicId];
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
        $this->persistRow($row['productPublicId'], $row);
    }

    public function archive(ProductPublicId $productPublicId, string $documentPublicId, UserId $actorUserId): void
    {
        $rows = $this->store->documentsFor($productPublicId->toString());
        foreach ($rows as &$row) {
            if ($row['publicId'] === $documentPublicId) {
                $row['archived'] = true;
            }
        }
        unset($row);
        $this->store->setDocumentsFor($productPublicId->toString(), $rows);
        $map = $this->store->documentsByPublicIdMap();
        if (isset($map[$documentPublicId])) {
            $map[$documentPublicId]['archived'] = true;
            $this->store->setDocumentsByPublicIdMap($map);
        }
    }

    /** @param array<string, mixed> $row */
    private function persistRow(string $productPublicId, array $row): void
    {
        $rows = $this->store->documentsFor($productPublicId);
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
        $this->store->setDocumentsFor($productPublicId, $rows);
        $map = $this->store->documentsByPublicIdMap();
        $map[$row['publicId']] = $row;
        $this->store->setDocumentsByPublicIdMap($map);
    }

    /** @param array<string, mixed> $row */
    private static function toSummary(array $row): ProductDocumentSummary
    {
        return new ProductDocumentSummary(
            $row['publicId'],
            $row['documentTypeCode'],
            $row['title'],
            $row['versionLabel'] ?? null,
            $row['languageCode'] ?? null,
            $row['publicUrl'] ?? null,
            $row['accessCode'],
            $row['displayOrder'] ?? 0,
            $row['uploadStatusCode'] ?? UploadStatusCode::ACTIVE,
            $row['scanStatusCode'] ?? ScanStatusCode::NOT_REQUIRED,
            $row['byteSize'] ?? null,
            $row['contentType'] ?? null,
            $row['originalFileName'] ?? null,
            $row['fileExtension'] ?? null,
            $row['storagePath'] ?? null,
            $row['storageContainer'] ?? null,
        );
    }

    /** @param array<string, mixed> $row */
    private static function toRecord(array $row): ProductDocumentFileRecord
    {
        return new ProductDocumentFileRecord(
            publicId: $row['publicId'],
            productPublicId: $row['productPublicId'],
            documentTypeCode: $row['documentTypeCode'],
            title: $row['title'],
            versionLabel: $row['versionLabel'] ?? null,
            languageCode: $row['languageCode'] ?? null,
            publicUrl: $row['publicUrl'] ?? null,
            accessCode: $row['accessCode'],
            displayOrder: $row['displayOrder'] ?? 0,
            uploadStatusCode: $row['uploadStatusCode'],
            scanStatusCode: $row['scanStatusCode'],
            byteSize: $row['byteSize'] ?? null,
            contentType: $row['contentType'] ?? null,
            originalFileName: $row['originalFileName'] ?? null,
            fileExtension: $row['fileExtension'] ?? null,
            storagePath: $row['storagePath'] ?? null,
            storageContainer: $row['storageContainer'] ?? null,
            pendingStoragePath: $row['pendingStoragePath'] ?? null,
            pendingStorageContainer: $row['pendingStorageContainer'] ?? null,
            previousStoragePath: $row['previousStoragePath'] ?? null,
        );
    }
}
