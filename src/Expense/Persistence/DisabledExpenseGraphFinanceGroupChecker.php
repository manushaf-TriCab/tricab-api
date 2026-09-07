<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseGraphFinanceGroupChecker;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/** Graph finance group checks disabled — always deny. */
final class DisabledExpenseGraphFinanceGroupChecker implements ExpenseGraphFinanceGroupChecker
{
    public function isMemberOfFinanceGroup(UserId $userId, string $email): bool
    {
        unset($userId, $email);

        return false;
    }
}
