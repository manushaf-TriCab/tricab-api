<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Contract\ExpenseFinanceCapabilityResolver;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Module;

/**
 * Stage 2 access: authenticated + TRIEXPENSE.ACCESS + active ExpenseProfile.
 * Platform ADMINISTRATOR alone is insufficient.
 */
final class ExpenseAccessService
{
    public function __construct(
        private readonly ExpenseProfileRepository $profiles,
        private readonly ExpenseFinanceCapabilityResolver $financeCapability,
    ) {
    }

    public function requireActiveProfile(ExpenseActor $actor): ExpenseProfile
    {
        if (!$actor->hasPermission(Module::PERMISSION_ACCESS)) {
            throw ExpenseAccessDenied::permissionRequired();
        }

        $profile = $this->profiles->findByUserId($actor->userId);
        if ($profile === null || !$profile->isActive()) {
            throw ExpenseAccessDenied::inactiveOrMissingProfile();
        }

        return $profile;
    }

    public function isExpenseAdmin(ExpenseProfile $profile): bool
    {
        return $profile->isExpenseAdmin();
    }

    public function hasFinanceCapability(ExpenseActor $actor, ExpenseProfile $profile): bool
    {
        return $this->financeCapability->hasFinanceCapability($actor->userId, $profile);
    }

    public function requireFinance(ExpenseActor $actor): ExpenseProfile
    {
        $profile = $this->requireActiveProfile($actor);
        if (!$this->hasFinanceCapability($actor, $profile)) {
            throw ExpenseAccessDenied::financeRequired();
        }

        return $profile;
    }

    public function platformAdministratorIsNotExpenseAdmin(ExpenseActor $actor, ExpenseProfile $profile): bool
    {
        return $actor->isPlatformAdministrator() && !$profile->isExpenseAdmin();
    }
}