<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Module;

/**
 * Finance reconciliation resolution — distinct from receipt workflow status.
 */
final class ExpenseFinanceResolution
{
    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        $allowed = array_merge(
            Module::BANK_RESOLUTIONS,
            Module::RECEIPT_FINANCE_RESOLUTIONS,
            [Module::RESOLUTION_OFFSET],
        );
        if (!in_array($normalized, $allowed, true)) {
            throw InvalidExpenseValue::for('ExpenseFinanceResolution', $value);
        }

        return new self($normalized);
    }

    public static function writtenOff(): self
    {
        return new self(Module::RESOLUTION_WRITTEN_OFF);
    }

    public static function exception(): self
    {
        return new self(Module::RESOLUTION_EXCEPTION);
    }

    public static function chased(): self
    {
        return new self(Module::RESOLUTION_CHASED);
    }

    public static function markedCash(): self
    {
        return new self(Module::RESOLUTION_MARKED_CASH);
    }

    public static function forceApproved(): self
    {
        return new self(Module::RESOLUTION_FORCE_APPROVED);
    }

    public static function forceRejected(): self
    {
        return new self(Module::RESOLUTION_FORCE_REJECTED);
    }

    public static function offset(): self
    {
        return new self(Module::RESOLUTION_OFFSET);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isChased(): bool
    {
        return $this->value === Module::RESOLUTION_CHASED;
    }

    public function isOffset(): bool
    {
        return $this->value === Module::RESOLUTION_OFFSET;
    }

    public function isForceApproved(): bool
    {
        return $this->value === Module::RESOLUTION_FORCE_APPROVED;
    }

    public function isForceRejected(): bool
    {
        return $this->value === Module::RESOLUTION_FORCE_REJECTED;
    }

    public function isAllowedForBank(): bool
    {
        return in_array($this->value, Module::BANK_RESOLUTIONS, true);
    }

    public function isAllowedForReceipt(): bool
    {
        return in_array($this->value, Module::RECEIPT_FINANCE_RESOLUTIONS, true);
    }
}
