<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;

/**
 * Manager-stage status — mirrors legacy TE_APPROVAL_* (same string values as receipt status).
 * Empty string is valid for drafts that have never been submitted.
 */
final class ManagerApprovalStatus
{
    public const NONE = '';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    private const ALL = [self::NONE, self::PENDING, self::APPROVED, self::REJECTED];

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(?string $value): self
    {
        $normalized = strtolower(trim((string) $value));
        if (!in_array($normalized, self::ALL, true)) {
            throw InvalidExpenseValue::for('ManagerApprovalStatus', (string) $value);
        }

        return new self($normalized);
    }

    public static function none(): self
    {
        return new self(self::NONE);
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
}