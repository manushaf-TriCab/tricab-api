<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/** Future Azure SQL: ProductMedia + typed media tables (metadata only). */
interface ProductMediaRepository
{
    /**
     * @return list<ProductMediaSummary>
     */
    public function listByProduct(ProductPublicId $productPublicId): array;

    /**
     * @param array{
     *   mediaCategoryCode: string,
     *   mediaTypeCode: string,
     *   title: string,
     *   storagePath: string,
     *   storageContainer?: ?string,
     *   contentType?: ?string,
     *   fileExtension?: ?string,
     *   byteSize?: ?int,
     *   altText?: ?string,
     *   caption?: ?string,
     *   displayOrder?: int,
     *   isPrimary?: bool,
     *   publicUrl?: ?string
     * } $data
     */
    public function create(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductMediaSummary;

    public function setPrimary(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        UserId $actorUserId,
    ): void;

    /**
     * @param list<string> $orderedMediaPublicIds
     */
    public function reorder(
        ProductPublicId $productPublicId,
        array $orderedMediaPublicIds,
        UserId $actorUserId,
    ): void;

    public function archive(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        UserId $actorUserId,
    ): void;
}
