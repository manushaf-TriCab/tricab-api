<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;

/**
 * Receipt workflow status — mirrors legacy TE_STATUS_*.
 */
final class ExpenseReceiptStatus
{
    public const DRAFT = 'draft';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    private const ALL = [self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, self::ALL, true)) {
            throw InvalidExpenseValue::for('ExpenseReceiptStatus', $value);
        }

        return new self($normalized);
    }

    public static function draft(): self
    {
        return new self(self::DRAFT);
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    public static function approved(): self
    {
        return new self(self::APPROVED);
    }

    public static function rejected(): self
    {
        return new self(self::REJECTED);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isDraft(): bool
    {
        return $this->value === self::DRAFT;
    }

    public function isPending(): bool
    {
        return $this->value === self::PENDING;
    }

    public function isApproved(): bool
    {
        return $this->value === self::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->value === self::REJECTED;
    }

    public function isEditableByOwner(): bool
    {
        return $this->isDraft() || $this->isRejected();
    }
}