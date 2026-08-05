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
    ) {
    }
}
