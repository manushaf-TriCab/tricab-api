<?php

declare(strict_types=1);

/**
 * DEVELOPMENT seed: MIGRATION.ADMIN + ADMINISTRATOR role mapping.
 *
 * Idempotent. Does not grant the permission to portal or employee roles.
 *
 * Usage (from tricab-api):
 *   php bin/seed-legacy-migration-permission.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Environment::settings(dirname(__DIR__));

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDOUT, "USER_REPOSITORY is not azure-sql — in-memory mode already grants MIGRATION.ADMIN to ADMINISTRATOR.\n");
    exit(0);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$code = 'MIGRATION.ADMIN';
$name = 'Administer Legacy Migration';
$description = 'Operate the reusable legacy migration framework. Administrators only.';

$resolvePermission = static function (SqlConnection $db, string $permissionCode): ?array {
    $rows = $db->fetchAll(
        'SELECT TOP (1) [PermissionId], [IsActive] FROM [identity].[Permissions] WHERE UPPER([PermissionCode]) = UPPER(:code) ORDER BY [PermissionId]',
        [':code' => $permissionCode],
    );

    return $rows === [] ? null : [
        'id' => (int) $rows[0]['PermissionId'],
        'active' => (int) $rows[0]['IsActive'] === 1,
    ];
};

echo "Seeding {$code} for ADMINISTRATOR...\n";
$db->beginTransaction();

try {
    $existing = $resolvePermission($db, $code);
    if ($existing === null) {
        $db->execute(
            <<<'SQL'
INSERT INTO [identity].[Permissions] (
    [PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive]
) VALUES (:code, :name, :description, N'migration', 1)
SQL,
            [
                ':code' => $code,
                ':name' => $name,
                ':description' => $description,
            ],
        );
        echo "  + permission {$code}\n";
    } elseif (!$existing['active']) {
        $db->execute(
            'UPDATE [identity].[Permissions] SET [IsActive] = 1, [UpdatedAt] = SYSDATETIMEOFFSET() WHERE [PermissionId] = :id',
            [':id' => $existing['id']],
        );
        echo "  ^ reactivated permission {$code}\n";
    } else {
        echo "  = permission {$code}\n";
    }

    $resolved = $resolvePermission($db, $code);
    if ($resolved === null) {
        throw new RuntimeException("Failed to resolve permission {$code}.");
    }

    $roleRows = $db->fetchAll(
        'SELECT TOP (1) [RoleId], [IsActive] FROM [identity].[Roles] WHERE UPPER([RoleCode]) = UPPER(:code) ORDER BY [RoleId]',
        [':code' => ProtectedIdentityCatalog::ROLE_ADMINISTRATOR],
    );
    if ($roleRows === []) {
        throw new RuntimeException('ADMINISTRATOR role does not exist.');
    }
    if ((int) $roleRows[0]['IsActive'] !== 1) {
        throw new RuntimeException('ADMINISTRATOR role exists but is inactive.');
    }

    $administratorRoleId = (int) $roleRows[0]['RoleId'];
    echo "  role ADMINISTRATOR id={$administratorRoleId}\n";

    $mapping = $db->fetchAll(
        'SELECT TOP (1) [RolePermissionId], [IsActive] FROM [identity].[RolePermissions] WHERE [RoleId] = :roleId AND [PermissionId] = :permissionId ORDER BY [RolePermissionId]',
        [':roleId' => $administratorRoleId, ':permissionId' => $resolved['id']],
    );

    if ($mapping === []) {
        $db->execute(
            'INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive]) VALUES (:roleId, :permissionId, 1)',
            [':roleId' => $administratorRoleId, ':permissionId' => $resolved['id']],
        );
        echo "  + ADMINISTRATOR → {$code}\n";
    } elseif ((int) $mapping[0]['IsActive'] !== 1) {
        $db->execute(
            'UPDATE [identity].[RolePermissions] SET [IsActive] = 1 WHERE [RolePermissionId] = :id',
            [':id' => (int) $mapping[0]['RolePermissionId']],
        );
        echo "  ^ reactivated ADMINISTRATOR → {$code}\n";
    } else {
        echo "  = ADMINISTRATOR → {$code}\n";
    }

    $db->commit();
    echo "Done. Administrators now have full Legacy Migration access. Refresh the Admin session if the UI was already open.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
