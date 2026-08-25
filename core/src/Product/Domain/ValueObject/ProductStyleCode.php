<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

final class ProductStyleCode
{
    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if ($normalized === '' || strlen($normalized) > 50) {
            throw InvalidProductValue::for('ProductStyleCode', $value);
        }
        if (preg_match('/^[A-Z0-9][A-Z0-9._-]*$/', $normalized) !== 1) {
            throw InvalidProductValue::for('ProductStyleCode', $value);
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
