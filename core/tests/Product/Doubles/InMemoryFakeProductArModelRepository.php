<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use RuntimeException;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductArModelSummary;
use TriCab\Core\Product\Contract\ProductArModelRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/** @internal */
final class InMemoryFakeProductArModelRepository implements ProductArModelRepository
{
    public function __construct(private readonly InMemoryProductStore $store)
    {
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
            if ($row['productPublicId'] === $productPublicId->toString()) {
                $out[] = self::toSummary($row);
            }
        }

        return $out;
    }

    public function createUploadSession(ProductPublicId $productPublicId, array $data, UserId $actorUserId): ProductArModelSummary
    {
        $publicId = $data['publicId'] ?? bin2hex(random_bytes(16));
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

    public function markUploadCompleted(string $arPublicId, array $blobMeta, UserId $actorUserId): ProductArModelSummary
    {
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

    /** @param array<string, mixed> $row */
    private static function toSummary(array $row): ProductArModelSummary
    {
        return new ProductArModelSummary(
            publicId: $row['publicId'],
            productPublicId: $row['productPublicId'],
            title: $row['title'],
            uploadStatusCode: $row['uploadStatusCode'],
            scanStatusCode: $row['scanStatusCode'],
            publicUrl: $row['publicUrl'] ?? null,
            storagePath: $row['storagePath'] ?? null,
            storageContainer: $row['storageContainer'] ?? null,
            contentType: $row['contentType'] ?? null,
            fileExtension: $row['fileExtension'] ?? null,
            byteSize: $row['byteSize'] ?? null,
            originalFileName: $row['originalFileName'] ?? null,
            isPrimary: $row['isPrimary'] ?? false,
        );
    }
}
