<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;

final class ExpensePeriodStatus
{
    public const OPEN = 'open';
    public const CLOSED = 'closed';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, [self::OPEN, self::CLOSED], true)) {
            throw InvalidExpenseValue::for('ExpensePeriodStatus', $value);
        }

        return new self($normalized);
    }

    public static function open(): self
    {
        return new self(self::OPEN);
    }

    public static function closed(): self
    {
        return new self(self::CLOSED);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isOpen(): bool
    {
        return $this->value === self::OPEN;
    }

    public function isClosed(): bool
    {
        return $this->value === self::CLOSED;
    }
}