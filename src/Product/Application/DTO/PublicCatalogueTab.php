<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class PublicCatalogueTab
{
    public function __construct(
        public string $publicId,
        public string $tabCode,
        public string $tabName,
        public ?string $description,
        public string $slug,
        public int $displayOrder,
    ) {
    }
}
