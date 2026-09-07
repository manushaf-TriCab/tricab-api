<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductMediaSummary
{
    public function __construct(
        public string $publicId,
        public string $mediaCategoryCode,
        public string $mediaTypeCode,
        public string $title,
        public ?string $publicUrl,
        public ?string $altText,
        public int $displayOrder,
        public bool $isPrimary,
        public ?string $uploadStatusCode = 'active',
        public ?string $scanStatusCode = 'not_required',
        public ?int $byteSize = null,
        public ?string $contentType = null,
        public ?string $originalFileName = null,
        public ?int $widthPx = null,
        public ?int $heightPx = null,
        public ?string $thumbnailUrl = null,
        public ?string $storagePath = null,
        public ?string $storageContainer = null,
        /** @var list<string> Canonical presentation roles from ProductImageRoleAssignments. */
        public array $presentationRoles = [],
    ) {
    }

    /**
     * @param list<string> $presentationRoles
     */
    public function withPresentationRoles(array $presentationRoles): self
    {
        return new self(
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
            storagePath: $this->storagePath,
            storageContainer: $this->storageContainer,
            presentationRoles: array_values($presentationRoles),
        );
    }
}
