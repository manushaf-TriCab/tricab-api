<?php

declare(strict_types=1);

/**
 * DEVELOPMENT-ONLY sample seed for Customer / Contact foundation (Stage 2).
 *
 * Idempotent. No passwords, no WordPress secrets, no fake Entra identities.
 *
 * Usage (from tricab-api):
 *   php bin/seed-customer-sample-data.php
 *   php bin/ensure-customer-schema.php --seed
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

if (!isset($db) || !$db instanceof SqlConnection) {
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
}

$required = [
    '[customer].[Customers]',
    '[customer].[CustomerContacts]',
    '[customer].[CustomerContactUsers]',
    '[migration].[LegacyCustomerUserMappings]',
];

foreach ($required as $table) {
    $rows = $db->fetchAll(
        'SELECT CASE WHEN OBJECT_ID(:name, N\'U\') IS NULL THEN 0 ELSE 1 END AS [TableExists]',
        [':name' => $table],
    );
    if ((int) ($rows[0]['TableExists'] ?? 0) !== 1) {
        fwrite(STDERR, "Missing {$table}. Run php bin/ensure-customer-schema.php first.\n");
        exit(1);
    }
}

$statusRows = $db->fetchAll(
    'SELECT [UserStatusId] FROM [identity].[UserStatuses] WHERE [StatusCode] = :status',
    [':status' => 'active'],
);
$activeStatusId = isset($statusRows[0]['UserStatusId']) ? (int) $statusRows[0]['UserStatusId'] : 0;
if ($activeStatusId < 1) {
    fwrite(STDERR, "identity.UserStatuses is missing StatusCode=active.\n");
    exit(1);
}

/**
 * @param array<string, scalar|null> $payload
 */
$ensureCustomer = static function (SqlConnection $db, string $code, array $payload): int {
    $existing = $db->fetchAll(
        'SELECT [CustomerId] FROM [customer].[Customers] WHERE [CustomerCode] = :code',
        [':code' => $code],
    );
    if ($existing !== []) {
        return (int) $existing[0]['CustomerId'];
    }

    $db->execute(
        <<<'SQL'
INSERT INTO [customer].[Customers] (
    [LegacyCustomerId], [CustomerCode], [CustomerName], [StatusCode],
    [AddressLine1], [Suburb], [Postcode], [CountryCode],
    [MarketCode], [SalesRepresentative], [TerritoryCode], [PhoneNumber], [IsActive]
) VALUES (
    :legacyCustomerId, :customerCode, :customerName, :statusCode,
    :addressLine1, :suburb, :postcode, :countryCode,
    :marketCode, :salesRepresentative, :territoryCode, :phoneNumber, :isActive
)
SQL,
        $payload,
    );

    $created = $db->fetchAll(
        'SELECT [CustomerId] FROM [customer].[Customers] WHERE [CustomerCode] = :code',
        [':code' => $code],
    );

    return (int) $created[0]['CustomerId'];
};

/**
 * @param array<string, scalar|null> $payload
 */
$ensureContact = static function (SqlConnection $db, int $customerId, string $normalizedEmail, array $payload): int {
    $existing = $db->fetchAll(
        <<<'SQL'
SELECT [CustomerContactId]
FROM [customer].[CustomerContacts]
WHERE [CustomerId] = :customerId AND [NormalizedEmailAddress] = :email
SQL,
        [':customerId' => $customerId, ':email' => $normalizedEmail],
    );
    if ($existing !== []) {
        return (int) $existing[0]['CustomerContactId'];
    }

    $db->execute(
        <<<'SQL'
INSERT INTO [customer].[CustomerContacts] (
    [CustomerId], [LegacyContactId], [DisplayName], [EmailAddress], [NormalizedEmailAddress],
    [PositionTitle], [PhoneNumber], [MobileNumber], [IsPrimaryContact], [IsActive], [PortalAccessStatusCode]
) VALUES (
    :customerId, :legacyContactId, :displayName, :emailAddress, :normalizedEmailAddress,
    :positionTitle, :phoneNumber, :mobileNumber, :isPrimaryContact, :isActive, :portalAccessStatusCode
)
SQL,
        $payload,
    );

    $created = $db->fetchAll(
        <<<'SQL'
SELECT [CustomerContactId]
FROM [customer].[CustomerContacts]
WHERE [CustomerId] = :customerId AND [NormalizedEmailAddress] = :email
SQL,
        [':customerId' => $customerId, ':email' => $normalizedEmail],
    );

    return (int) $created[0]['CustomerContactId'];
};

$db->beginTransaction();

try {
    $melbourneId = $ensureCustomer($db, 'MEL-ELEC-001', [
        ':legacyCustomerId' => 900001,
        ':customerCode' => 'MEL-ELEC-001',
        ':customerName' => 'Melbourne Electrical Supplies',
        ':statusCode' => 'active',
        ':addressLine1' => '12 Flinders Street',
        ':suburb' => 'Melbourne',
        ':postcode' => '3000',
        ':countryCode' => 'AU',
        ':marketCode' => 'ELECTRICAL',
        ':salesRepresentative' => 'Sample Rep VIC',
        ':territoryCode' => 'VIC-METRO',
        ':phoneNumber' => '+61 3 9000 1001',
        ':isActive' => 1,
    ]);
    echo "  customer MEL-ELEC-001 id={$melbourneId}\n";

    $sydneyId = $ensureCustomer($db, 'SYD-IND-001', [
        ':legacyCustomerId' => 900002,
        ':customerCode' => 'SYD-IND-001',
        ':customerName' => 'Sydney Industrial Cables',
        ':statusCode' => 'active',
        ':addressLine1' => '88 George Street',
        ':suburb' => 'Sydney',
        ':postcode' => '2000',
        ':countryCode' => 'AU',
        ':marketCode' => 'INDUSTRIAL',
        ':salesRepresentative' => 'Sample Rep NSW',
        ':territoryCode' => 'NSW-METRO',
        ':phoneNumber' => '+61 2 9000 2002',
        ':isActive' => 1,
    ]);
    echo "  customer SYD-IND-001 id={$sydneyId}\n";

    $brisbaneId = $ensureCustomer($db, 'BNE-ENR-001', [
        ':legacyCustomerId' => 900003,
        ':customerCode' => 'BNE-ENR-001',
        ':customerName' => 'Brisbane Energy Systems',
        ':statusCode' => 'active',
        ':addressLine1' => '45 Queen Street',
        ':suburb' => 'Brisbane',
        ':postcode' => '4000',
        ':countryCode' => 'AU',
        ':marketCode' => 'ENERGY',
        ':salesRepresentative' => 'Sample Rep QLD',
        ':territoryCode' => 'QLD-METRO',
        ':phoneNumber' => '+61 7 9000 3003',
        ':isActive' => 1,
    ]);
    echo "  customer BNE-ENR-001 id={$brisbaneId}\n";

    $portalEmail = 'customer.admin@example.com';

    $melbournePrimaryContactId = $ensureContact($db, $melbourneId, $portalEmail, [
        ':customerId' => $melbourneId,
        ':legacyContactId' => 910001,
        ':displayName' => 'Alex Customer',
        ':emailAddress' => $portalEmail,
        ':normalizedEmailAddress' => $portalEmail,
        ':positionTitle' => 'Procurement Manager',
        ':phoneNumber' => '+61 3 9000 1010',
        ':mobileNumber' => '+61 400 000 101',
        ':isPrimaryContact' => 1,
        ':isActive' => 1,
        ':portalAccessStatusCode' => 'active',
    ]);
    $ensureContact($db, $melbourneId, 'sam.buyer@example.com', [
        ':customerId' => $melbourneId,
        ':legacyContactId' => 910002,
        ':displayName' => 'Sam Buyer',
        ':emailAddress' => 'sam.buyer@example.com',
        ':normalizedEmailAddress' => 'sam.buyer@example.com',
        ':positionTitle' => 'Buyer',
        ':phoneNumber' => '+61 3 9000 1011',
        ':mobileNumber' => '+61 400 000 102',
        ':isPrimaryContact' => 0,
        ':isActive' => 1,
        ':portalAccessStatusCode' => 'not-invited',
    ]);

    $ensureContact($db, $sydneyId, 'jordan.ops@example.com', [
        ':customerId' => $sydneyId,
        ':legacyContactId' => 920001,
        ':displayName' => 'Jordan Ops',
        ':emailAddress' => 'jordan.ops@example.com',
        ':normalizedEmailAddress' => 'jordan.ops@example.com',
        ':positionTitle' => 'Operations Lead',
        ':phoneNumber' => '+61 2 9000 2010',
        ':mobileNumber' => '+61 400 000 201',
        ':isPrimaryContact' => 1,
        ':isActive' => 1,
        ':portalAccessStatusCode' => 'invited',
    ]);
    $ensureContact($db, $sydneyId, 'taylor.store@example.com', [
        ':customerId' => $sydneyId,
        ':legacyContactId' => 920002,
        ':displayName' => 'Taylor Store',
        ':emailAddress' => 'taylor.store@example.com',
        ':normalizedEmailAddress' => 'taylor.store@example.com',
        ':positionTitle' => 'Store Supervisor',
        ':phoneNumber' => '+61 2 9000 2011',
        ':mobileNumber' => '+61 400 000 202',
        ':isPrimaryContact' => 0,
        ':isActive' => 1,
        ':portalAccessStatusCode' => 'not-invited',
    ]);

    $ensureContact($db, $brisbaneId, 'casey.energy@example.com', [
        ':customerId' => $brisbaneId,
        ':legacyContactId' => 930001,
        ':displayName' => 'Casey Energy',
        ':emailAddress' => 'casey.energy@example.com',
        ':normalizedEmailAddress' => 'casey.energy@example.com',
        ':positionTitle' => 'Project Engineer',
        ':phoneNumber' => '+61 7 9000 3010',
        ':mobileNumber' => '+61 400 000 301',
        ':isPrimaryContact' => 1,
        ':isActive' => 1,
        ':portalAccessStatusCode' => 'suspended',
    ]);
    $ensureContact($db, $brisbaneId, 'riley.admin@example.com', [
        ':customerId' => $brisbaneId,
        ':legacyContactId' => 930002,
        ':displayName' => 'Riley Admin',
        ':emailAddress' => 'riley.admin@example.com',
        ':normalizedEmailAddress' => 'riley.admin@example.com',
        ':positionTitle' => 'Office Administrator',
        ':phoneNumber' => '+61 7 9000 3011',
        ':mobileNumber' => '+61 400 000 302',
        ':isPrimaryContact' => 0,
        ':isActive' => 1,
        ':portalAccessStatusCode' => 'declined',
    ]);
    echo "  contacts seeded (2 per customer)\n";

    $userRows = $db->fetchAll(
        'SELECT [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = :email',
        [':email' => $portalEmail],
    );
    if ($userRows === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [identity].[Users] (
    [EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId]
) VALUES (
    :email, :normalizedEmail, :displayName, :statusId
)
SQL,
            [
                ':email' => $portalEmail,
                ':normalizedEmail' => $portalEmail,
                ':displayName' => 'Alex Customer',
                ':statusId' => $activeStatusId,
            ],
        );
        $userRows = $db->fetchAll(
            'SELECT [UserId] FROM [identity].[Users] WHERE [NormalizedEmailAddress] = :email',
            [':email' => $portalEmail],
        );
        echo "  + portal user {$portalEmail}\n";
    } else {
        echo "  = portal user {$portalEmail}\n";
    }
    $portalUserId = (int) $userRows[0]['UserId'];

    $linkRows = $db->fetchAll(
        <<<'SQL'
SELECT [CustomerContactUserId]
FROM [customer].[CustomerContactUsers]
WHERE [CustomerContactId] = :contactId AND [UserId] = :userId
SQL,
        [':contactId' => $melbournePrimaryContactId, ':userId' => $portalUserId],
    );
    if ($linkRows === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [customer].[CustomerContactUsers] (
    [CustomerContactId], [UserId], [IsPrimary], [IsActive]
) VALUES (
    :contactId, :userId, 1, 1
)
SQL,
            [':contactId' => $melbournePrimaryContactId, ':userId' => $portalUserId],
        );
        echo "  + contact-user link\n";
    } else {
        $db->execute(
            <<<'SQL'
UPDATE [customer].[CustomerContactUsers]
SET [IsPrimary] = 1, [IsActive] = 1, [UnlinkedAtUtc] = NULL
WHERE [CustomerContactId] = :contactId AND [UserId] = :userId
SQL,
            [':contactId' => $melbournePrimaryContactId, ':userId' => $portalUserId],
        );
        echo "  = contact-user link\n";
    }

    $roleRows = $db->fetchAll(
        'SELECT [RoleId] FROM [identity].[Roles] WHERE UPPER([RoleCode]) = :code',
        [':code' => 'CUSTOMER_ADMIN'],
    );
    if ($roleRows === []) {
        echo "  ~ CUSTOMER_ADMIN role missing (run Stage 3 seed to assign)\n";
    } else {
        $roleId = (int) $roleRows[0]['RoleId'];
        $userRoleRows = $db->fetchAll(
            'SELECT [UserRoleId] FROM [identity].[UserRoles] WHERE [UserId] = :userId AND [RoleId] = :roleId',
            [':userId' => $portalUserId, ':roleId' => $roleId],
        );
        if ($userRoleRows === []) {
            $db->execute(
                'INSERT INTO [identity].[UserRoles] ([UserId], [RoleId], [IsActive]) VALUES (:userId, :roleId, 1)',
                [':userId' => $portalUserId, ':roleId' => $roleId],
            );
            echo "  + CUSTOMER_ADMIN role assigned\n";
        } else {
            $db->execute(
                'UPDATE [identity].[UserRoles] SET [IsActive] = 1, [ExpiresAt] = NULL WHERE [UserId] = :userId AND [RoleId] = :roleId',
                [':userId' => $portalUserId, ':roleId' => $roleId],
            );
            echo "  = CUSTOMER_ADMIN role assigned\n";
        }
    }

    $mappingRows = $db->fetchAll(
        <<<'SQL'
SELECT [LegacyCustomerUserMappingId]
FROM [migration].[LegacyCustomerUserMappings]
WHERE [LegacyEmailAddress] = :email AND [MigrationStatusCode] = N'created-user'
SQL,
        [':email' => $portalEmail],
    );
    if ($mappingRows === []) {
        $db->execute(
            <<<'SQL'
INSERT INTO [migration].[LegacyCustomerUserMappings] (
    [LegacyWordPressUserId], [LegacyUserLogin], [LegacyEmailAddress],
    [LegacyCustomerId], [LegacyContactRowId],
    [CustomerId], [CustomerContactId], [UserId],
    [MigrationStatusCode], [MigrationNotes], [MigratedAtUtc]
) VALUES (
    NULL, N'customer.admin', :email,
    900001, NULL,
    :customerId, :contactId, :userId,
    N'created-user',
    N'DEVELOPMENT seed only — synthetic sample; not a WordPress migration.',
    SYSUTCDATETIME()
)
SQL,
            [
                ':email' => $portalEmail,
                ':customerId' => $melbourneId,
                ':contactId' => $melbournePrimaryContactId,
                ':userId' => $portalUserId,
            ],
        );
        echo "  + migration mapping sample row\n";
    } else {
        echo "  = migration mapping sample row\n";
    }

    $db->commit();
    echo "Sample seed complete.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, 'Seed failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
