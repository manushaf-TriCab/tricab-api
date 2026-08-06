<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use RuntimeException;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductArModelSummary;
use TriCab\Core\Product\Contract\ProductArModelRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class InMemoryProductArModelRepository implements ProductArModelRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function findByPublicId(string $arPublicId): ?ProductArModelSummary
    {
        $row = $this->store->arModelsMap()[$arPublicId] ?? null;

        return $row === null ? null : self::toSummary($row);
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        $out = [];
        foreach ($this->store->arModelsMap() as $row) {
            if (($row['productPublicId'] ?? '') === $productPublicId->toString()) {
                $out[] = self::toSummary($row);
            }
        }

        return $out;
    }

    public function createUploadSession(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductArModelSummary {
        unset($actorUserId);
        $publicId = $data['publicId'] ?? $this->store->newPublicId();
        $row = [
            'publicId' => $publicId,
            'productPublicId' => $productPublicId->toString(),
            'title' => $data['title'],
            'uploadStatusCode' => $data['uploadStatusCode'],
            'scanStatusCode' => $data['scanStatusCode'],
            'publicUrl' => null,
            'storagePath' => $data['storagePath'],
            'storageContainer' => $data['storageContainer'],
            'contentType' => $data['contentType'],
            'fileExtension' => $data['fileExtension'],
            'byteSize' => $data['byteSize'],
            'originalFileName' => $data['originalFileName'],
            'isPrimary' => $data['isPrimary'] ?? false,
        ];
        $map = $this->store->arModelsMap();
        $map[$publicId] = $row;
        $this->store->setArModelsMap($map);

        return self::toSummary($row);
    }

    public function markUploadCompleted(
        string $arPublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): ProductArModelSummary {
        unset($actorUserId);
        $map = $this->store->arModelsMap();
        if (!isset($map[$arPublicId])) {
            throw new RuntimeException('AR model not found');
        }
        $row = $map[$arPublicId];
        $row['uploadStatusCode'] = $blobMeta['uploadStatusCode'];
        $row['scanStatusCode'] = $blobMeta['scanStatusCode'];
        $row['publicUrl'] = $blobMeta['publicUrl'] ?? $row['publicUrl'];
        $row['contentType'] = $blobMeta['contentType'] ?? $row['contentType'];
        $row['byteSize'] = $blobMeta['byteSize'] ?? $row['byteSize'];
        $map[$arPublicId] = $row;
        $this->store->setArModelsMap($map);

        return self::toSummary($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toSummary(array $row): ProductArModelSummary
    {
        return new ProductArModelSummary(
            publicId: (string) $row['publicId'],
            productPublicId: (string) $row['productPublicId'],
            title: (string) $row['title'],
            uploadStatusCode: (string) $row['uploadStatusCode'],
            scanStatusCode: (string) $row['scanStatusCode'],
            publicUrl: isset($row['publicUrl']) ? (is_string($row['publicUrl']) ? $row['publicUrl'] : null) : null,
            storagePath: isset($row['storagePath']) ? (is_string($row['storagePath']) ? $row['storagePath'] : null) : null,
            storageContainer: isset($row['storageContainer']) ? (is_string($row['storageContainer']) ? $row['storageContainer'] : null) : null,
            contentType: isset($row['contentType']) ? (is_string($row['contentType']) ? $row['contentType'] : null) : null,
            fileExtension: isset($row['fileExtension']) ? (is_string($row['fileExtension']) ? $row['fileExtension'] : null) : null,
            byteSize: isset($row['byteSize']) ? (int) $row['byteSize'] : null,
            originalFileName: isset($row['originalFileName']) ? (is_string($row['originalFileName']) ? $row['originalFileName'] : null) : null,
            isPrimary: (bool) ($row['isPrimary'] ?? false),
        );
    }
}
