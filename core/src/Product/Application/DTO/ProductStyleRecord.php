<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductStyleRecord
{
    /**
     * @param list<array{publicId: string, styleCode: string, displayName: string}> $ancestors
     * @param list<array{publicId: string, styleCode: string, displayName: string, isActive: bool}> $children
     */
    public function __construct(
        public string $publicId,
        public string $styleCode,
        public string $displayName,
        public ?string $parentPublicId,
        public ?string $parentStyleCode,
        public ?string $parentDisplayName,
        public int $sortOrder,
        public bool $isActive,
        public bool $isDeleted,
        public string $rowVersion,
        public ProductStyleInheritanceConfig $inheritance,
        public ?string $createdAtUtc = null,
        public ?string $updatedAtUtc = null,
        public int $productCount = 0,
        public int $childCount = 0,
        public int $activeChildCount = 0,
        public array $ancestors = [],
        public array $children = [],
    ) {
    }
}
