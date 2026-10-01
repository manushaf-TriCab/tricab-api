<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain;

use TriCab\Core\CustomerContactReport\Domain\Exception\InvalidCustomerContactReportValue;

/**
 * Non-negative decimal money stored as DECIMAL(18,2).
 * Comparisons use bcmath, not PHP floats.
 */
final readonly class CustomerContactReportMoney
{
    private function __construct(
        private string $decimal,
    ) {
    }

    public static function fromDecimal(string $raw, string $field): self
    {
        $value = trim($raw);
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value) || !is_numeric($value)) {
            throw InvalidCustomerContactReportValue::for($field, 'must be a non-negative amount with at most two decimal places.');
        }

        $normalized = bcadd($value, '0', 2);
        if (bccomp($normalized, '0', 2) < 0) {
            throw InvalidCustomerContactReportValue::for($field, 'must not be negative.');
        }
        $whole = explode('.', $normalized)[0];
        if (strlen($whole) > 16) {
            throw InvalidCustomerContactReportValue::for($field, 'exceeds DECIMAL(18,2).');
        }

        return new self($normalized);
    }

    public function toDecimal(): string
    {
        return $this->decimal;
    }

    public function isPositive(): bool
    {
        $decimal = $this->decimal;
        if (!is_numeric($decimal)) {
            return false;
        }

        return bccomp($decimal, '0', 2) === 1;
    }

    public function greaterThan(self $other): bool
    {
        $left = $this->decimal;
        $right = $other->decimal;
        if (!is_numeric($left) || !is_numeric($right)) {
            return false;
        }

        return bccomp($left, $right, 2) === 1;
    }
}
