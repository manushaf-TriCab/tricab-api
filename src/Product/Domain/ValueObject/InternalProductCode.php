<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;

final class InternalProductCode
{
    private const MAX_LENGTH = 100;

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = trim($value);

        if ($normalized === '' || mb_strlen($normalized) > self::MAX_LENGTH) {
            throw InvalidProductValue::for('InternalProductCode', $value);
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
