<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;

/**
 * Administration dashboard summary for TriExpense module admins.
 */
final class GetExpenseAdminOverviewService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ListExpenseProfilesAdminService $profiles,
        private readonly ListExpenseAdminUsersService $users,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor): array
    {
        $this->access->requireExpenseAdmin($actor);

        $profileRows = $this->profiles->execute($actor);
        $usersResult = $this->users->execute($actor);
        $missingApprover = 0;
        $withApprover = 0;
        $legacyTeApprover = 0;
        $adminApprover = 0;
        foreach ($profileRows as $row) {
            if (($row['hasApprover'] ?? false) === true) {
                $withApprover++;
                if (($row['approverSource'] ?? null) === 'legacy_te') {
                    $legacyTeApprover++;
                }
                if (($row['approverSource'] ?? null) === 'admin') {
                    $adminApprover++;
                }
            } else {
                $missingApprover++;
            }
        }

        return [
            'users' => (int) ($usersResult['summary']['users'] ?? 0),
            'withAccess' => (int) ($usersResult['summary']['withAccess'] ?? 0),
            'withApprove' => (int) ($usersResult['summary']['withApprove'] ?? 0),
            'withFinance' => (int) ($usersResult['summary']['withFinance'] ?? 0),
            'withAdmin' => (int) ($usersResult['summary']['withAdmin'] ?? 0),
            'withAudit' => (int) ($usersResult['summary']['withAudit'] ?? 0),
            'profiles' => count($profileRows),
            'approversAssigned' => $withApprover,
            'missingApprovers' => $missingApprover,
            'legacyTeApprovers' => $legacyTeApprover,
            'adminAssignedApprovers' => $adminApprover,
        ];
    }
}
