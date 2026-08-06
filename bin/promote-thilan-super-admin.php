<?php

declare(strict_types=1);

/**
 * Development helper: ensure thilanh@tricab.com is an ADMINISTRATOR ("super admin").
 *
 * Runs the Stage 2 permission catalog + ADMINISTRATOR role mapping, then assigns
 * the role to thilanh@tricab.com. Safe to re-run (idempotent).
 *
 * Usage (from tricab-api):
 *   php bin/promote-thilan-super-admin.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "USER_REPOSITORY is not azure-sql. In-memory mode already seeds thilanh as ADMINISTRATOR.\n");
    exit(0);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$permissions = [
    'USER.READ' => ['Read users', 'View users and user profiles in administration.', 'user'],
    'USER.CREATE' => ['Create users', 'Create new TriCab user accounts.', 'user'],
    'USER.UPDATE' => ['Update users', 'Update user profile details.', 'user'],
    'USER.STATUS.UPDATE' => ['Update user status', 'Activate, suspend, or deactivate user accounts.', 'user'],
    'USER.ROLE.ASSIGN' => ['Assign user roles', 'Assign roles to users.', 'user'],
    'USER.ROLE.REMOVE' => ['Remove user roles', 'Remove roles from users.', 'user'],
    'ROLE.READ' => ['Read roles', 'View roles and role details.', 'role'],
    'ROLE.CREATE' => ['Create roles', 'Create new roles.', 'role'],
    'ROLE.UPDATE' => ['Update roles', 'Update role names and descriptions.', 'role'],
    'ROLE.STATUS.UPDATE' => ['Update role status', 'Activate or deactivate roles.', 'role'],
    'ROLE.PERMISSION.ASSIGN' => ['Assign role permissions', 'Assign permissions to roles.', 'role'],
    'ROLE.PERMISSION.REMOVE' => ['Remove role permissions', 'Remove permissions from roles.', 'role'],
    'PERMISSION.READ' => ['Read permissions', 'View the permission catalog.', 'permission'],
    'PERMISSION.CREATE' => ['Create permissions', 'Create new permission definitions.', 'permission'],
    'PERMISSION.UPDATE' => ['Update permissions', 'Update permission metadata where safe.', 'permission'],
    'AUDIT.USER_MANAGEMENT.READ' => ['Read user-management audit', 'View user, role and permission management audit history.', 'audit'],
];

$targetEmail = 'thilanh@tricab.com';
$adminRole = ProtectedIdentityCatalog::ROLE_ADMINISTRATOR;

echo "Connecting to Azure SQL and promoting {$targetEmail} to {$adminRole}...\n";

$db->beginTransaction();

try {
    $permissionIds = [];

    foreach ($permissions as $code => [$name, $description, $module]) {
        $existing = $db->fetchAll(
            <<<'SQL'
SELECT TOP (1) [PermissionId], [IsActive]
FROM [identity].[Permissions]
WHERE UPPER([PermissionCode]) = UPPER(:code)
ORDER BY [PermissionId]
SQL,
            [':code' => $code],
        );

        if ($existing === []) {
            $inserted = $db->fetchAll(
                <<<'SQL'
INSERT INTO [identity].[Permissions] (
    [PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive]
)
OUTPUT INSERTED.[PermissionId]
VALUES (:code, :name, :description, :module, 1)
SQL,
                [
                    ':code' => $code,
                    ':name' => $name,
                    ':description' => $description,
                    ':module' => $module,
                ],
            );
            $permissionIds[$code] = (int) ($inserted[0]['PermissionId'] ?? 0);
            echo "  + permission {$code}\n";
        } else {
            if ((int) ($existing[0]['IsActive'] ?? 0) !== 1) {
                throw new RuntimeException("Permission {$code} exists but is inactive.");
            }
            $permissionIds[$code] = (int) ($existing[0]['PermissionId'] ?? 0);
            echo "  = permission {$code}\n";
        }
    }

    $roleRows = $db->fetchAll(
        <<<'SQL'
SELECT TOP (1) [RoleId], [IsActive]
FROM [identity].[Roles]
WHERE UPPER([RoleCode]) = UPPER(:code)
ORDER BY [RoleId]
SQL,
        [':code' => $adminRole],
    );

    if ($roleRows === []) {
        $insertedRole = $db->fetchAll(
            <<<'SQL'
INSERT INTO [identity].[Roles] (
    [RoleCode], [RoleName], [Description], [IsSystemRole], [IsActive]
)
OUTPUT INSERTED.[RoleId]
VALUES (:code, :name, :description, 1, 1)
SQL,
            [
                ':code' => $adminRole,
                ':name' => 'Administrator',
                ':description' => 'Full administrative access to user, role and permission management.',
            ],
        );
        $adminRoleId = (int) ($insertedRole[0]['RoleId'] ?? 0);
        echo "  + role {$adminRole}\n";
    } else {
        if ((int) ($roleRows[0]['IsActive'] ?? 0) !== 1) {
            throw new RuntimeException('ADMINISTRATOR role exists but is inactive.');
        }
        $adminRoleId = (int) ($roleRows[0]['RoleId'] ?? 0);
        echo "  = role {$adminRole}\n";
    }

    if ($adminRoleId <= 0) {
        throw new RuntimeException('Unable to resolve ADMINISTRATOR RoleId.');
    }

    foreach ($permissionIds as $code => $permissionId) {
        $mapping = $db->fetchAll(
            <<<'SQL'
SELECT TOP (1) [RolePermissionId], [IsActive]
FROM [identity].[RolePermissions]
WHERE [RoleId] = :roleId AND [PermissionId] = :permissionId
ORDER BY [RolePermissionId]
SQL,
            [':roleId' => $adminRoleId, ':permissionId' => $permissionId],
        );

        if ($mapping === []) {
            $db->execute(
                <<<'SQL'
INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive])
VALUES (:roleId, :permissionId, 1)
SQL,
                [':roleId' => $adminRoleId, ':permissionId' => $permissionId],
            );
            echo "  + map {$adminRole} → {$code}\n";
        } elseif ((int) ($mapping[0]['IsActive'] ?? 0) !== 1) {
            $db->execute(
                <<<'SQL'
UPDATE [identity].[RolePermissions]
SET [IsActive] = 1
WHERE [RoleId] = :roleId AND [PermissionId] = :permissionId
SQL,
                [':roleId' => $adminRoleId, ':permissionId' => $permissionId],
            );
            echo "  ! reactivated map {$adminRole} → {$code}\n";
        }
    }

    $users = $db->fetchAll(
        <<<'SQL'
SELECT u.[UserId], u.[EmailAddress], s.[StatusCode]
FROM [identity].[Users] u
INNER JOIN [identity].[UserStatuses] s ON s.[UserStatusId] = u.[UserStatusId]
WHERE u.[NormalizedEmailAddress] = :email
SQL,
        [':email' => strtolower($targetEmail)],
    );

    if ($users === []) {
        throw new RuntimeException("No user found for {$targetEmail}. Create/link the user first.");
    }

    if (count($users) > 1) {
        throw new RuntimeException("Multiple users found for {$targetEmail}.");
    }

    $userId = (int) ($users[0]['UserId'] ?? 0);
    $status = (string) ($users[0]['StatusCode'] ?? '');
    if ($userId <= 0) {
        throw new RuntimeException('Unable to resolve UserId.');
    }
    if ($status !== 'active') {
        throw new RuntimeException("User {$targetEmail} status is '{$status}' (expected active).");
    }

    $userRole = $db->fetchAll(
        <<<'SQL'
SELECT TOP (1) [UserRoleId], [IsActive]
FROM [identity].[UserRoles]
WHERE [UserId] = :userId AND [RoleId] = :roleId
ORDER BY [UserRoleId]
SQL,
        [':userId' => $userId, ':roleId' => $adminRoleId],
    );

    if ($userRole === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [identity].[UserRoles] ([UserId], [RoleId], [AssignedByUserId], [ExpiresAt], [IsActive])
VALUES (:userId, :roleId, NULL, NULL, 1)
SQL,
            [':userId' => $userId, ':roleId' => $adminRoleId],
        );
        echo "  + assigned {$adminRole} to {$targetEmail}\n";
    } elseif ((int) ($userRole[0]['IsActive'] ?? 0) !== 1) {
        $db->execute(
            <<<'SQL'
UPDATE [identity].[UserRoles]
SET [IsActive] = 1, [ExpiresAt] = NULL, [AssignedAt] = SYSDATETIMEOFFSET()
WHERE [UserId] = :userId AND [RoleId] = :roleId
SQL,
            [':userId' => $userId, ':roleId' => $adminRoleId],
        );
        echo "  ! reactivated {$adminRole} for {$targetEmail}\n";
    } else {
        echo "  = {$targetEmail} already has active {$adminRole}\n";
    }

    $verify = $db->fetchAll(
        <<<'SQL'
SELECT r.[RoleCode], COUNT(p.[PermissionId]) AS [PermissionCount]
FROM [identity].[Users] u
INNER JOIN [identity].[UserRoles] ur
    ON ur.[UserId] = u.[UserId]
   AND ur.[IsActive] = 1
   AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
INNER JOIN [identity].[Roles] r
    ON r.[RoleId] = ur.[RoleId]
   AND r.[IsActive] = 1
LEFT JOIN [identity].[RolePermissions] rp
    ON rp.[RoleId] = r.[RoleId]
   AND rp.[IsActive] = 1
LEFT JOIN [identity].[Permissions] p
    ON p.[PermissionId] = rp.[PermissionId]
   AND p.[IsActive] = 1
WHERE u.[UserId] = :userId
GROUP BY r.[RoleCode]
ORDER BY r.[RoleCode]
SQL,
        [':userId' => $userId],
    );

    $db->commit();

    echo "\nSuccess. Active roles for {$targetEmail}:\n";
    foreach ($verify as $row) {
        echo sprintf(
            "  - %s (%d active permissions)\n",
            (string) ($row['RoleCode'] ?? ''),
            (int) ($row['PermissionCount'] ?? 0),
        );
    }
    echo "\nSign out/in (or refresh /api/v1/me) to pick up ADMINISTRATOR permissions.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
