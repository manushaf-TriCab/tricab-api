<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductArModelSummary;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

interface ProductArModelRepository
{
    public function findByPublicId(string $arPublicId): ?ProductArModelSummary;

    /**
     * @return list<ProductArModelSummary>
     */
    public function listByProduct(ProductPublicId $productPublicId): array;

    /**
     * @param array{
     *   title: string,
     *   originalFileName: string,
     *   contentType: string,
     *   byteSize: int,
     *   fileExtension: string,
     *   storagePath: string,
     *   storageContainer: string,
     *   uploadStatusCode: string,
     *   scanStatusCode: string,
     *   publicId?: string,
     *   isPrimary?: bool
     * } $data
     */
    public function createUploadSession(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductArModelSummary;

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
    public function markUploadCompleted(
        string $arPublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): ProductArModelSummary;
}
