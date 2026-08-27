<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\ValueObject;

use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;

final class CartStatus
{
    public const OPEN = 'OPEN';
    public const SUBMITTED = 'SUBMITTED';
    public const ABANDONED = 'ABANDONED';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (!in_array($normalized, [self::OPEN, self::SUBMITTED, self::ABANDONED], true)) {
            throw InvalidCartValue::for('CartStatus', $value);
        }

        return new self($normalized);
    }

    public static function open(): self
    {
        return new self(self::OPEN);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function isOpen(): bool
    {
        return $this->value === self::OPEN;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
