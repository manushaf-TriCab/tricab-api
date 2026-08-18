<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

final class SpecificationScope
{
    public const PRODUCT = 'product';
    public const VARIANT = 'variant';
    public const BOTH = 'both';

    private const ALLOWED = [self::PRODUCT, self::VARIANT, self::BOTH];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw InvalidProductValue::for('SpecificationScope', $value);
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function allowsProduct(): bool
    {
        return $this->value === self::PRODUCT || $this->value === self::BOTH;
    }

    public function allowsVariant(): bool
    {
        return $this->value === self::VARIANT || $this->value === self::BOTH;
    }
}
