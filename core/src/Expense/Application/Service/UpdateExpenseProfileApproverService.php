<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

/**
 * Finance / TE Admin assignment of ExpenseProfile.ManagerUserId.
 *
 * Clears LegacyManagerLogin so future legacy sync treats this as an explicit
 * V2 admin assignment (linker will CONFLICT if legacy TE manager differs).
 */
final class UpdateExpenseProfileApproverService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseProfileRepository $profiles,
        private readonly UserRepository $users,
        private readonly UserManagementRepository $userManagement,
        private readonly ExpenseAuditRecorder $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        ExpenseActor $actor,
        string $employeePublicId,
        string $managerPublicId,
    ): array {
        $this->access->requireProfileAdministration($actor);

        $employeeId = $this->userManagement->findUserIdByPublicId(
            UserPublicId::fromString($employeePublicId),
        );
        if ($employeeId === null) {
            throw InvalidExpenseValue::for('employeePublicId', $employeePublicId);
        }
        $managerId = $this->userManagement->findUserIdByPublicId(
            UserPublicId::fromString($managerPublicId),
        );
        if ($managerId === null) {
            throw InvalidExpenseValue::for('managerPublicId', $managerPublicId);
        }

        $managerUser = $this->users->findById($managerId);
        // Pending = pre-activated staff (AllowsLogin=0). Legitimate employees may
        // already be frozen as expense approvers before first Entra activation.
        // Disabled / missing accounts remain ineligible.
        if (
            $managerUser === null
            || $managerUser->status()->isDisabled()
            || !(
                $managerUser->status()->isActive()
                || $managerUser->status()->isPending()
            )
        ) {
            throw InvalidExpenseValue::for('managerPublicId', $managerPublicId);
        }

        $profile = $this->profiles->findByUserId($employeeId);
        if ($profile === null || !$profile->isActive()) {
            throw ExpenseAccessDenied::inactiveOrMissingProfile();
        }

        $previousManagerId = $profile->managerUserId();
        $previousManager = $previousManagerId !== null ? $this->users->findById($previousManagerId) : null;

        $updated = new ExpenseProfile(
            $profile->userId(),
            $profile->legacyLogin(),
            $profile->legacyEmail(),
            $profile->isActive(),
            $profile->isFinance(),
            $profile->isExpenseAdmin(),
            null, // clear → V2 Admin assignment; linker will CONFLICT vs different TE mgr
            $managerUser->id(),
        );

        $this->profiles->save($updated);
        $employeeUser = $this->users->findById($employeeId);

        $this->audit->record(
            $actor->userId,
            'te_approver_assigned',
            null,
            json_encode([
                'employeePublicId' => $employeePublicId,
                'employeeDisplayName' => $employeeUser?->displayName() ?? $profile->legacyEmail(),
                'oldApprover' => $previousManager?->displayName(),
                'newApprover' => $managerUser->displayName(),
                'changedBy' => $actor->emailAddress,
            ], JSON_THROW_ON_ERROR),
        );

        return [
            'employeePublicId' => $employeePublicId,
            'employeeDisplayName' => $employeeUser?->displayName() ?? $profile->legacyEmail(),
            'approverPublicId' => $managerUser->publicId()->toString(),
            'approverDisplayName' => $managerUser->displayName(),
            'approverSource' => 'admin',
        ];
    }
}
