<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

final class ProductVariantId
{
    private function __construct(private readonly int $value)
    {
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw InvalidProductValue::for('ProductVariantId', (string) $value);
        }

        return new self($value);
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
