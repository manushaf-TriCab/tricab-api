<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class CatalogueNodeProductStyleRecord
{
    public function __construct(
        public string $productStylePublicId,
        public string $styleCode,
        public string $displayName,
        public int $displayOrder,
        public bool $isActive,
        public int $childStyleCount,
        public int $assignedProductCount,
    ) {
    }
}
