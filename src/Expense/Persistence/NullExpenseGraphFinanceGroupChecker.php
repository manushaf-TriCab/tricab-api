<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseGraphFinanceGroupChecker;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/** Default null Graph checker — always deny until a live Graph adapter is wired. */
final class NullExpenseGraphFinanceGroupChecker implements ExpenseGraphFinanceGroupChecker
{
    public function isMemberOfFinanceGroup(UserId $userId, string $email): bool
    {
        unset($userId, $email);

        return false;
    }
}
