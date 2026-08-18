<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductMediaFileRecord;
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/** Future Azure SQL: ProductMedia + typed media tables (metadata only). */
interface ProductMediaRepository
{
    /**
     * @return list<ProductMediaSummary>
     */
    public function listByProduct(ProductPublicId $productPublicId): array;

    public function findByPublicId(string $mediaPublicId): ?ProductMediaFileRecord;

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

    /**
     * @param array{
     *   mediaCategoryCode: string,
     *   mediaTypeCode: string,
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
     *   altText?: ?string,
     *   caption?: ?string,
     *   displayOrder?: int,
     *   isPrimary?: bool
     * } $data
     */
    public function createUploadSession(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductMediaFileRecord;

    /**
     * @param array{
     *   uploadStatusCode: string,
     *   scanStatusCode: string,
     *   publicUrl?: ?string,
     *   contentType?: ?string,
     *   byteSize?: ?int,
     *   etag?: ?string,
     *   widthPx?: ?int,
     *   heightPx?: ?int,
     *   thumbnailUrl?: ?string,
     *   storagePath?: ?string,
     *   storageContainer?: ?string
     * } $blobMeta
     */
    public function markUploadCompleted(
        string $mediaPublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): ProductMediaFileRecord;

    /**
     * @param array{
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
    public function beginReplaceUpload(
        string $mediaPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductMediaFileRecord;

    public function clearFile(string $mediaPublicId, UserId $actorUserId): void;

    public function setPrimary(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        UserId $actorUserId,
    ): void;

    /**
     * Updates ProductImages.ImageRoleCode (admin mediaCategoryCode).
     * Exclusive roles (e.g. cross-section) should demote previous holders to gallery.
     */
    public function updateImageRole(
        ProductPublicId $productPublicId,
        string $mediaPublicId,
        string $imageRoleCode,
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
