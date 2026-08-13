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
    ) {
    }
}
