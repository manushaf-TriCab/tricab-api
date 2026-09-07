<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseFinanceCapabilityResolver;
use TriCab\Core\Expense\Contract\ExpenseGraphFinanceGroupChecker;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Grants finance when profile.isFinance OR Graph Tricab-Finance membership.
 * ACCESS permission alone never grants finance.
 */
final class OrExpenseFinanceCapabilityResolver implements ExpenseFinanceCapabilityResolver
{
    public function __construct(
        private readonly ExpenseGraphFinanceGroupChecker $graphChecker,
    ) {
    }

    public function hasFinanceCapability(UserId $userId, ExpenseProfile $profile): bool
    {
        if ($profile->isFinance()) {
            return true;
        }

        return $this->graphChecker->isMemberOfFinanceGroup($userId, $profile->legacyEmail());
    }
}
