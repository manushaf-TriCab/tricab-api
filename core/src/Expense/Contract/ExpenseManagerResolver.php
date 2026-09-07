<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Precedence: Tri Expense explicit manager → employee manager fallback.
 */
interface ExpenseManagerResolver
{
    public function resolveManagerUserId(ExpenseProfile $profile): ?UserId;
}