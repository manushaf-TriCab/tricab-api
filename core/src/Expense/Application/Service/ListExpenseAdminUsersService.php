<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Contract\ExpenseProfileAdminQuery;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserListCriteria;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

/**
 * Lists TriExpense-relevant users with effective TE permission flags.
 * Authoritative source: V2 roles → effective permissions (not a parallel ACL).
 */
final class ListExpenseAdminUsersService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly UserManagementRepository $users,
        private readonly ExpenseProfileAdminQuery $profiles,
        private readonly ListExpenseProfilesAdminService $adminProfiles,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function execute(ExpenseActor $actor, string $search = ''): array
    {
        $this->access->requireExpenseAdmin($actor);

        $profileApproverByEmail = [];
        foreach ($this->adminProfiles->execute($actor) as $row) {
            $email = strtolower(trim((string) $row['employeeEmail']));
            if ($email !== '') {
                $profileApproverByEmail[$email] = $row;
            }
        }

        $profileEmails = [];
        foreach ($this->profiles->listActiveProfiles() as $row) {
            $email = strtolower(trim((string) $row['email']));
            if ($email !== '') {
                $profileEmails[$email] = true;
            }
        }

        $items = [];
        $summary = [
            'users' => 0,
            'withAccess' => 0,
            'withApprove' => 0,
            'withFinance' => 0,
            'withAdmin' => 0,
            'withAudit' => 0,
        ];

        $needle = strtolower(trim($search));
        $seen = [];

        for ($page = 1; $page <= 10; $page++) {
            $listed = $this->users->listUsers(new UserListCriteria(
                page: $page,
                pageSize: 100,
                search: $needle !== '' ? $needle : null,
                status: 'active',
            ));

            foreach ($listed->items as $summaryUser) {
                if (isset($seen[$summaryUser->publicId])) {
                    continue;
                }
                $seen[$summaryUser->publicId] = true;

                $details = $this->users->findDetailsByPublicId(
                    UserPublicId::fromString($summaryUser->publicId),
                );
                if ($details === null) {
                    continue;
                }

                $perms = $this->tePermissions($details->effectivePermissions);
                $roles = $this->teRoles($details->roles);
                $email = strtolower($details->emailAddress);
                $hasProfile = isset($profileEmails[$email]);
                $hasAccess = $perms[Module::PERMISSION_ACCESS];
                $hasApprove = $perms[Module::PERMISSION_APPROVE];
                $hasFinance = $perms[Module::PERMISSION_FINANCE];
                $hasAdmin = $perms[Module::PERMISSION_ADMIN];
                $hasAudit = $perms[Module::PERMISSION_AUDIT];

                $hasTeRelevance = $hasAccess || $hasApprove || $hasFinance || $hasAdmin || $hasAudit || $hasProfile;
                if (!$hasTeRelevance) {
                    continue;
                }

                $profileRow = $profileApproverByEmail[$email] ?? null;

                $items[] = [
                    'publicId' => $details->publicId,
                    'displayName' => $details->displayName,
                    'email' => $details->emailAddress,
                    'permissions' => [
                        'access' => $hasAccess,
                        'approve' => $hasApprove,
                        'finance' => $hasFinance,
                        'admin' => $hasAdmin,
                        'audit' => $hasAudit,
                    ],
                    'roles' => $roles,
                    'hasExpenseProfile' => $hasProfile,
                    'approverDisplayName' => $profileRow['approverDisplayName'] ?? null,
                    'hasApprover' => (bool) ($profileRow['hasApprover'] ?? false),
                    'fullTeAdministrator' => $hasAccess && $hasApprove && $hasFinance && $hasAdmin && $hasAudit,
                ];

                $summary['users']++;
                if ($hasAccess) {
                    $summary['withAccess']++;
                }
                if ($hasApprove) {
                    $summary['withApprove']++;
                }
                if ($hasFinance) {
                    $summary['withFinance']++;
                }
                if ($hasAdmin) {
                    $summary['withAdmin']++;
                }
                if ($hasAudit) {
                    $summary['withAudit']++;
                }
            }

            if ($page >= $listed->pagination->totalPages || $listed->items === []) {
                break;
            }
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcasecmp((string) $a['displayName'], (string) $b['displayName']),
        );

        return ['items' => $items, 'summary' => $summary];
    }

    /**
     * @param list<\TriCab\Core\Identity\Application\DTO\UserManagement\EffectivePermissionGrant> $grants
     * @return array<string, bool>
     */
    private function tePermissions(array $grants): array
    {
        $out = [
            Module::PERMISSION_ACCESS => false,
            Module::PERMISSION_APPROVE => false,
            Module::PERMISSION_FINANCE => false,
            Module::PERMISSION_ADMIN => false,
            Module::PERMISSION_AUDIT => false,
        ];
        foreach ($grants as $grant) {
            $code = strtoupper($grant->permissionCode);
            if (array_key_exists($code, $out)) {
                $out[$code] = true;
            }
        }

        return $out;
    }

    /**
     * @param list<\TriCab\Core\Identity\Application\DTO\UserManagement\UserRoleAssignment> $roles
     * @return list<string>
     */
    private function teRoles(array $roles): array
    {
        $allowed = array_map('strtoupper', Module::ROLES);
        $out = [];
        foreach ($roles as $role) {
            if (!$role->isActive || $role->isExpired) {
                continue;
            }
            $code = strtoupper($role->roleCode);
            if (in_array($code, $allowed, true)) {
                $out[] = $code;
            }
        }

        return array_values(array_unique($out));
    }
}
