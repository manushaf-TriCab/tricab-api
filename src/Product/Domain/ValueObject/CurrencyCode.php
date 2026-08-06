<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

/**
 * ISO-4217 style currency code. Seeded Phase 1 values are preferred;
 * any 3-letter uppercase code is accepted so admins can extend Currencies.
 */
final class CurrencyCode
{
    private const PATTERN = '/^[A-Z]{3}$/';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw InvalidProductValue::for('CurrencyCode', $value);
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
