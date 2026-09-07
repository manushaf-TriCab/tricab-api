<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseFinanceCapabilityResolver;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Stage 2: profile.is_finance only. Graph Tricab-Finance can be composed later.
 * ACCESS permission alone never grants finance.
 */
final class ProfileFinanceCapabilityResolver implements ExpenseFinanceCapabilityResolver
{
    public function hasFinanceCapability(UserId $userId, ExpenseProfile $profile): bool
    {
        unset($userId);

        return $profile->isFinance();
    }
}