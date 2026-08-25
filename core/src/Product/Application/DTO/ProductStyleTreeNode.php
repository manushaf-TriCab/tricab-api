<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductStyleTreeNode
{
    /**
     * @param list<self> $children
     */
    public function __construct(
        public string $publicId,
        public string $styleCode,
        public string $displayName,
        public ?string $parentPublicId,
        public int $sortOrder,
        public bool $isActive,
        public int $childCount,
        public int $productCount,
        public array $children = [],
    ) {
    }
}
