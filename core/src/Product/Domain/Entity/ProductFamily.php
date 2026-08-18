<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Entity;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyId;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyPublicId;

final class ProductFamily
{
    private readonly string $familyCode;
    private readonly string $familyName;

    public function __construct(
        private readonly ?ProductFamilyId $id,
        private readonly ProductFamilyPublicId $publicId,
        string $familyCode,
        string $familyName,
        private readonly DisplayOrder $displayOrder,
        private readonly ?string $description = null,
        private readonly bool $isActive = true,
    ) {
        $code = trim($familyCode);
        $name = trim($familyName);
        if ($code === '') {
            throw InvalidProductValue::for('FamilyCode', $familyCode);
        }
        if ($name === '') {
            throw InvalidProductValue::for('FamilyName', $familyName);
        }
        $this->familyCode = $code;
        $this->familyName = $name;
    }

    public function id(): ?ProductFamilyId
    {
        return $this->id;
    }

    public function publicId(): ProductFamilyPublicId
    {
        return $this->publicId;
    }

    public function familyCode(): string
    {
        return $this->familyCode;
    }

    public function familyName(): string
    {
        return $this->familyName;
    }

    public function displayOrder(): DisplayOrder
    {
        return $this->displayOrder;
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
