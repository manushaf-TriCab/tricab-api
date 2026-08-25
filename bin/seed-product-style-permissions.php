<?php

declare(strict_types=1);

/**
 * DEVELOPMENT seed: PRODUCT.STYLES.* permissions + ADMINISTRATOR role mapping.
 *
 * Idempotent. Does not grant the permissions to portal or employee roles.
 *
 * Usage (from tricab-api):
 *   php bin/seed-product-style-permissions.php
 *   php bin/seed-product-style-permissions.php thilanh@tricab.com
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Environment::settings(dirname(__DIR__));

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDOUT, "USER_REPOSITORY is not azure-sql — in-memory mode already grants PRODUCT.STYLES.* to ADMINISTRATOR.\n");
    exit(0);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$targetEmail = $argv[1] ?? 'thilanh@tricab.com';

/** @var list<array{code: string, name: string, description: string}> $catalog */
$catalog = [
    [
        'code' => 'PRODUCT.STYLES.READ',
        'name' => 'View product styles',
        'description' => 'View product style hierarchy and inherited resources.',
    ],
    [
        'code' => 'PRODUCT.STYLES.MANAGE',
        'name' => 'Manage product styles',
        'description' => 'Create and maintain product style hierarchy.',
    ],
    [
        'code' => 'PRODUCT.STYLES.INHERITANCE.MANAGE',
        'name' => 'Manage product style inheritance',
        'description' => 'Configure style inheritance and make local copies.',
    ],
];

$resolvePermission = static function (SqlConnection $db, string $code): ?array {
    $rows = $db->fetchAll(
        'SELECT TOP (1) [PermissionId], [IsActive] FROM [identity].[Permissions] WHERE UPPER([PermissionCode]) = UPPER(:code) ORDER BY [PermissionId]',
        [':code' => $code],
    );

    return $rows === [] ? null : [
        'id' => (int) $rows[0]['PermissionId'],
        'active' => (int) $rows[0]['IsActive'] === 1,
    ];
};

echo "Seeding PRODUCT.STYLES.* permissions for ADMINISTRATOR...\n";
$db->beginTransaction();

try {
    $permissionIds = [];

    foreach ($catalog as $definition) {
        $existing = $resolvePermission($db, $definition['code']);

        if ($existing === null) {
            $db->execute(
                <<<'SQL'
INSERT INTO [identity].[Permissions] (
    [PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive]
) VALUES (:code, :name, :description, N'product', 1)
SQL,
                [
                    ':code' => $definition['code'],
                    ':name' => $definition['name'],
                    ':description' => $definition['description'],
                ],
            );
            echo "  + permission {$definition['code']}\n";
        } elseif (!$existing['active']) {
            $db->execute(
                'UPDATE [identity].[Permissions] SET [IsActive] = 1, [UpdatedAt] = SYSDATETIMEOFFSET() WHERE [PermissionId] = :id',
                [':id' => $existing['id']],
            );
            echo "  ^ reactivated permission {$definition['code']}\n";
        } else {
            echo "  = permission {$definition['code']}\n";
        }

        $resolved = $resolvePermission($db, $definition['code']);
        if ($resolved === null) {
            throw new RuntimeException(sprintf('Failed to resolve permission %s.', $definition['code']));
        }
        $permissionIds[$definition['code']] = $resolved['id'];
    }

    $roleRows = $db->fetchAll(
        'SELECT TOP (1) [RoleId], [IsActive] FROM [identity].[Roles] WHERE UPPER([RoleCode]) = UPPER(:code) ORDER BY [RoleId]',
        [':code' => ProtectedIdentityCatalog::ROLE_ADMINISTRATOR],
    );

    if ($roleRows === []) {
        throw new RuntimeException('ADMINISTRATOR role does not exist. Run seed-user-management-permissions.sql first.');
    }
    if ((int) $roleRows[0]['IsActive'] !== 1) {
        throw new RuntimeException('ADMINISTRATOR role exists but is inactive.');
    }

    $administratorRoleId = (int) $roleRows[0]['RoleId'];
    echo "  role ADMINISTRATOR id={$administratorRoleId}\n";

    foreach ($permissionIds as $code => $permissionId) {
        $mapping = $db->fetchAll(
            'SELECT TOP (1) [RolePermissionId], [IsActive] FROM [identity].[RolePermissions] WHERE [RoleId] = :roleId AND [PermissionId] = :permissionId ORDER BY [RolePermissionId]',
            [':roleId' => $administratorRoleId, ':permissionId' => $permissionId],
        );

        if ($mapping === []) {
            $db->execute(
                'INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive]) VALUES (:roleId, :permissionId, 1)',
                [':roleId' => $administratorRoleId, ':permissionId' => $permissionId],
            );
            echo "  + ADMINISTRATOR → {$code}\n";
            continue;
        }

        if ((int) $mapping[0]['IsActive'] !== 1) {
            $db->execute(
                'UPDATE [identity].[RolePermissions] SET [IsActive] = 1 WHERE [RolePermissionId] = :id',
                [':id' => (int) $mapping[0]['RolePermissionId']],
            );
            echo "  ^ reactivated ADMINISTRATOR → {$code}\n";
            continue;
        }

        echo "  = ADMINISTRATOR → {$code}\n";
    }

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

$userPermissions = $db->fetchAll(
    <<<'SQL'
SELECT DISTINCT p.[PermissionCode]
FROM [identity].[Users] u
INNER JOIN [identity].[UserRoles] ur
    ON ur.[UserId] = u.[UserId]
   AND ur.[IsActive] = 1
   AND (ur.[ExpiresAt] IS NULL OR ur.[ExpiresAt] > SYSDATETIMEOFFSET())
INNER JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] AND r.[IsActive] = 1
INNER JOIN [identity].[RolePermissions] rp ON rp.[RoleId] = r.[RoleId] AND rp.[IsActive] = 1
INNER JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId] AND p.[IsActive] = 1
WHERE LOWER(u.[EmailAddress]) = LOWER(:email)
  AND UPPER(p.[PermissionCode]) LIKE N'PRODUCT.STYLES.%'
ORDER BY p.[PermissionCode]
SQL,
    [':email' => $targetEmail],
);

if ($userPermissions === []) {
    echo "  {$targetEmail}: no PRODUCT.STYLES.* permissions resolved — check the user holds an active ADMINISTRATOR role\n";
} else {
    echo "  {$targetEmail}: " . implode(', ', array_map(
        static fn (array $row): string => (string) $row['PermissionCode'],
        $userPermissions,
    )) . "\n";
}

echo "\nAdministrators now have full Product Styles access. Refresh the Admin session if the UI was already open.\n";
