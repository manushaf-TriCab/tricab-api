<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\ValueObject;

use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;

final class CartLineId
{
    private function __construct(private readonly int $value)
    {
    }

    public static function fromInt(int $value): self
    {
        if ($value < 1) {
            throw InvalidCartValue::for('CartLineId', (string) $value);
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
