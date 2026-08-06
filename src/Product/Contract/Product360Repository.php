<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\Product360FrameSummary;
use TriCab\Core\Product\Application\DTO\Product360SetSummary;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

interface Product360Repository
{
    public function findSetByPublicId(string $setPublicId): ?Product360SetSummary;

    /**
     * @return list<Product360SetSummary>
     */
    public function listSetsByProduct(ProductPublicId $productPublicId): array;

    /**
     * @param array{
     *   title: string,
     *   expectedFrameCount: int,
     *   displayOrder?: int
     * } $data
     */
    public function createSet(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): Product360SetSummary;

    /**
     * @param array{
     *   frameNumber: int,
     *   originalFileName: string,
     *   contentType: string,
     *   byteSize: int,
     *   fileExtension: string,
     *   storagePath: string,
     *   storageContainer: string,
     *   uploadStatusCode: string,
     *   scanStatusCode: string
     * } $data
     */
    public function createFrameUploadSession(
        string $setPublicId,
        array $data,
        UserId $actorUserId,
    ): Product360FrameSummary;

    public function findFrameByPublicId(string $framePublicId): ?Product360FrameSummary;

    /**
     * @param array{
     *   uploadStatusCode: string,
     *   scanStatusCode: string,
     *   publicUrl?: ?string,
     *   contentType?: ?string,
     *   byteSize?: ?int,
     *   etag?: ?string
     * } $blobMeta
     */
    public function markFrameUploadCompleted(
        string $framePublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): Product360FrameSummary;

    public function activateSet(string $setPublicId, UserId $actorUserId): Product360SetSummary;
}
