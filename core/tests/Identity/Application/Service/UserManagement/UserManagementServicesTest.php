<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service\UserManagement;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Application\Exception\PermissionCodeConflict;
use TriCab\Core\Identity\Application\Exception\RoleCodeConflict;
use TriCab\Core\Identity\Application\Exception\UserEmailConflict;
use TriCab\Core\Identity\Application\Service\UserManagement\AssignPermissionToRoleService;
use TriCab\Core\Identity\Application\Service\UserManagement\AssignRoleToUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\ChangeRoleStatusService;
use TriCab\Core\Identity\Application\Service\UserManagement\ChangeUserStatusService;
use TriCab\Core\Identity\Application\Service\UserManagement\CreatePermissionService;
use TriCab\Core\Identity\Application\Service\UserManagement\CreateRoleService;
use TriCab\Core\Identity\Application\Service\UserManagement\CreateUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\GetUserDetailsService;
use TriCab\Core\Identity\Application\Service\UserManagement\RemoveRoleFromUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\UpdatePermissionService;
use TriCab\Core\Identity\Application\Service\UserManagement\UserManagementAuditRecorder;
use TriCab\Core\Identity\Domain\Exception\InactivePermissionAssignment;
use TriCab\Core\Identity\Domain\Exception\InactiveRoleAssignment;
use TriCab\Core\Identity\Domain\Exception\LastAdminProtection;
use TriCab\Core\Identity\Domain\Exception\SelfLockoutProtection;
use TriCab\Core\Identity\Domain\Exception\SystemPermissionProtected;
use TriCab\Core\Identity\Domain\Exception\SystemRoleProtected;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;
use TriCab\Core\Identity\Domain\ValueObject\UserManagementEventType;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Identity\Doubles\InMemoryUserManagementStore;

final class UserManagementServicesTest extends TestCase
{
    private InMemoryUserManagementStore $store;
    private UserManagementAuditRecorder $audit;
    private ActorContext $actor;

    protected function setUp(): void
    {
        $this->store = new InMemoryUserManagementStore(FixedClock::utc('2026-08-03T02:00:00+00:00'));
        $this->store->seedSystemCatalog();
        $this->audit = new UserManagementAuditRecorder($this->store->audit());

        $admin = $this->store->seedUser(
            emailAddress: 'admin@example.com',
            displayName: 'Primary Admin',
            roleCodes: [ProtectedIdentityCatalog::ROLE_ADMINISTRATOR],
        );

        $adminUserId = $this->store->users()->findUserIdByPublicId(
            \TriCab\Core\Identity\Domain\ValueObject\UserPublicId::fromString($admin->publicId),
        );
        assert($adminUserId !== null);

        $this->actor = new ActorContext(
            userId: $adminUserId->toInt(),
            publicId: $admin->publicId,
            emailAddress: $admin->emailAddress,
            correlationId: 'corr-test-001',
        );
    }

    private function employeeActor(): ActorContext
    {
        $employee = $this->store->seedUser(
            emailAddress: sprintf('employee-%s@example.com', bin2hex(random_bytes(4))),
            displayName: 'Employee Actor',
            roleCodes: [ProtectedIdentityCatalog::ROLE_EMPLOYEE],
        );
        $employeeUserId = $this->store->users()->findUserIdByPublicId(
            \TriCab\Core\Identity\Domain\ValueObject\UserPublicId::fromString($employee->publicId),
        );
        assert($employeeUserId !== null);

        return new ActorContext(
            userId: $employeeUserId->toInt(),
            publicId: $employee->publicId,
            emailAddress: $employee->emailAddress,
        );
    }

    public function test_create_user_rejects_duplicate_email(): void
    {
        $service = new CreateUserService($this->store->users(), $this->store->roles(), $this->audit);

        $service->execute($this->actor, 'user@example.com', 'Example User');

        $this->expectException(UserEmailConflict::class);

        $service->execute($this->actor, 'user@example.com', 'Duplicate User');
    }

    public function test_create_role_rejects_duplicate_code(): void
    {
        $service = new CreateRoleService($this->store->roles(), $this->audit);

        $service->execute($this->actor, 'DISPATCHER', 'Dispatcher', 'Dispatch operations');

        $this->expectException(RoleCodeConflict::class);

        $service->execute($this->actor, 'DISPATCHER', 'Duplicate Dispatcher');
    }

    public function test_create_permission_rejects_duplicate_code(): void
    {
        $service = new CreatePermissionService($this->store->permissions(), $this->audit);

        $service->execute(
            $this->actor,
            'ORDERS.VIEW',
            'View Orders',
            'ORDERS',
            'View order records',
        );

        $this->expectException(PermissionCodeConflict::class);

        $service->execute(
            $this->actor,
            'ORDERS.VIEW',
            'Duplicate View Orders',
            'ORDERS',
        );
    }

    public function test_role_assignment_and_removal(): void
    {
        $createUser = new CreateUserService($this->store->users(), $this->store->roles(), $this->audit);
        $assignRole = new AssignRoleToUserService($this->store->users(), $this->store->roles(), $this->audit);
        $removeRole = new RemoveRoleFromUserService($this->store->users(), $this->store->roles(), $this->audit);

        $created = $createUser->execute(
            $this->actor,
            'employee@example.com',
            'Employee User',
            UserStatus::ACTIVE,
            [ProtectedIdentityCatalog::ROLE_EMPLOYEE],
        );

        $assigned = $assignRole->execute(
            $this->actor,
            $created->publicId,
            ProtectedIdentityCatalog::ROLE_GUEST,
        );

        self::assertContains(ProtectedIdentityCatalog::ROLE_GUEST, array_map(
            static fn ($role) => $role->roleCode,
            $assigned->roles,
        ));

        $removed = $removeRole->execute(
            $this->actor,
            $created->publicId,
            ProtectedIdentityCatalog::ROLE_GUEST,
        );

        $activeRoleCodes = array_values(array_filter(
            $removed->roles,
            static fn ($role) => !$role->isExpired,
        ));

        self::assertNotContains(ProtectedIdentityCatalog::ROLE_GUEST, array_map(
            static fn ($role) => $role->roleCode,
            $activeRoleCodes,
        ));
    }

    public function test_expired_role_assignment_does_not_grant_permissions(): void
    {
        $createUser = new CreateUserService($this->store->users(), $this->store->roles(), $this->audit);
        $createRole = new CreateRoleService($this->store->roles(), $this->audit);
        $createPermission = new CreatePermissionService($this->store->permissions(), $this->audit);
        $assignPermission = new AssignPermissionToRoleService(
            $this->store->roles(),
            $this->store->permissions(),
            $this->audit,
        );
        $getDetails = new GetUserDetailsService($this->store->users(), $this->store->audit());

        $createRole->execute($this->actor, 'TEMP_ANALYST', 'Temporary Analyst');
        $createPermission->execute($this->actor, 'REPORTS.VIEW', 'View Reports', 'REPORTS');
        $assignPermission->execute($this->actor, 'TEMP_ANALYST', 'REPORTS.VIEW');

        $created = $createUser->execute(
            $this->actor,
            'analyst@example.com',
            'Temporary Analyst User',
        );

        $expiredAt = new DateTimeImmutable('2026-08-03T01:00:00+00:00', new DateTimeZone('UTC'));
        $this->store->assignRoleWithExpiry($created->publicId, 'TEMP_ANALYST', $expiredAt, $this->actor->userId);

        $details = $getDetails->execute($created->publicId);

        self::assertCount(1, $details->roles);
        self::assertTrue($details->roles[0]->isExpired);
        self::assertSame([], $details->effectivePermissions);
    }

    public function test_assign_role_rejects_inactive_role(): void
    {
        $createUser = new CreateUserService($this->store->users(), $this->store->roles(), $this->audit);
        $createRole = new CreateRoleService($this->store->roles(), $this->audit);
        $changeRoleStatus = new ChangeRoleStatusService($this->store->roles(), $this->audit);
        $assignRole = new AssignRoleToUserService($this->store->users(), $this->store->roles(), $this->audit);

        $createRole->execute($this->actor, 'RETIRED', 'Retired Role', null, false);
        $created = $createUser->execute($this->actor, 'retired-user@example.com', 'Retired User');

        $this->expectException(InactiveRoleAssignment::class);

        $assignRole->execute($this->actor, $created->publicId, 'RETIRED');
    }

    public function test_assign_permission_rejects_inactive_permission(): void
    {
        $createRole = new CreateRoleService($this->store->roles(), $this->audit);
        $createPermission = new CreatePermissionService($this->store->permissions(), $this->audit);
        $assignPermission = new AssignPermissionToRoleService(
            $this->store->roles(),
            $this->store->permissions(),
            $this->audit,
        );

        $createRole->execute($this->actor, 'AUDITOR', 'Auditor');
        $createPermission->execute(
            $this->actor,
            'AUDIT.VIEW',
            'View Audit',
            'AUDIT',
            null,
            false,
        );

        $this->expectException(InactivePermissionAssignment::class);

        $assignPermission->execute($this->actor, 'AUDITOR', 'AUDIT.VIEW');
    }

    public function test_last_admin_status_change_is_blocked(): void
    {
        $changeStatus = new ChangeUserStatusService($this->store->users(), $this->audit);
        $employeeActor = $this->employeeActor();

        $this->expectException(LastAdminProtection::class);

        $changeStatus->execute($employeeActor, $this->actor->publicId, UserStatus::DISABLED);
    }

    public function test_last_admin_role_removal_is_blocked(): void
    {
        $removeRole = new RemoveRoleFromUserService($this->store->users(), $this->store->roles(), $this->audit);
        $employeeActor = $this->employeeActor();

        $this->expectException(LastAdminProtection::class);

        $removeRole->execute(
            $employeeActor,
            $this->actor->publicId,
            ProtectedIdentityCatalog::ROLE_ADMINISTRATOR,
        );
    }

    public function test_self_lockout_status_change_is_blocked(): void
    {
        $this->store->seedUser(
            emailAddress: 'backup-admin@example.com',
            displayName: 'Backup Admin',
            roleCodes: [ProtectedIdentityCatalog::ROLE_ADMINISTRATOR],
        );

        $changeStatus = new ChangeUserStatusService($this->store->users(), $this->audit);

        $this->expectException(SelfLockoutProtection::class);

        $changeStatus->execute($this->actor, $this->actor->publicId, UserStatus::DISABLED);
    }

    public function test_self_lockout_admin_role_removal_is_blocked(): void
    {
        $this->store->seedUser(
            emailAddress: 'backup-admin@example.com',
            displayName: 'Backup Admin',
            roleCodes: [ProtectedIdentityCatalog::ROLE_ADMINISTRATOR],
        );

        $removeRole = new RemoveRoleFromUserService($this->store->users(), $this->store->roles(), $this->audit);

        $this->expectException(SelfLockoutProtection::class);

        $removeRole->execute(
            $this->actor,
            $this->actor->publicId,
            ProtectedIdentityCatalog::ROLE_ADMINISTRATOR,
        );
    }

    public function test_effective_permissions_via_role_permission_and_user_assignment(): void
    {
        $createRole = new CreateRoleService($this->store->roles(), $this->audit);
        $createPermission = new CreatePermissionService($this->store->permissions(), $this->audit);
        $assignPermission = new AssignPermissionToRoleService(
            $this->store->roles(),
            $this->store->permissions(),
            $this->audit,
        );
        $createUser = new CreateUserService($this->store->users(), $this->store->roles(), $this->audit);
        $getDetails = new GetUserDetailsService($this->store->users(), $this->store->audit());

        $createRole->execute($this->actor, 'FLEET_MANAGER', 'Fleet Manager');
        $createPermission->execute($this->actor, 'FLEET.VIEW', 'View Fleet', 'FLEET');
        $assignPermission->execute($this->actor, 'FLEET_MANAGER', 'FLEET.VIEW');

        $created = $createUser->execute(
            $this->actor,
            'fleet@example.com',
            'Fleet Manager User',
            UserStatus::ACTIVE,
            ['FLEET_MANAGER'],
        );

        $details = $getDetails->execute($created->publicId);

        self::assertCount(1, $details->effectivePermissions);
        self::assertSame('FLEET.VIEW', $details->effectivePermissions[0]->permissionCode);
        self::assertSame(['FLEET_MANAGER'], $details->effectivePermissions[0]->grantedByRoleCodes);
    }

    public function test_audit_entries_created_on_mutations(): void
    {
        $createUser = new CreateUserService($this->store->users(), $this->store->roles(), $this->audit);
        $assignRole = new AssignRoleToUserService($this->store->users(), $this->store->roles(), $this->audit);
        $removeRole = new RemoveRoleFromUserService($this->store->users(), $this->store->roles(), $this->audit);

        $created = $createUser->execute(
            $this->actor,
            'audited@example.com',
            'Audited User',
            UserStatus::ACTIVE,
            [ProtectedIdentityCatalog::ROLE_EMPLOYEE],
        );

        $assignRole->execute($this->actor, $created->publicId, ProtectedIdentityCatalog::ROLE_GUEST);
        $removeRole->execute($this->actor, $created->publicId, ProtectedIdentityCatalog::ROLE_GUEST);

        $events = array_map(
            static fn ($entry) => $entry->eventType,
            $this->store->allAuditEntries(),
        );

        self::assertContains(UserManagementEventType::USER_CREATED, $events);
        self::assertContains(UserManagementEventType::USER_ROLE_ASSIGNED, $events);
        self::assertContains(UserManagementEventType::USER_ROLE_REMOVED, $events);
    }

    public function test_remove_missing_role_is_idempotent(): void
    {
        $createUser = new CreateUserService($this->store->users(), $this->store->roles(), $this->audit);
        $removeRole = new RemoveRoleFromUserService($this->store->users(), $this->store->roles(), $this->audit);

        $created = $createUser->execute(
            $this->actor,
            'idempotent@example.com',
            'Idempotent User',
            UserStatus::ACTIVE,
            [ProtectedIdentityCatalog::ROLE_EMPLOYEE],
        );

        $beforeCount = count($this->store->allAuditEntries());

        $details = $removeRole->execute(
            $this->actor,
            $created->publicId,
            ProtectedIdentityCatalog::ROLE_GUEST,
        );

        self::assertSame($beforeCount, count($this->store->allAuditEntries()));
        self::assertSame('idempotent@example.com', $details->emailAddress);
    }

    public function test_cannot_deactivate_system_protected_roles(): void
    {
        $service = new ChangeRoleStatusService($this->store->roles(), $this->audit);

        $this->expectException(SystemRoleProtected::class);

        $service->execute($this->actor, ProtectedIdentityCatalog::ROLE_ADMINISTRATOR, false);
    }

    public function test_cannot_deactivate_account_login_while_users_depend_on_it(): void
    {
        $createUser = new CreateUserService($this->store->users(), $this->store->roles(), $this->audit);
        $service = new UpdatePermissionService($this->store->permissions(), $this->audit);

        $createUser->execute(
            $this->actor,
            'login-user@example.com',
            'Login User',
            UserStatus::ACTIVE,
            [ProtectedIdentityCatalog::ROLE_EMPLOYEE],
        );

        $this->expectException(SystemPermissionProtected::class);

        $service->execute(
            $this->actor,
            ProtectedIdentityCatalog::PERMISSION_ACCOUNT_LOGIN,
            'Account Login',
            'Allows authentication',
            false,
        );
    }
}
