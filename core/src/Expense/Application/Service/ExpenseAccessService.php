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
 *
 * Finance authorization decision (Phase 5):
 *   ExpenseProfile.IsFinance is authoritative at request time.
 *   Graph Tricab-Finance group is diagnostic/sync only — not a live hard
 *   dependency per request.
 *
 * Concretely: {@see ExpenseFinanceCapabilityResolver} is wired to the
 * profile-only resolver, so no request ever waits on — or is denied by — a Graph
 * call. The Graph group remains useful for reconciling who *should* carry
 * IsFinance, and the group checker stays available for diagnostics and for
 * provisioning jobs, but it must not be composed into the request path: a Graph
 * outage or throttle would otherwise take Finance offline mid-period-close.
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