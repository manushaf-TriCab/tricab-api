<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;

/**
 * Non-negative monetary amount as a decimal string (never float storage).
 * Scale matches legacy receipt DECIMAL semantics (up to 2 fractional digits for API;
 * accepts up to 6 for migration safety).
 */
final class ExpenseMoney
{
    private function __construct(private readonly string $amount)
    {
    }

    public static function fromString(string $amount): self
    {
        $normalized = trim($amount);
        if ($normalized === '' || !preg_match('/^\d+(\.\d{1,6})?$/', $normalized)) {
            throw InvalidExpenseValue::for('ExpenseMoney', $amount);
        }

        // Strip trailing zeros after decimal for stable equality, keep integer part.
        if (str_contains($normalized, '.')) {
            $normalized = rtrim(rtrim($normalized, '0'), '.');
            if ($normalized === '') {
                $normalized = '0';
            }
        }

        return new self($normalized);
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->amount, $other->amount, 6) === 0;
    }
}