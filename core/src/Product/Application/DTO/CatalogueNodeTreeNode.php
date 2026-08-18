<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class CatalogueNodeTreeNode
{
    /**
     * @param list<self> $children
     * @param list<string> $regionCodes
     */
    public function __construct(
        public string $publicId,
        public string $nodeCode,
        public string $nodeName,
        public string $slug,
        public ?string $description,
        public string $nodeTypeCode,
        public ?string $imageUrl,
        public ?string $imageAltText,
        public int $displayOrder,
        public bool $isActive,
        public bool $isPublic,
        public int $depth,
        public int $childCount,
        public int $productCount,
        public array $children = [],
        public array $regionCodes = [],
        public ?string $rowVersion = null,
        public ?string $parentPublicId = null,
    ) {
    }
}
