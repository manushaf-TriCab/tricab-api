<?php

declare(strict_types=1);

/**
 * DEVELOPMENT seed: REGION.* permissions + ADMINISTRATOR role mapping.
 *
 * Idempotent. Also reports whether alter-product-regions.sql has been applied
 * and which REGION.* permissions the target user resolves to, so a 403 on
 * /api/v1/admin/regions can be diagnosed without opening SSMS.
 *
 * Usage (from tricab-api):
 *   php bin/seed-region-permissions.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = Environment::settings(dirname(__DIR__));

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDOUT, "USER_REPOSITORY is not azure-sql — in-memory mode already grants REGION.* to ADMINISTRATOR.\n");
    exit(0);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$targetEmail = $argv[1] ?? 'thilanh@tricab.com';

/** @var list<array{code: string, name: string, description: string}> $catalog */
$catalog = [
    ['code' => 'REGION.READ', 'name' => 'Read regions', 'description' => 'View website regions in TriCab Admin.'],
    ['code' => 'REGION.CREATE', 'name' => 'Create regions', 'description' => 'Create website regions in TriCab Admin.'],
    ['code' => 'REGION.UPDATE', 'name' => 'Update regions', 'description' => 'Update and activate website regions in TriCab Admin.'],
    ['code' => 'REGION.DELETE', 'name' => 'Delete regions', 'description' => 'Delete unused website regions in TriCab Admin.'],
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

echo "Seeding REGION.* permissions for ADMINISTRATOR...\n";
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
) VALUES (:code, :name, :description, N'region', 1)
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

echo "\nDiagnostics\n";

$regionColumns = $db->fetchAll(
    <<<'SQL'
SELECT [name]
FROM sys.columns
WHERE [object_id] = OBJECT_ID(N'[product].[Regions]')
  AND [name] IN (N'DisplayName', N'Flag', N'IsDefault', N'WebsiteEnabled', N'ShowInRegionPicker', N'IsDefaultWebsiteRegion')
SQL,
);
$presentColumns = array_map(static fn (array $row): string => (string) $row['name'], $regionColumns);
$missingColumns = array_values(array_diff(
    ['DisplayName', 'Flag', 'IsDefault', 'WebsiteEnabled', 'ShowInRegionPicker', 'IsDefaultWebsiteRegion'],
    $presentColumns,
));

if ($missingColumns === []) {
    echo "  migration: alter-product-regions.sql applied\n";

    $pickerRows = $db->fetchAll(
        <<<'SQL'
SELECT [RegionCode], [IsActive], [WebsiteEnabled], [ShowInRegionPicker], [IsDefaultWebsiteRegion]
FROM [product].[Regions]
WHERE [DeletedAtUtc] IS NULL
ORDER BY [DisplayOrder], [RegionCode]
SQL,
    );
    echo '  regions: ' . count($pickerRows) . " row(s)\n";
    foreach ($pickerRows as $row) {
        printf(
            "    %-6s active=%d website=%d picker=%d defaultWebsite=%d\n",
            (string) $row['RegionCode'],
            (int) $row['IsActive'],
            (int) $row['WebsiteEnabled'],
            (int) $row['ShowInRegionPicker'],
            (int) $row['IsDefaultWebsiteRegion'],
        );
    }
} else {
    echo '  migration: MISSING columns — ' . implode(', ', $missingColumns) . "\n";
    echo "            run database/scripts/development/alter-product-regions.sql\n";
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
  AND UPPER(p.[PermissionCode]) LIKE N'REGION.%'
ORDER BY p.[PermissionCode]
SQL,
    [':email' => $targetEmail],
);

if ($userPermissions === []) {
    echo "  {$targetEmail}: no REGION.* permissions resolved — check the user holds an active ADMINISTRATOR role\n";
} else {
    echo "  {$targetEmail}: " . implode(', ', array_map(
        static fn (array $row): string => (string) $row['PermissionCode'],
        $userPermissions,
    )) . "\n";
}

echo "\nRefresh TriCab Admin (or sign out/in) to pick up the new permissions.\n";
