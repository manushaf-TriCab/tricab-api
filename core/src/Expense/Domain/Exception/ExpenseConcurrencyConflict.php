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
}
