<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * URL slug for a product page (NVARCHAR(250)).
 * Lowercase kebab-case: letters, digits, hyphens.
 */
final class ProductSlug
{
    private const MAX_LENGTH = 250;
    private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (
            $normalized === ''
            || mb_strlen($normalized) > self::MAX_LENGTH
            || preg_match(self::PATTERN, $normalized) !== 1
        ) {
            throw InvalidProductValue::for('ProductSlug', $value);
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
