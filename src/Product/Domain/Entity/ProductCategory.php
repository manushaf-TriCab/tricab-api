<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Entity;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryId;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyId;

final class ProductCategory
{
    private readonly string $categoryCode;
    private readonly string $categoryName;

    public function __construct(
        private readonly ?ProductCategoryId $id,
        private readonly ProductCategoryPublicId $publicId,
        string $categoryCode,
        string $categoryName,
        private readonly DisplayOrder $displayOrder,
        private readonly ?ProductFamilyId $familyId = null,
        private readonly ?string $description = null,
        private readonly bool $isActive = true,
    ) {
        $code = trim($categoryCode);
        $name = trim($categoryName);
        if ($code === '') {
            throw InvalidProductValue::for('CategoryCode', $categoryCode);
        }
        if ($name === '') {
            throw InvalidProductValue::for('CategoryName', $categoryName);
        }
        $this->categoryCode = $code;
        $this->categoryName = $name;
    }

    public function id(): ?ProductCategoryId
    {
        return $this->id;
    }

    public function publicId(): ProductCategoryPublicId
    {
        return $this->publicId;
    }

    public function categoryCode(): string
    {
        return $this->categoryCode;
    }

    public function categoryName(): string
    {
        return $this->categoryName;
    }

    public function displayOrder(): DisplayOrder
    {
        return $this->displayOrder;
    }

    public function familyId(): ?ProductFamilyId
    {
        return $this->familyId;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
