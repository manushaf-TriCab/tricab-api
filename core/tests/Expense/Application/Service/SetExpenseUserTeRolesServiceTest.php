<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Expense\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Expense\Persistence\InMemoryExpenseAuditRecorder;
use TriCab\Api\Expense\Persistence\InMemoryExpenseProfileRepository;
use TriCab\Api\Identity\Persistence\InMemoryUserManagementStore;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;
use TriCab\Core\Expense\Application\Exception\ExpenseAccessDenied;
use TriCab\Core\Expense\Application\ExpensePermissionMode;
use TriCab\Api\Expense\Persistence\ProfileFinanceCapabilityResolver;
use TriCab\Core\Expense\Application\Service\ExpenseAccessService;
use TriCab\Core\Expense\Application\Service\SetExpenseUserTeRolesService;
use TriCab\Core\Expense\Domain\Entity\ExpenseProfile;
use TriCab\Core\Expense\Module;
use TriCab\Core\Identity\Application\Service\UserManagement\AssignRoleToUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\RemoveRoleFromUserService;
use TriCab\Core\Identity\Application\Service\UserManagement\UserManagementAuditRecorder;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class SetExpenseUserTeRolesServiceTest extends TestCase
{
    public function test_full_admin_assigns_finance_and_administrator_roles_only(): void
    {
        $store = new InMemoryUserManagementStore();
        $store->seedDevelopmentUserManagement();
        $users = $store->users();
        $roles = $store->roles();
        $auditUm = new UserManagementAuditRecorder($store->audit(), $users);
        $assign = new AssignRoleToUserService($users, $roles, $auditUm);
        $remove = new RemoveRoleFromUserService($users, $roles, $auditUm);
        $profiles = new InMemoryExpenseProfileRepository();
        $profiles->save(new ExpenseProfile(
            UserId::fromInt(1),
            'thilan',
            'thilanh@tricab.com',
            true,
            false,
            true,
            null,
            null,
        ));
        $access = new ExpenseAccessService(
            $profiles,
            new ProfileFinanceCapabilityResolver(),
            ExpensePermissionMode::permissions(),
        );
        $service = new SetExpenseUserTeRolesService(
            $access,
            $users,
            $assign,
            $remove,
            new InMemoryExpenseAuditRecorder(),
            $profiles,
        );

        $created = $users->createUser(
            EmailAddress::fromString('target@tricab.com'),
            'Target User',
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            UserId::fromInt(1),
        );

        $actor = new ExpenseActor(
            UserId::fromInt(1),
            '550e8400-e29b-41d4-a716-446655440000',
            'thilanh@tricab.com',
            [Module::PERMISSION_ACCESS, Module::PERMISSION_ADMIN],
            ['EMPLOYEE'],
        );

        $result = $service->execute($actor, $created->publicId, [
            'fullTeAdministrator' => true,
        ]);

        self::assertContains(Module::ROLE_FINANCE, $result['roles']);
        self::assertContains(Module::ROLE_ADMINISTRATOR, $result['roles']);
        self::assertNotContains('ADMINISTRATOR', $result['roles']);
        self::assertTrue($result['fullTeAdministrator']);
        self::assertTrue($result['hasExpenseProfile']);
        self::assertTrue($result['profileCreated']);
    }

    public function test_access_only_actor_cannot_self_escalate(): void
    {
        $store = new InMemoryUserManagementStore();
        $store->seedDevelopmentUserManagement();
        $users = $store->users();
        $roles = $store->roles();
        $auditUm = new UserManagementAuditRecorder($store->audit(), $users);
        $assign = new AssignRoleToUserService($users, $roles, $auditUm);
        $remove = new RemoveRoleFromUserService($users, $roles, $auditUm);
        $profiles = new InMemoryExpenseProfileRepository();
        $profiles->save(new ExpenseProfile(
            UserId::fromInt(1),
            'thilan',
            'thilanh@tricab.com',
            true,
            false,
            false,
            null,
            null,
        ));
        $access = new ExpenseAccessService(
            $profiles,
            new ProfileFinanceCapabilityResolver(),
            ExpensePermissionMode::permissions(),
        );
        $service = new SetExpenseUserTeRolesService(
            $access,
            $users,
            $assign,
            $remove,
            new InMemoryExpenseAuditRecorder(),
            $profiles,
        );

        $actor = new ExpenseActor(
            UserId::fromInt(1),
            '550e8400-e29b-41d4-a716-446655440000',
            'thilanh@tricab.com',
            [Module::PERMISSION_ACCESS],
            ['EMPLOYEE'],
        );

        $this->expectException(ExpenseAccessDenied::class);
        $service->execute($actor, '550e8400-e29b-41d4-a716-446655440000', [
            'fullTeAdministrator' => true,
        ]);
    }
}
