<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class CatalogueNodeRecord
{
    /**
     * @param list<string> $regionCodes empty = global
     */
    public function __construct(
        public string $publicId,
        public string $tabPublicId,
        public string $tabSlug,
        public string $tabName,
        public ?string $parentPublicId,
        public ?string $parentName,
        public string $nodeCode,
        public string $nodeName,
        public string $slug,
        public ?string $description,
        public string $nodeTypeCode,
        public ?string $imageUrl,
        public ?string $imageAltText,
        public ?string $imageMediaPublicId,
        public int $displayOrder,
        public bool $isActive,
        public bool $isPublic,
        public bool $isDeleted,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public string $rowVersion,
        public int $depth = 0,
        public int $childCount = 0,
        public int $productCount = 0,
        public array $regionCodes = [],
        public ?string $createdAtUtc = null,
        public ?string $updatedAtUtc = null,
    ) {
    }
}
