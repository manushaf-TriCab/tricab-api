<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseProfileAdminQuery;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Identity\Contract\UserRepository;

final class InMemoryExpenseProfileAdminQuery implements ExpenseProfileAdminQuery
{
    public function __construct(
        private readonly ExpenseProfileRepository $profiles,
        private readonly UserRepository $users,
        /** @var list<\TriCab\Core\Identity\Domain\ValueObject\UserId> */
        private array $userIds = [],
    ) {
    }

    /** @param list<\TriCab\Core\Identity\Domain\ValueObject\UserId> $userIds */
    public function seedUserIds(array $userIds): void
    {
        $this->userIds = $userIds;
    }

    public function listActiveProfiles(): array
    {
        $out = [];
        foreach ($this->userIds as $userId) {
            $profile = $this->profiles->findByUserId($userId);
            if ($profile === null || !$profile->isActive()) {
                continue;
            }
            $user = $this->users->findById($userId);
            $out[] = [
                'userId' => $userId->toInt(),
                'publicId' => $user?->publicId()->toString() ?? ('user-' . $userId->toInt()),
                'displayName' => $user?->displayName() ?? $profile->legacyEmail(),
                'email' => $user?->emailAddress()->toString() ?? $profile->legacyEmail(),
                'legacyLogin' => $profile->legacyLogin(),
            ];
        }

        return $out;
    }
}
