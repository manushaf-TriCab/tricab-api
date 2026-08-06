<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductClassificationRecord
{
    public function __construct(
        public string $publicId,
        public string $code,
        public string $name,
        public ?string $description,
        public ?string $parentPublicId,
        public ?string $parentName,
        public int $displayOrder,
        public bool $isActive,
        public bool $isDeleted,
        public string $rowVersion,
        public ?string $createdAtUtc = null,
        public ?string $updatedAtUtc = null,
        public int $productCount = 0,
        public int $activeChildCount = 0,
        public int $inactiveChildCount = 0,
    ) {
    }

    public function toLookupItem(): ProductLookupItem
    {
        return new ProductLookupItem(
            publicId: $this->publicId,
            code: $this->code,
            name: $this->name,
            parentPublicId: $this->parentPublicId,
            displayOrder: $this->displayOrder,
            isActive: $this->isActive,
        );
    }
}
