<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Port for Microsoft Graph Tricab-Finance group membership.
 * Implementations must be deny-safe: failures return false, never throw to callers.
 */
interface ExpenseGraphFinanceGroupChecker
{
    public function isMemberOfFinanceGroup(UserId $userId, string $email): bool;
}
