<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Contract\ExpenseLegacyTeUserDirectoryPort;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListCriteria;
use TriCab\Core\Identity\Contract\UserManagementRepository;

/**
 * SAFE browser dry-run / status for legacy TriExpense approver mapping.
 * Never applies links; never writes to legacy MySQL.
 */
final class DryCheckExpenseLegacyApproverMappingService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ExpenseLegacyTeUserDirectoryPort $legacyDirectory,
        private readonly LinkLegacyExpenseApproversService $linker,
        private readonly UserManagementRepository $users,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor): array
    {
        $this->access->requireExpenseAdmin($actor);

        $emptyCounts = LinkLegacyExpenseApproversService::countByClassification([]);

        if (!$this->legacyDirectory->isAvailable()) {
            return [
                'available' => false,
                'mode' => 'dry_run',
                'message' => 'Legacy TriExpense directory is not available in this environment. Use the CLI dry-run when LEGACY_TC is configured.',
                'counts' => $emptyCounts,
                'linked' => 0,
                'missingProfile' => 0,
                'conflict' => 0,
                'unresolvedManager' => 0,
                'wouldLink' => 0,
            ];
        }

        $teUsers = $this->legacyDirectory->listActiveDirectory();
        $byEmail = $this->v2UsersByEmail();
        $loginToEmail = [];
        foreach ($teUsers as $row) {
            $login = (string) $row['login_norm'];
            $email = (string) $row['email_norm'];
            if ($login !== '' && $email !== '') {
                $loginToEmail[$login] = $email;
            }
        }

        $results = $this->linker->classify($teUsers, $byEmail, $loginToEmail);
        $counts = LinkLegacyExpenseApproversService::countByClassification($results);

        return [
            'available' => true,
            'mode' => 'dry_run',
            'message' => 'Read-only dry check. No profiles were modified. Bulk apply remains CLI-controlled.',
            'counts' => $counts,
            'linked' => (int) ($counts[LinkLegacyExpenseApproverResult::ALREADY_LINKED] ?? 0),
            'missingProfile' => (int) ($counts[LinkLegacyExpenseApproverResult::EMPLOYEE_NOT_FOUND] ?? 0),
            'conflict' => (int) ($counts[LinkLegacyExpenseApproverResult::CONFLICT] ?? 0),
            'unresolvedManager' => (int) ($counts[LinkLegacyExpenseApproverResult::MANAGER_NOT_FOUND] ?? 0),
            'wouldLink' => (int) ($counts[LinkLegacyExpenseApproverResult::WOULD_LINK] ?? 0),
        ];
    }

    /**
     * @return array<string, list<array{userId: int, email_norm: string}>>
     */
    private function v2UsersByEmail(): array
    {
        $byEmail = [];
        for ($page = 1; $page <= 20; $page++) {
            $listed = $this->users->listUsers(new UserListCriteria(
                page: $page,
                pageSize: 100,
                status: 'active',
            ));
            foreach ($listed->items as $user) {
                $email = strtolower(trim($user->emailAddress));
                if ($email === '') {
                    continue;
                }
                $userId = $this->users->findUserIdByPublicId(
                    \TriCab\Core\Identity\Domain\ValueObject\UserPublicId::fromString($user->publicId),
                );
                if ($userId === null) {
                    continue;
                }
                $byEmail[$email][] = [
                    'userId' => $userId->toInt(),
                    'email_norm' => $email,
                ];
            }
            if ($page >= $listed->pagination->totalPages || $listed->items === []) {
                break;
            }
        }

        return $byEmail;
    }
}
