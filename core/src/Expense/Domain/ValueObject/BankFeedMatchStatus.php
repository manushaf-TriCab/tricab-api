<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;

/** Early bank-feed concept — phased implementation. */
final class BankFeedMatchStatus
{
    public const UNMATCHED = 'unmatched';
    public const MATCHED = 'matched';
    public const AMBIGUOUS = 'ambiguous';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (!in_array($normalized, [self::UNMATCHED, self::MATCHED, self::AMBIGUOUS], true)) {
            throw InvalidExpenseValue::for('BankFeedMatchStatus', $value);
        }

        return new self($normalized);
    }

    public static function unmatched(): self
    {
        return new self(self::UNMATCHED);
    }

    public static function matched(): self
    {
        return new self(self::MATCHED);
    }

    public static function ambiguous(): self
    {
        return new self(self::AMBIGUOUS);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function isUnmatched(): bool
    {
        return $this->value === self::UNMATCHED;
    }

    public function isMatched(): bool
    {
        return $this->value === self::MATCHED;
    }

    public function isAmbiguous(): bool
    {
        return $this->value === self::AMBIGUOUS;
    }
}