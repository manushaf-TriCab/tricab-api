<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Rich media row for upload lifecycle (admin / command side).
 */
final readonly class ProductMediaFileRecord
{
    public function __construct(
        public string $publicId,
        public string $productPublicId,
        public string $mediaCategoryCode,
        public string $mediaTypeCode,
        public string $title,
        public ?string $publicUrl,
        public ?string $altText,
        public int $displayOrder,
        public bool $isPrimary,
        public string $uploadStatusCode,
        public string $scanStatusCode,
        public ?int $byteSize,
        public ?string $contentType,
        public ?string $originalFileName,
        public ?string $fileExtension,
        public ?int $widthPx,
        public ?int $heightPx,
        public ?string $thumbnailUrl,
        public ?string $storagePath,
        public ?string $storageContainer,
        public ?string $pendingStoragePath = null,
        public ?string $pendingStorageContainer = null,
        public ?string $previousStoragePath = null,
    ) {
    }

    public function toSummary(bool $includeStoragePath = false): ProductMediaSummary
    {
        return new ProductMediaSummary(
            publicId: $this->publicId,
            mediaCategoryCode: $this->mediaCategoryCode,
            mediaTypeCode: $this->mediaTypeCode,
            title: $this->title,
            publicUrl: $this->publicUrl,
            altText: $this->altText,
            displayOrder: $this->displayOrder,
            isPrimary: $this->isPrimary,
            uploadStatusCode: $this->uploadStatusCode,
            scanStatusCode: $this->scanStatusCode,
            byteSize: $this->byteSize,
            contentType: $this->contentType,
            originalFileName: $this->originalFileName,
            widthPx: $this->widthPx,
            heightPx: $this->heightPx,
            thumbnailUrl: $this->thumbnailUrl,
            storagePath: $includeStoragePath ? $this->storagePath : null,
            storageContainer: $includeStoragePath ? $this->storageContainer : null,
        );
    }
}
