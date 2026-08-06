<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\ValueObject;

use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;

/**
 * Reference to the legacy TriCab customer identifier, retained for migration
 * traceability only. Never used as the authoritative identity.
 */
final class LegacyCustomerId
{
    private function __construct(private readonly int $value)
    {
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw InvalidCustomerValue::for('LegacyCustomerId', (string) $value);
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
