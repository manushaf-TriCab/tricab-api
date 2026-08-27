<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\ValueObject;

use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;

final class CartPricingMode
{
    public const SELF_CUSTOMER = 'SELF_CUSTOMER';
    public const STAFF_SELECTED_CUSTOMER = 'STAFF_SELECTED_CUSTOMER';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (!in_array($normalized, [self::SELF_CUSTOMER, self::STAFF_SELECTED_CUSTOMER], true)) {
            throw InvalidCartValue::for('CartPricingMode', $value);
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
