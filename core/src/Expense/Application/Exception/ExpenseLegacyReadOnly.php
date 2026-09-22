<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Exception;

use RuntimeException;

final class ExpenseLegacyReadOnly extends RuntimeException
{
    public static function mutationBlocked(): self
    {
        return new self(
            'This expense is from the previous TriExpense system and is read-only.',
        );
    }

    public static function invalidIdentifier(string $raw): self
    {
        return new self('Invalid legacy TriExpense identifier: ' . $raw);
    }
}
