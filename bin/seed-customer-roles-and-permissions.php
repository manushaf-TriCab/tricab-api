<?php

declare(strict_types=1);

/**
 * DEVELOPMENT seed: CUSTOMER_USER / CUSTOMER_ADMIN roles + Stage 3 permissions.
 *
 * Idempotent. Does not assign roles to users (use seed-customer-sample-data.php).
 *
 * Usage (from tricab-api):
 *   php bin/seed-customer-roles-and-permissions.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDOUT, "USER_REPOSITORY is not azure-sql — nothing to seed.\n");
    exit(0);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

/** @var list<array{code:string,name:string,description:string,module:string}> $permissionCatalog */
$permissionCatalog = [
    ['code' => 'CUSTOMER.READ', 'name' => 'Read customers', 'description' => 'View customer company records.', 'module' => 'customer'],
    ['code' => 'CUSTOMER.UPDATE', 'name' => 'Update customers', 'description' => 'Update customer company profile and commercial details.', 'module' => 'customer'],
    ['code' => 'CUSTOMER.CONTACT.READ', 'name' => 'Read customer contacts', 'description' => 'View contacts belonging to a customer.', 'module' => 'customer'],
    ['code' => 'CUSTOMER.CONTACT.CREATE', 'name' => 'Create customer contacts', 'description' => 'Create contacts for a customer company.', 'module' => 'customer'],
    ['code' => 'CUSTOMER.CONTACT.UPDATE', 'name' => 'Update customer contacts', 'description' => 'Update customer contact profile details.', 'module' => 'customer'],
    ['code' => 'CUSTOMER.CONTACT.STATUS.UPDATE', 'name' => 'Update contact status', 'description' => 'Activate or deactivate customer contacts.', 'module' => 'customer'],
    ['code' => 'CUSTOMER.USER.READ', 'name' => 'Read customer users', 'description' => 'View portal users linked to a customer.', 'module' => 'customer'],
    ['code' => 'CUSTOMER.USER.INVITE', 'name' => 'Invite customer users', 'description' => 'Invite or create portal users for customer contacts.', 'module' => 'customer'],
    ['code' => 'CUSTOMER.USER.LINK', 'name' => 'Link customer users', 'description' => 'Link an existing portal user to a customer contact.', 'module' => 'customer'],
    ['code' => 'CUSTOMER.USER.UNLINK', 'name' => 'Unlink customer users', 'description' => 'Unlink a portal user from a customer contact.', 'module' => 'customer'],
    ['code' => 'CUSTOMER.PORTAL_ACCESS.MANAGE', 'name' => 'Manage portal access', 'description' => 'Enable, disable, or change portal access for customer contacts.', 'module' => 'customer'],
    ['code' => 'PRODUCT.READ', 'name' => 'Read products', 'description' => 'View product catalogue information.', 'module' => 'product'],
    ['code' => 'QUOTE.READ', 'name' => 'Read quotes', 'description' => 'View quotes for linked customers.', 'module' => 'quote'],
    ['code' => 'QUOTE.CREATE', 'name' => 'Create quotes', 'description' => 'Create quotes for linked customers.', 'module' => 'quote'],
    ['code' => 'ORDER.READ', 'name' => 'Read orders', 'description' => 'View orders for linked customers.', 'module' => 'order'],
    ['code' => 'DOCUMENT.READ', 'name' => 'Read documents', 'description' => 'View documents for linked customers.', 'module' => 'document'],
];

$customerUserPermissions = [
    'ACCOUNT.LOGIN',
    'CUSTOMER.READ',
    'CUSTOMER.CONTACT.READ',
    'PRODUCT.READ',
    'QUOTE.READ',
    'ORDER.READ',
    'DOCUMENT.READ',
];

$customerAdminPermissions = array_values(array_unique(array_merge($customerUserPermissions, [
    'CUSTOMER.UPDATE',
    'CUSTOMER.CONTACT.CREATE',
    'CUSTOMER.CONTACT.UPDATE',
    'CUSTOMER.CONTACT.STATUS.UPDATE',
    'CUSTOMER.USER.READ',
    'CUSTOMER.USER.INVITE',
    'CUSTOMER.USER.LINK',
    'CUSTOMER.USER.UNLINK',
    'CUSTOMER.PORTAL_ACCESS.MANAGE',
    'QUOTE.CREATE',
])));

$resolvePermissionId = static function (SqlConnection $db, string $code): ?array {
    $rows = $db->fetchAll(
        'SELECT TOP (1) [PermissionId], [IsActive] FROM [identity].[Permissions] WHERE UPPER([PermissionCode]) = UPPER(:code) ORDER BY [PermissionId]',
        [':code' => $code],
    );
    if ($rows === []) {
        return null;
    }

    return [
        'id' => (int) $rows[0]['PermissionId'],
        'active' => (int) $rows[0]['IsActive'] === 1,
    ];
};

$ensurePermission = static function (SqlConnection $db, array $def) use ($resolvePermissionId): int {
    $existing = $resolvePermissionId($db, $def['code']);
    if ($existing !== null) {
        if (!$existing['active']) {
            throw new RuntimeException(sprintf('Permission %s exists but IsActive=0.', $def['code']));
        }

        return $existing['id'];
    }

    $db->execute(
        <<<'SQL'
INSERT INTO [identity].[Permissions] (
    [PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive]
) VALUES (
    :code, :name, :description, :module, 1
)
SQL,
        [
            ':code' => $def['code'],
            ':name' => $def['name'],
            ':description' => $def['description'],
            ':module' => $def['module'],
        ],
    );

    $created = $resolvePermissionId($db, $def['code']);
    if ($created === null) {
        throw new RuntimeException(sprintf('Failed to create permission %s.', $def['code']));
    }

    return $created['id'];
};

$ensureRole = static function (SqlConnection $db, string $code, string $name, string $description): int {
    $rows = $db->fetchAll(
        'SELECT TOP (1) [RoleId], [IsActive] FROM [identity].[Roles] WHERE UPPER([RoleCode]) = UPPER(:code) ORDER BY [RoleId]',
        [':code' => $code],
    );
    if ($rows !== []) {
        if ((int) $rows[0]['IsActive'] !== 1) {
            throw new RuntimeException(sprintf('Role %s exists but IsActive=0.', $code));
        }

        return (int) $rows[0]['RoleId'];
    }

    $db->execute(
        <<<'SQL'
INSERT INTO [identity].[Roles] (
    [RoleCode], [RoleName], [Description], [IsSystemRole], [IsActive]
) VALUES (
    :code, :name, :description, 1, 1
)
SQL,
        [':code' => $code, ':name' => $name, ':description' => $description],
    );

    $created = $db->fetchAll(
        'SELECT TOP (1) [RoleId] FROM [identity].[Roles] WHERE UPPER([RoleCode]) = UPPER(:code) ORDER BY [RoleId]',
        [':code' => $code],
    );

    return (int) $created[0]['RoleId'];
};

$mapPermissions = static function (SqlConnection $db, int $roleId, array $codes, string $roleLabel) use ($resolvePermissionId): array {
    $inserted = 0;
    $existed = 0;

    foreach ($codes as $code) {
        $permission = $resolvePermissionId($db, $code);
        if ($permission === null || !$permission['active']) {
            throw new RuntimeException(sprintf('Required permission %s missing/inactive for %s.', $code, $roleLabel));
        }

        $mapping = $db->fetchAll(
            'SELECT TOP (1) [IsActive] FROM [identity].[RolePermissions] WHERE [RoleId] = :roleId AND [PermissionId] = :permissionId ORDER BY [RolePermissionId]',
            [':roleId' => $roleId, ':permissionId' => $permission['id']],
        );

        if ($mapping === []) {
            $db->execute(
                'INSERT INTO [identity].[RolePermissions] ([RoleId], [PermissionId], [IsActive]) VALUES (:roleId, :permissionId, 1)',
                [':roleId' => $roleId, ':permissionId' => $permission['id']],
            );
            ++$inserted;
            continue;
        }

        if ((int) $mapping[0]['IsActive'] !== 1) {
            throw new RuntimeException(sprintf('Mapping %s → %s exists but IsActive=0.', $roleLabel, $code));
        }

        ++$existed;
    }

    return ['inserted' => $inserted, 'existed' => $existed];
};

echo "Seeding customer roles and permissions...\n";
$db->beginTransaction();

try {
    $login = $resolvePermissionId($db, 'ACCOUNT.LOGIN');
    if ($login === null) {
        $ensurePermission($db, [
            'code' => 'ACCOUNT.LOGIN',
            'name' => 'Log in to TriCab',
            'description' => 'Allows a user to sign in to the TriCab platform.',
            'module' => 'identity',
        ]);
        echo "  + ACCOUNT.LOGIN\n";
    } elseif (!$login['active']) {
        throw new RuntimeException('ACCOUNT.LOGIN exists but IsActive=0.');
    } else {
        echo "  = ACCOUNT.LOGIN\n";
    }

    $permissionsInserted = 0;
    $permissionsReused = 0;
    foreach ($permissionCatalog as $def) {
        $before = $resolvePermissionId($db, $def['code']);
        $ensurePermission($db, $def);
        if ($before === null) {
            ++$permissionsInserted;
            echo "  + permission {$def['code']}\n";
        } else {
            ++$permissionsReused;
            echo "  = permission {$def['code']}\n";
        }
    }

    $customerUserRoleId = $ensureRole(
        $db,
        'CUSTOMER_USER',
        'Customer User',
        'Standard customer portal access for a linked company contact.',
    );
    echo "  role CUSTOMER_USER id={$customerUserRoleId}\n";

    $customerAdminRoleId = $ensureRole(
        $db,
        'CUSTOMER_ADMIN',
        'Customer Administrator',
        'Customer portal administrator for a linked company; manage contacts and portal access.',
    );
    echo "  role CUSTOMER_ADMIN id={$customerAdminRoleId}\n";

    $userMap = $mapPermissions($db, $customerUserRoleId, $customerUserPermissions, 'CUSTOMER_USER');
    echo "  CUSTOMER_USER mappings +{$userMap['inserted']} ={$userMap['existed']}\n";

    $adminMap = $mapPermissions($db, $customerAdminRoleId, $customerAdminPermissions, 'CUSTOMER_ADMIN');
    echo "  CUSTOMER_ADMIN mappings +{$adminMap['inserted']} ={$adminMap['existed']}\n";

    $administratorRows = $db->fetchAll(
        'SELECT TOP (1) [RoleId], [IsActive] FROM [identity].[Roles] WHERE UPPER([RoleCode]) = N\'ADMINISTRATOR\' ORDER BY [RoleId]',
    );
    if ($administratorRows !== [] && (int) $administratorRows[0]['IsActive'] === 1) {
        $administratorRoleId = (int) $administratorRows[0]['RoleId'];
        $adminCatalogCodes = array_map(static fn (array $row): string => $row['code'], $permissionCatalog);
        $internalMap = $mapPermissions($db, $administratorRoleId, $adminCatalogCodes, 'ADMINISTRATOR');
        echo "  ADMINISTRATOR customer-catalog mappings +{$internalMap['inserted']} ={$internalMap['existed']}\n";
    } else {
        echo "  ~ ADMINISTRATOR missing/inactive — skipped Admin 2 catalog grant\n";
    }

    // Guard: customer roles must not hold internal admin catalog permissions.
    $forbidden = $db->fetchAll(
        <<<'SQL'
SELECT p.[PermissionCode]
FROM [identity].[RolePermissions] rp
INNER JOIN [identity].[Permissions] p ON p.[PermissionId] = rp.[PermissionId]
WHERE rp.[RoleId] IN (:customerUserRoleId, :customerAdminRoleId)
  AND rp.[IsActive] = 1
  AND (
        UPPER(p.[PermissionCode]) LIKE N'USER.%'
     OR UPPER(p.[PermissionCode]) LIKE N'ROLE.%'
     OR UPPER(p.[PermissionCode]) LIKE N'PERMISSION.%'
     OR UPPER(p.[PermissionCode]) LIKE N'AUDIT.%'
  )
SQL,
        [
            ':customerUserRoleId' => $customerUserRoleId,
            ':customerAdminRoleId' => $customerAdminRoleId,
        ],
    );
    if ($forbidden !== []) {
        throw new RuntimeException('CUSTOMER roles unexpectedly include internal admin permissions.');
    }

    $db->commit();
    echo "Customer roles/permissions seed complete.\n";
    echo "  permissionsInserted={$permissionsInserted} permissionsReused={$permissionsReused}\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Seed failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
