<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\ValueObject;

use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;

/**
 * Optional human-facing customer code. When present it must be non-empty and
 * fit within the NVARCHAR(100) storage column.
 */
final class CustomerCode
{
    private const MAX_LENGTH = 100;

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = trim($value);

        if ($normalized === '' || strlen($normalized) > self::MAX_LENGTH) {
            throw InvalidCustomerValue::for('CustomerCode', $value);
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
