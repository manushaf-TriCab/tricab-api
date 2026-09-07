<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Contract;

use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface ExpenseProfileRepository
{
    public function findByUserId(UserId $userId): ?ExpenseProfile;

    public function findByLegacyLogin(string $legacyLogin): ?ExpenseProfile;

    public function findByLegacyEmail(string $legacyEmail): ?ExpenseProfile;

    public function save(ExpenseProfile $profile): ExpenseProfile;
}