<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Domain\Exception;

use DomainException;

final class ExpenseConcurrencyConflict extends DomainException
{
    public static function forReceipt(): self
    {
        return new self('Expense receipt was modified by another request. Refresh and retry.');
    }

    public static function forBankFeed(): self
    {
        return new self('Bank feed entry was modified by another request. Refresh and retry.');
    }

    public static function forPeriod(): self
    {
        return new self('Expense period was modified by another request. Refresh and retry.');
    }
}
