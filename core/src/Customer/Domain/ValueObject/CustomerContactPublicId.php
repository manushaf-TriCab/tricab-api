<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Domain\ValueObject;

use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;

final class CustomerContactPublicId
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || preg_match(self::UUID_PATTERN, $normalized) !== 1) {
            throw InvalidCustomerValue::for('CustomerContactPublicId', $value);
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
