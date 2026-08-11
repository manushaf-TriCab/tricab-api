<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class PublicCatalogueProductCard
{
    public function __construct(
        public string $publicId,
        public string $name,
        public string $slug,
        public ?string $internalCode,
        public ?string $thumbnailUrl,
        public int $displayOrder,
        public bool $isFeatured,
    ) {
    }
}
