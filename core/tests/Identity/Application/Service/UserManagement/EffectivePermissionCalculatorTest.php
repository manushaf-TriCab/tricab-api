<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Application\Service\UserManagement;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\RolePermissionSource;
use TriCab\Core\Identity\Application\Service\UserManagement\EffectivePermissionCalculator;

final class EffectivePermissionCalculatorTest extends TestCase
{
    private EffectivePermissionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new EffectivePermissionCalculator();
    }

    public function test_excludes_expired_and_inactive_sources(): void
    {
        $grants = $this->calculator->calculate([
            new RolePermissionSource(
                roleCode: 'ADMINISTRATOR',
                permissionCode: 'ACCOUNT.LOGIN',
                permissionName: 'Account Login',
                roleIsActive: true,
                permissionIsActive: true,
                assignmentIsActive: true,
                isExpired: false,
            ),
            new RolePermissionSource(
                roleCode: 'EMPLOYEE',
                permissionCode: 'ACCOUNT.LOGIN',
                permissionName: 'Account Login',
                roleIsActive: true,
                permissionIsActive: true,
                assignmentIsActive: true,
                isExpired: true,
            ),
            new RolePermissionSource(
                roleCode: 'GUEST',
                permissionCode: 'ORDERS.VIEW',
                permissionName: 'View Orders',
                roleIsActive: false,
                permissionIsActive: true,
                assignmentIsActive: true,
                isExpired: false,
            ),
            new RolePermissionSource(
                roleCode: 'EMPLOYEE',
                permissionCode: 'ORDERS.EDIT',
                permissionName: 'Edit Orders',
                roleIsActive: true,
                permissionIsActive: false,
                assignmentIsActive: true,
                isExpired: false,
            ),
            new RolePermissionSource(
                roleCode: 'EMPLOYEE',
                permissionCode: 'ORDERS.CREATE',
                permissionName: 'Create Orders',
                roleIsActive: true,
                permissionIsActive: true,
                assignmentIsActive: false,
                isExpired: false,
            ),
        ]);

        self::assertCount(1, $grants);
        self::assertSame('ACCOUNT.LOGIN', $grants[0]->permissionCode);
        self::assertSame(['ADMINISTRATOR'], $grants[0]->grantedByRoleCodes);
    }

    public function test_merges_granted_by_role_codes(): void
    {
        $grants = $this->calculator->calculate([
            new RolePermissionSource(
                roleCode: 'ADMINISTRATOR',
                permissionCode: 'ORDERS.VIEW',
                permissionName: 'View Orders',
                roleIsActive: true,
                permissionIsActive: true,
                assignmentIsActive: true,
                isExpired: false,
            ),
            new RolePermissionSource(
                roleCode: 'EMPLOYEE',
                permissionCode: 'ORDERS.VIEW',
                permissionName: 'View Orders',
                roleIsActive: true,
                permissionIsActive: true,
                assignmentIsActive: true,
                isExpired: false,
            ),
            new RolePermissionSource(
                roleCode: 'GUEST',
                permissionCode: 'ORDERS.VIEW',
                permissionName: 'View Orders',
                roleIsActive: true,
                permissionIsActive: true,
                assignmentIsActive: true,
                isExpired: false,
            ),
        ]);

        self::assertCount(1, $grants);
        self::assertSame('ORDERS.VIEW', $grants[0]->permissionCode);
        self::assertSame(['ADMINISTRATOR', 'EMPLOYEE', 'GUEST'], $grants[0]->grantedByRoleCodes);
    }
}
