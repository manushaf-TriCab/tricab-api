<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Entity;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;

final class ProductCode
{
    public function __construct(
        private readonly ProductCodeValue $codeValue,
        private readonly ?ProductId $productId,
        private readonly ?ProductVariantId $productVariantId = null,
        private readonly bool $isPrimary = true,
        private readonly ?string $codeExplanation = null,
    ) {
        if ($productId === null && $productVariantId === null) {
            throw InvalidProductValue::for('ProductCodeOwner', 'missing');
        }

        if ($codeExplanation !== null && mb_strlen($codeExplanation) > 1000) {
            throw InvalidProductValue::for('CodeExplanation', 'too-long');
        }
    }

    public function codeValue(): ProductCodeValue
    {
        return $this->codeValue;
    }

    public function productId(): ?ProductId
    {
        return $this->productId;
    }

    public function productVariantId(): ?ProductVariantId
    {
        return $this->productVariantId;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function codeExplanation(): ?string
    {
        return $this->codeExplanation;
    }
}
