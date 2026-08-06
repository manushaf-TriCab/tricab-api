<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use RuntimeException;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\Product360FrameSummary;
use TriCab\Core\Product\Application\DTO\Product360SetSummary;
use TriCab\Core\Product\Contract\Product360Repository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\UploadStatusCode;

final class InMemoryProduct360Repository implements Product360Repository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function findSetByPublicId(string $setPublicId): ?Product360SetSummary
    {
        $row = $this->store->sets360Map()[$setPublicId] ?? null;

        return $row === null ? null : $this->toSetSummary($row);
    }

    public function listSetsByProduct(ProductPublicId $productPublicId): array
    {
        $out = [];
        foreach ($this->store->sets360Map() as $row) {
            if (($row['productPublicId'] ?? '') === $productPublicId->toString()) {
                $out[] = $this->toSetSummary($row);
            }
        }

        return $out;
    }

    public function createSet(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): Product360SetSummary {
        unset($actorUserId);
        $publicId = $this->store->newPublicId();
        $row = [
            'publicId' => $publicId,
            'productPublicId' => $productPublicId->toString(),
            'title' => $data['title'],
            'expectedFrameCount' => $data['expectedFrameCount'],
            'statusCode' => 'draft',
            'isActive' => false,
            'displayOrder' => $data['displayOrder'] ?? 0,
        ];
        $map = $this->store->sets360Map();
        $map[$publicId] = $row;
        $this->store->setSets360Map($map);

        return $this->toSetSummary($row);
    }

    public function createFrameUploadSession(
        string $setPublicId,
        array $data,
        UserId $actorUserId,
    ): Product360FrameSummary {
        unset($actorUserId);
        if (!isset($this->store->sets360Map()[$setPublicId])) {
            throw new RuntimeException('360 set not found');
        }
        $publicId = $this->store->newPublicId();
        $row = [
            'publicId' => $publicId,
            'setPublicId' => $setPublicId,
            'frameNumber' => $data['frameNumber'],
            'uploadStatusCode' => $data['uploadStatusCode'],
            'scanStatusCode' => $data['scanStatusCode'],
            'publicUrl' => null,
            'storagePath' => $data['storagePath'],
            'storageContainer' => $data['storageContainer'],
            'contentType' => $data['contentType'],
            'byteSize' => $data['byteSize'],
            'originalFileName' => $data['originalFileName'],
        ];
        $map = $this->store->frames360Map();
        $map[$publicId] = $row;
        $this->store->setFrames360Map($map);

        return self::toFrameSummary($row);
    }

    public function findFrameByPublicId(string $framePublicId): ?Product360FrameSummary
    {
        $row = $this->store->frames360Map()[$framePublicId] ?? null;

        return $row === null ? null : self::toFrameSummary($row);
    }

    public function markFrameUploadCompleted(
        string $framePublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): Product360FrameSummary {
        unset($actorUserId);
        $map = $this->store->frames360Map();
        if (!isset($map[$framePublicId])) {
            throw new RuntimeException('360 frame not found');
        }
        $row = $map[$framePublicId];
        $row['uploadStatusCode'] = $blobMeta['uploadStatusCode'];
        $row['scanStatusCode'] = $blobMeta['scanStatusCode'];
        $row['publicUrl'] = $blobMeta['publicUrl'] ?? $row['publicUrl'];
        $row['contentType'] = $blobMeta['contentType'] ?? $row['contentType'];
        $row['byteSize'] = $blobMeta['byteSize'] ?? $row['byteSize'];
        $map[$framePublicId] = $row;
        $this->store->setFrames360Map($map);

        return self::toFrameSummary($row);
    }

    public function activateSet(string $setPublicId, UserId $actorUserId): Product360SetSummary
    {
        unset($actorUserId);
        $map = $this->store->sets360Map();
        if (!isset($map[$setPublicId])) {
            throw new RuntimeException('360 set not found');
        }
        $row = $map[$setPublicId];
        $row['statusCode'] = 'active';
        $row['isActive'] = true;
        $map[$setPublicId] = $row;
        $this->store->setSets360Map($map);

        return $this->toSetSummary($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toSetSummary(array $row): Product360SetSummary
    {
        $frames = [];
        foreach ($this->store->frames360Map() as $frame) {
            if (($frame['setPublicId'] ?? '') === ($row['publicId'] ?? '')) {
                $frames[] = self::toFrameSummary($frame);
            }
        }
        $uploaded = count(array_filter(
            $frames,
            static fn (Product360FrameSummary $f): bool => $f->uploadStatusCode === UploadStatusCode::ACTIVE
                || $f->uploadStatusCode === UploadStatusCode::UPLOADED,
        ));

        return new Product360SetSummary(
            publicId: (string) $row['publicId'],
            productPublicId: (string) $row['productPublicId'],
            title: (string) $row['title'],
            expectedFrameCount: (int) $row['expectedFrameCount'],
            uploadedFrameCount: $uploaded,
            statusCode: (string) $row['statusCode'],
            isActive: (bool) $row['isActive'],
            frames: $frames,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toFrameSummary(array $row): Product360FrameSummary
    {
        return new Product360FrameSummary(
            publicId: (string) $row['publicId'],
            setPublicId: (string) $row['setPublicId'],
            frameNumber: (int) $row['frameNumber'],
            uploadStatusCode: (string) $row['uploadStatusCode'],
            scanStatusCode: (string) $row['scanStatusCode'],
            publicUrl: isset($row['publicUrl']) ? (is_string($row['publicUrl']) ? $row['publicUrl'] : null) : null,
            storagePath: isset($row['storagePath']) ? (is_string($row['storagePath']) ? $row['storagePath'] : null) : null,
            storageContainer: isset($row['storageContainer']) ? (is_string($row['storageContainer']) ? $row['storageContainer'] : null) : null,
            contentType: isset($row['contentType']) ? (is_string($row['contentType']) ? $row['contentType'] : null) : null,
            byteSize: isset($row['byteSize']) ? (int) $row['byteSize'] : null,
            originalFileName: isset($row['originalFileName']) ? (is_string($row['originalFileName']) ? $row['originalFileName'] : null) : null,
        );
    }
}
