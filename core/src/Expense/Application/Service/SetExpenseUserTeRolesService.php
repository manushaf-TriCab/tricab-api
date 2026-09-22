<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Contract\ExpenseAuditRecorder;
use TriCab\Core\Expense\Contract\ExpenseProfileRepository;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Domain\Exception\InvalidExpenseValue;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\DTO\UserManagement\UserTypeClassifier;
use TriCab\Core\Identity\Application\Service\UserManagement\AssignRoleToUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\RemoveRoleFromUserService;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Syncs TriExpense module roles for a user using the existing role catalog.
 *
 * Only {@see Module::ROLES} may be assigned/removed. Never touches platform
 * ADMINISTRATOR, Azure/Entra roles, or non-TE permissions.
 *
 * When TE access is granted and no ExpenseProfile exists, creates a minimal
 * active profile (no guessed approver). Existing profiles are never duplicated.
 */
final class SetExpenseUserTeRolesService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly UserManagementRepository $users,
        private readonly AssignRoleToUserService $assignRole,
        private readonly RemoveRoleFromUserService $removeRole,
        private readonly ExpenseAuditRecorder $audit,
        private readonly ExpenseProfileRepository $profiles,
    ) {
    }

    /**
     * @param array{
     *   access?: bool,
     *   approve?: bool,
     *   finance?: bool,
     *   admin?: bool,
     *   audit?: bool,
     *   fullTeAdministrator?: bool
     * } $desired
     * @return array<string, mixed>
     */
    public function execute(ExpenseActor $actor, string $targetPublicId, array $desired): array
    {
        $this->access->requireExpenseAdmin($actor);

        $targetId = UserPublicId::fromString($targetPublicId);
        $before = $this->users->findDetailsByPublicId($targetId);
        if ($before === null) {
            throw InvalidExpenseValue::for('publicId', $targetPublicId);
        }

        $status = UserStatus::fromString($before->status);
        if ($status->isDisabled()) {
            throw InvalidExpenseValue::for('publicId', $targetPublicId);
        }

        $roleCodes = [];
        foreach ($before->roles as $role) {
            if ($role->isActive && !$role->isExpired) {
                $roleCodes[] = $role->roleCode;
            }
        }
        $userType = UserTypeClassifier::fromRoleCodes($roleCodes);
        if ($userType === UserTypeClassifier::GUEST || $userType === UserTypeClassifier::CUSTOMER) {
            throw ExpenseAccessDenied::permissionRequired();
        }

        $desiredRoles = $this->rolesForDesired($desired);
        $currentTeRoles = [];
        foreach ($before->roles as $role) {
            if (!$role->isActive || $role->isExpired) {
                continue;
            }
            $code = strtoupper($role->roleCode);
            if (in_array($code, Module::ROLES, true)) {
                $currentTeRoles[] = $code;
            }
        }
        $currentTeRoles = array_values(array_unique($currentTeRoles));

        $actorCtx = new ActorContext(
            userId: $actor->userId->toInt(),
            publicId: $actor->publicId,
            emailAddress: $actor->emailAddress,
        );

        $assigned = [];
        $removed = [];

        foreach ($desiredRoles as $roleCode) {
            if (!in_array($roleCode, $currentTeRoles, true)) {
                $this->assertTeRoleOnly($roleCode);
                $this->assignRole->execute($actorCtx, $targetPublicId, $roleCode, null);
                $assigned[] = $roleCode;
            }
        }

        foreach ($currentTeRoles as $roleCode) {
            if (!in_array($roleCode, $desiredRoles, true)) {
                $this->assertTeRoleOnly($roleCode);
                $this->removeRole->execute($actorCtx, $targetPublicId, $roleCode);
                $removed[] = $roleCode;
            }
        }

        $after = $this->users->findDetailsByPublicId($targetId);
        if ($after === null) {
            throw InvalidExpenseValue::for('publicId', $targetPublicId);
        }

        $permissions = $this->permissionFlags($after->effectivePermissions);
        $roles = [];
        foreach ($after->roles as $role) {
            if (!$role->isActive || $role->isExpired) {
                continue;
            }
            $code = strtoupper($role->roleCode);
            if (in_array($code, Module::ROLES, true)) {
                $roles[] = $code;
            }
        }

        $profileCreated = false;
        $hasApprover = false;
        $hasExpenseProfile = false;
        $userId = $this->users->findUserIdByPublicId($targetId);
        if ($userId !== null && $desiredRoles !== []) {
            $existing = $this->profiles->findByUserId($userId);
            if ($existing === null) {
                $email = strtolower(trim($after->emailAddress));
                $this->profiles->save(new ExpenseProfile(
                    $userId,
                    $email,
                    $email,
                    true,
                    false,
                    false,
                    null,
                    null,
                ));
                $profileCreated = true;
                $hasExpenseProfile = true;
            } else {
                if (!$existing->isActive()) {
                    $this->profiles->save(new ExpenseProfile(
                        $existing->userId(),
                        $existing->legacyLogin(),
                        $existing->legacyEmail(),
                        true,
                        $existing->isFinance(),
                        $existing->isExpenseAdmin(),
                        $existing->managerLegacyLogin(),
                        $existing->managerUserId(),
                    ));
                }
                $fresh = $this->profiles->findByUserId($userId) ?? $existing;
                $hasExpenseProfile = true;
                $hasApprover = $fresh->managerUserId() !== null
                    || ($fresh->managerLegacyLogin() !== null && $fresh->managerLegacyLogin() !== '');
            }
        } elseif ($userId !== null) {
            $existing = $this->profiles->findByUserId($userId);
            $hasExpenseProfile = $existing !== null && $existing->isActive();
            $hasApprover = $existing !== null && (
                $existing->managerUserId() !== null
                || ($existing->managerLegacyLogin() !== null && $existing->managerLegacyLogin() !== '')
            );
        }

        if ($assigned !== [] || $removed !== [] || $profileCreated) {
            $this->audit->record(
                $actor->userId,
                'te_user_roles_updated',
                null,
                json_encode([
                    'employeePublicId' => $targetPublicId,
                    'employeeDisplayName' => $after->displayName,
                    'assignedRoles' => $assigned,
                    'removedRoles' => $removed,
                    'profileCreated' => $profileCreated,
                    'permissions' => $permissions,
                    'changedBy' => $actor->emailAddress,
                ], JSON_THROW_ON_ERROR),
            );
        }

        return [
            'publicId' => $after->publicId,
            'displayName' => $after->displayName,
            'email' => $after->emailAddress,
            'permissions' => $permissions,
            'roles' => array_values(array_unique($roles)),
            'fullTeAdministrator' => $permissions['access']
                && $permissions['approve']
                && $permissions['finance']
                && $permissions['admin']
                && $permissions['audit'],
            'assignedRoles' => $assigned,
            'removedRoles' => $removed,
            'hasExpenseProfile' => $hasExpenseProfile,
            'profileCreated' => $profileCreated,
            'hasApprover' => $hasApprover,
        ];
    }

    /**
     * @param array{
     *   access?: bool,
     *   approve?: bool,
     *   finance?: bool,
     *   admin?: bool,
     *   audit?: bool,
     *   fullTeAdministrator?: bool
     * } $desired
     * @return list<string>
     */
    private function rolesForDesired(array $desired): array
    {
        if (($desired['fullTeAdministrator'] ?? false) === true) {
            return [Module::ROLE_FINANCE, Module::ROLE_ADMINISTRATOR];
        }

        $access = ($desired['access'] ?? false) === true;
        $approve = ($desired['approve'] ?? false) === true;
        $finance = ($desired['finance'] ?? false) === true;
        $admin = ($desired['admin'] ?? false) === true;
        $audit = ($desired['audit'] ?? false) === true;

        if ($audit) {
            $finance = true;
        }
        if ($finance || $admin || $approve) {
            $access = true;
        }

        $roles = [];
        if ($admin) {
            $roles[] = Module::ROLE_ADMINISTRATOR;
        }
        if ($finance) {
            $roles[] = Module::ROLE_FINANCE;
        } elseif ($approve) {
            $roles[] = Module::ROLE_APPROVER;
        } elseif ($access) {
            $roles[] = Module::ROLE_USER;
        }

        return $roles;
    }

    private function assertTeRoleOnly(string $roleCode): void
    {
        if (!in_array(strtoupper($roleCode), Module::ROLES, true)) {
            throw ExpenseAccessDenied::permissionRequired();
        }
    }

    /**
     * @param list<\TriCab\Core\Identity\Application\DTO\UserManagement\EffectivePermissionGrant> $grants
     * @return array{access: bool, approve: bool, finance: bool, admin: bool, audit: bool}
     */
    private function permissionFlags(array $grants): array
    {
        $flags = [
            'access' => false,
            'approve' => false,
            'finance' => false,
            'admin' => false,
            'audit' => false,
        ];
        $map = [
            Module::PERMISSION_ACCESS => 'access',
            Module::PERMISSION_APPROVE => 'approve',
            Module::PERMISSION_FINANCE => 'finance',
            Module::PERMISSION_ADMIN => 'admin',
            Module::PERMISSION_AUDIT => 'audit',
        ];
        foreach ($grants as $grant) {
            $key = $map[strtoupper($grant->permissionCode)] ?? null;
            if ($key !== null) {
                $flags[$key] = true;
            }
        }

        return $flags;
    }
}
