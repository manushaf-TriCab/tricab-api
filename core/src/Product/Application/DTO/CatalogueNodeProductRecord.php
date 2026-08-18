<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class CatalogueNodeProductRecord
{
    public function __construct(
        public string $productPublicId,
        public string $productName,
        public string $productSlug,
        public ?string $internalCode,
        public ?string $thumbnailUrl,
        public int $displayOrder,
        public bool $isFeatured,
        public bool $isActive,
        public string $publicationStatus,
    ) {
    }
}
