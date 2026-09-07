<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Port for finance capability. Stage 2 uses profile.is_finance;
 * later may also check AAD Tricab-Finance server-side — never client-exposed.
 */
interface ExpenseFinanceCapabilityResolver
{
    public function hasFinanceCapability(UserId $userId, ExpenseProfile $profile): bool;
}