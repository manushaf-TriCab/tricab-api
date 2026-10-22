<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class PublicCatalogueTreeNode
{
    /**
     * @param list<self> $children
     * @param list<PublicCatalogueProductCard> $products
     * @param int $productCount Published, region-visible products placed directly on this node
     *                          (excludes descendants and style-family links).
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
        public int $depth,
        public bool $hasChildren,
        public bool $hasProducts,
        public array $children = [],
        public array $products = [],
        public ?string $parentPublicId = null,
        public ?string $productThumbnailUrl = null,
        public int $productCount = 0,
    ) {
    }
}
