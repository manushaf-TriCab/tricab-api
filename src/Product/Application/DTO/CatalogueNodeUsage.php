<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class CatalogueNodeUsage
{
    public function __construct(
        public string $publicId,
        public int $childCount,
        public int $activeChildCount,
        public int $productCount,
        public int $regionCount,
        public string $summary,
    ) {
    }
}
