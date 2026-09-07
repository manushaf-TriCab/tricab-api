<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;

/**
 * Preserves historical receipt currencies including non-ISO legacy value "Other".
 */
final class ExpenseCurrency
{
    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = trim($value);
        if ($normalized === '') {
            throw InvalidExpenseValue::for('ExpenseCurrency', $value);
        }

        if (strcasecmp($normalized, 'Other') === 0) {
            return new self('Other');
        }

        $upper = strtoupper($normalized);
        if (preg_match('/^[A-Z]{3}$/', $upper) !== 1) {
            throw InvalidExpenseValue::for('ExpenseCurrency', $value);
        }

        return new self($upper);
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