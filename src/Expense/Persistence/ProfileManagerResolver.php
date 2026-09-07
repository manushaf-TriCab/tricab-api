<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Persistence;

use TriCab\Core\Expense\Contract\ExpenseManagerResolver;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Precedence: explicit TE manager UserId on profile → resolve via legacy login → null
 * (employee fallback port can be added without changing callers).
 */
final class ProfileManagerResolver implements ExpenseManagerResolver
{
    public function __construct(
        private readonly ExpenseProfileRepository $profiles,
        private readonly ?ExpenseManagerResolver $employeeFallback = null,
    ) {
    }

    public function resolveManagerUserId(ExpenseProfile $profile): ?UserId
    {
        if ($profile->managerUserId() !== null) {
            return $profile->managerUserId();
        }

        $login = $profile->managerLegacyLogin();
        if ($login !== null && $login !== '') {
            $managerProfile = $this->profiles->findByLegacyLogin($login);
            if ($managerProfile !== null && $managerProfile->isActive()) {
                return $managerProfile->userId();
            }
        }

        return $this->employeeFallback?->resolveManagerUserId($profile);
    }
}