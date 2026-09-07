<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Exception;

use RuntimeException;

final class ExpenseManagerRequired extends RuntimeException
{
    public static function forSubmit(): self
    {
        return new self('A manager must be resolved before a receipt can be submitted.');
    }
}