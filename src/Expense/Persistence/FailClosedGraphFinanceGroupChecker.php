<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use Throwable;
use TriCab\Core\Expense\Contract\ExpenseGraphFinanceGroupChecker;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Deny-safe wrapper: any throwable from the inner Graph checker becomes false.
 */
final class FailClosedGraphFinanceGroupChecker implements ExpenseGraphFinanceGroupChecker
{
    public function __construct(
        private readonly ExpenseGraphFinanceGroupChecker $inner,
    ) {
    }

    public function isMemberOfFinanceGroup(UserId $userId, string $email): bool
    {
        try {
            return $this->inner->isMemberOfFinanceGroup($userId, $email);
        } catch (Throwable) {
            return false;
        }
    }
}
