<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductSearchResult
{
    public function __construct(
        public string $publicId,
        public string $productName,
        public ?string $productCode,
        public ?string $slug,
        public ?string $categoryName,
        public ?string $seriesName,
        public string $statusCode,
        public ?string $thumbnailUrl,
        public ?string $thumbnailImageUrl = null,
        public ?string $thumbnailBackgroundColor = null,
    ) {
    }
}
