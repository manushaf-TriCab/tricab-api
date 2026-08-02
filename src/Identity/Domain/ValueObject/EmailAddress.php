<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\ValueObject;

use TriCab\Core\Identity\Domain\Exception\InvalidIdentityValue;

final class EmailAddress
{
    private const MAX_LENGTH = 320;

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if (
            $normalized === ''
            || strlen($normalized) > self::MAX_LENGTH
            || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw InvalidIdentityValue::for('EmailAddress', $value);
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
