<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class InMemoryExpenseProfileRepository implements ExpenseProfileRepository
{
    /** @var array<int, ExpenseProfile> */
    private array $byUserId = [];

    public function findByUserId(UserId $userId): ?ExpenseProfile
    {
        return $this->byUserId[$userId->toInt()] ?? null;
    }

    public function findByLegacyLogin(string $legacyLogin): ?ExpenseProfile
    {
        $needle = strtolower(trim($legacyLogin));
        foreach ($this->byUserId as $profile) {
            if (strtolower($profile->legacyLogin()) === $needle) {
                return $profile;
            }
        }

        return null;
    }

    public function findByLegacyEmail(string $legacyEmail): ?ExpenseProfile
    {
        $needle = strtolower(trim($legacyEmail));
        foreach ($this->byUserId as $profile) {
            if ($profile->legacyEmail() === $needle) {
                return $profile;
            }
        }

        return null;
    }

    public function save(ExpenseProfile $profile): ExpenseProfile
    {
        $this->byUserId[$profile->userId()->toInt()] = $profile;

        return $profile;
    }
}