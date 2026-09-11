<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\ValueObject;

use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;

final class ExpensePeriodPublicId
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '' || preg_match(self::UUID_PATTERN, $normalized) !== 1) {
            throw InvalidExpenseValue::for('ExpensePeriodPublicId', $value);
        }

        return new self($normalized);
    }

    public static function generate(): self
    {
        return new self(ExpenseReceiptPublicId::generate()->toString());
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
