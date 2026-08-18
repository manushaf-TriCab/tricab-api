<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\Product3DModelSummary;
use TriCab\Core\Product\Application\DTO\ProductPublic3DPayload;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

interface Product3DModelRepository
{
    public function findByPublicId(string $modelPublicId): ?Product3DModelSummary;

    /**
     * @return list<Product3DModelSummary>
     */
    public function listByProduct(ProductPublicId $productPublicId): array;

    public function listPublicByProduct(ProductPublicId $productPublicId): ProductPublic3DPayload;

    /**
     * @param array{
     *   publicId?: string,
     *   name: string,
     *   modelTypeCode: string,
     *   sourceTypeCode: string,
     *   statusCode: string,
     *   uploadStatusCode: string,
     *   scanStatusCode: string,
     *   storagePath?: ?string,
     *   storageContainer?: ?string,
     *   mimeType?: ?string,
     *   fileExtension?: ?string,
     *   fileSizeBytes?: ?int,
     *   originalFileName?: ?string,
     *   fileName?: ?string,
     *   viewerConfigurationJson: string,
     *   productVariantPublicId?: ?string,
     *   isPrimary?: bool,
     *   displayOrder?: int
     * } $data
     */
    public function create(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): Product3DModelSummary;

    /**
     * @param array{
     *   name?: string,
     *   viewerConfigurationJson?: string,
     *   materialOverridesJson?: ?string,
     *   previewImagePublicUrl?: ?string,
     *   previewImageStoragePath?: ?string,
     *   displayOrder?: int,
     *   productVariantPublicId?: ?string
     * } $data
     */
    public function update(
        string $modelPublicId,
        array $data,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary;

    /**
     * @param array{
     *   uploadStatusCode: string,
     *   scanStatusCode: string,
     *   statusCode: string,
     *   publicUrl?: ?string,
     *   mimeType?: ?string,
     *   fileSizeBytes?: ?int
     * } $blobMeta
     */
    public function markUploadCompleted(
        string $modelPublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): Product3DModelSummary;

    public function publish(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary;

    public function unpublish(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary;

    public function setPrimary(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary;

    public function softDelete(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary;
}
