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

        return new self(self::normalizeDecimal($normalized));
    }

    /** Bank-feed amounts may be negative (credits / payments). */
    public static function fromSignedString(string $amount): self
    {
        $normalized = trim(str_replace([',', ' '], '', $amount));
        if ($normalized === '' || !preg_match('/^-?\d+(\.\d{1,6})?$/', $normalized)) {
            throw InvalidExpenseValue::for('ExpenseMoney', $amount);
        }

        return new self(self::normalizeDecimal($normalized));
    }

    private static function normalizeDecimal(string $normalized): string
    {
        $negative = str_starts_with($normalized, '-');
        $body = $negative ? substr($normalized, 1) : $normalized;
        if (str_contains($body, '.')) {
            $body = rtrim(rtrim($body, '0'), '.');
            if ($body === '') {
                $body = '0';
            }
        }

        if ($body === '0') {
            return '0';
        }

        return $negative ? '-' . $body : $body;
    }

    public function amount(): string
    {
        return $this->amount;
    }

    public function abs(): self
    {
        return str_starts_with($this->amount, '-')
            ? new self(substr($this->amount, 1))
            : $this;
    }

    public function negate(): self
    {
        if ($this->amount === '0') {
            return $this;
        }

        return str_starts_with($this->amount, '-')
            ? new self(substr($this->amount, 1))
            : new self('-' . $this->amount);
    }

    public function add(self $other): self
    {
        return new self(self::normalizeDecimal(bcadd($this->amount, $other->amount, 6)));
    }

    public function equals(self $other): bool
    {
        return bccomp($this->amount, $other->amount, 6) === 0;
    }

    /** Absolute difference within tolerance (both as decimal strings). */
    public function withinTolerance(self $other, string $tolerance): bool
    {
        $diff = bcsub($this->amount, $other->amount, 6);
        if (str_starts_with($diff, '-')) {
            $diff = substr($diff, 1);
        }

        return bccomp($diff, $tolerance, 6) <= 0;
    }
}