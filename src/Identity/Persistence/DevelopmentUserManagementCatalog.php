<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

/**
 * Shared constants for the development/test smoke user and the 16 admin
 * user-management permission codes, kept in sync between
 * InMemoryApiUserRepository (auth) and InMemoryUserManagementStore (admin CRUD)
 * so /me and /api/v1/admin/* agree on the same seeded identity in memory mode.
 */
final class DevelopmentUserManagementCatalog
{
    public const USER_PUBLIC_ID = '550e8400-e29b-41d4-a716-446655440000';
    public const USER_EMAIL = 'thilanh@tricab.com';
    public const USER_DISPLAY_NAME = 'Thilan Lakshitha';
    public const USER_SUBJECT = 'development-thilan';

    /**
     * @return array<string, array{name: string, module: string}>
     */
    public static function managementPermissions(): array
    {
        return [
            'USER.READ' => ['name' => 'View Users', 'module' => 'USER'],
            'USER.CREATE' => ['name' => 'Create Users', 'module' => 'USER'],
            'USER.UPDATE' => ['name' => 'Update Users', 'module' => 'USER'],
            'USER.STATUS.UPDATE' => ['name' => 'Change User Status', 'module' => 'USER'],
            'USER.ROLE.ASSIGN' => ['name' => 'Assign User Roles', 'module' => 'USER'],
            'USER.ROLE.REMOVE' => ['name' => 'Remove User Roles', 'module' => 'USER'],
            'ROLE.READ' => ['name' => 'View Roles', 'module' => 'ROLE'],
            'ROLE.CREATE' => ['name' => 'Create Roles', 'module' => 'ROLE'],
            'ROLE.UPDATE' => ['name' => 'Update Roles', 'module' => 'ROLE'],
            'ROLE.STATUS.UPDATE' => ['name' => 'Change Role Status', 'module' => 'ROLE'],
            'ROLE.PERMISSION.ASSIGN' => ['name' => 'Assign Role Permissions', 'module' => 'ROLE'],
            'ROLE.PERMISSION.REMOVE' => ['name' => 'Remove Role Permissions', 'module' => 'ROLE'],
            'PERMISSION.READ' => ['name' => 'View Permissions', 'module' => 'PERMISSION'],
            'PERMISSION.CREATE' => ['name' => 'Create Permissions', 'module' => 'PERMISSION'],
            'PERMISSION.UPDATE' => ['name' => 'Update Permissions', 'module' => 'PERMISSION'],
            'AUDIT.USER_MANAGEMENT.READ' => ['name' => 'View User Management Audit Log', 'module' => 'AUDIT'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function managementPermissionCodes(): array
    {
        return array_keys(self::managementPermissions());
    }
}
