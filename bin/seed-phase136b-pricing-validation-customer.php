<?php

declare(strict_types=1);

/**
 * Phase 13.6B — NON-PRODUCTION pricing validation customer seed (idempotent).
 *
 * Creates/links ONLY:
 * - Customer PH13-PRICE-VALIDATION (AU / VIC / CONSULT1)
 * - One primary contact
 * - Link to existing thilanh@tricab.com user (no password, no Entra invent)
 *
 * Does NOT modify MEL-ELEC-001 / SYD-IND-001 / BNE-ENR-001.
 * Does NOT write ProductVariantPrices / legacy tc / WordPress.
 *
 * Usage:
 *   php bin/seed-phase136b-pricing-validation-customer.php
 *   php bin/seed-phase136b-pricing-validation-customer.php --cleanup-plan
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if (strtolower($settings->appEnv) === 'production') {
    fwrite(STDERR, "PRODUCTION_BLOCKED\n");
    exit(1);
}

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDERR, "USER_REPOSITORY is not azure-sql — abort.\n");
    exit(1);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

const CUSTOMER_CODE = 'PH13-PRICE-VALIDATION';
const CUSTOMER_NAME = 'Phase 13 Pricing Validation';
const CONTACT_EMAIL = 'ph13.pricing.validation@example.com';
const CONTACT_DISPLAY = 'Phase 13 Pricing Validation Contact';
const LINK_USER_EMAIL = 'thilanh@tricab.com';

$cleanupPlan = in_array('--cleanup-plan', $argv, true);

$srv = $db->fetchAll('SELECT @@SERVERNAME AS servername, DB_NAME() AS dbname')[0] ?? [];
fwrite(STDOUT, sprintf(
    "env=%s server=%s db=%s production=NO\n",
    $settings->appEnv,
    (string) ($srv['servername'] ?? ''),
    (string) ($srv['dbname'] ?? ''),
));

$userRows = $db->fetchAll(
    'SELECT [UserId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [EmailAddress]
     FROM [identity].[Users]
     WHERE [NormalizedEmailAddress] = :email',
    [':email' => strtoupper(LINK_USER_EMAIL)],
);
if ($userRows === []) {
    fwrite(STDERR, "VALIDATION_USER_MISSING: " . LINK_USER_EMAIL . " not found. Abort (do not invent password user).\n");
    exit(1);
}
$linkUserId = (int) $userRows[0]['UserId'];
$linkUserPublicId = (string) $userRows[0]['PublicId'];

$existing = $db->fetchAll(
    'SELECT [CustomerId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId],
            [CustomerCode], [CustomerName], [CountryCode], [TerritoryCode], [MarketCode], [StatusCode], [IsActive]
     FROM [customer].[Customers]
     WHERE [CustomerCode] = :code',
    [':code' => CUSTOMER_CODE],
);

if ($existing !== []) {
    $row = $existing[0];
    $ok = strtoupper((string) $row['CountryCode']) === 'AU'
        && strtoupper((string) $row['TerritoryCode']) === 'VIC'
        && strtoupper((string) $row['MarketCode']) === 'CONSULT1';
    if (!$ok) {
        fwrite(STDERR, "VALIDATION_CUSTOMER_CONFLICT: existing commercial context differs.\n");
        fwrite(STDERR, json_encode([
            'country' => $row['CountryCode'],
            'territory' => $row['TerritoryCode'],
            'market' => $row['MarketCode'],
        ], JSON_THROW_ON_ERROR) . "\n");
        exit(1);
    }
    $customerId = (int) $row['CustomerId'];
    $customerPublicId = (string) $row['PublicId'];
    $customerAction = 'NO_CHANGE';
} else {
    $db->execute(
        <<<'SQL'
INSERT INTO [customer].[Customers] (
    [LegacyCustomerId], [CustomerCode], [CustomerName], [StatusCode],
    [AddressLine1], [Suburb], [Postcode], [CountryCode],
    [MarketCode], [SalesRepresentative], [TerritoryCode], [PhoneNumber], [IsActive]
) VALUES (
    NULL, :customerCode, :customerName, :statusCode,
    :addressLine1, :suburb, :postcode, :countryCode,
    :marketCode, :salesRepresentative, :territoryCode, :phoneNumber, :isActive
)
SQL,
        [
            ':customerCode' => CUSTOMER_CODE,
            ':customerName' => CUSTOMER_NAME,
            ':statusCode' => 'active',
            ':addressLine1' => 'NON-PRODUCTION Phase 13.6B pricing validation only',
            ':suburb' => 'Melbourne',
            ':postcode' => '3000',
            ':countryCode' => 'AU',
            ':marketCode' => 'CONSULT1',
            ':salesRepresentative' => 'PH13-TEST',
            ':territoryCode' => 'VIC',
            ':phoneNumber' => null,
            ':isActive' => 1,
        ],
    );
    $created = $db->fetchAll(
        'SELECT [CustomerId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId]
         FROM [customer].[Customers] WHERE [CustomerCode] = :code',
        [':code' => CUSTOMER_CODE],
    );
    $customerId = (int) $created[0]['CustomerId'];
    $customerPublicId = (string) $created[0]['PublicId'];
    $customerAction = 'CREATED';
}

$normalizedContact = strtoupper(CONTACT_EMAIL);
$contactRows = $db->fetchAll(
    'SELECT [CustomerContactId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [PortalAccessStatusCode]
     FROM [customer].[CustomerContacts]
     WHERE [CustomerId] = :cid AND [NormalizedEmailAddress] = :email',
    [':cid' => $customerId, ':email' => $normalizedContact],
);
if ($contactRows !== []) {
    $contactId = (int) $contactRows[0]['CustomerContactId'];
    $contactPublicId = (string) $contactRows[0]['PublicId'];
    $contactAction = 'NO_CHANGE';
    if (strtolower((string) $contactRows[0]['PortalAccessStatusCode']) !== 'active') {
        $db->execute(
            'UPDATE [customer].[CustomerContacts]
             SET [PortalAccessStatusCode] = :status, [IsActive] = 1, [IsPrimaryContact] = 1
             WHERE [CustomerContactId] = :id',
            [':status' => 'active', ':id' => $contactId],
        );
        $contactAction = 'UPDATED_PORTAL_ACTIVE';
    }
} else {
    $db->execute(
        <<<'SQL'
INSERT INTO [customer].[CustomerContacts] (
    [CustomerId], [LegacyContactId], [DisplayName], [EmailAddress], [NormalizedEmailAddress],
    [PositionTitle], [PhoneNumber], [MobileNumber], [IsPrimaryContact], [IsActive], [PortalAccessStatusCode]
) VALUES (
    :customerId, NULL, :displayName, :emailAddress, :normalizedEmailAddress,
    :positionTitle, NULL, NULL, 1, 1, :portalStatus
)
SQL,
        [
            ':customerId' => $customerId,
            ':displayName' => CONTACT_DISPLAY,
            ':emailAddress' => CONTACT_EMAIL,
            ':normalizedEmailAddress' => $normalizedContact,
            ':positionTitle' => 'NON-PRODUCTION pricing validation',
            ':portalStatus' => 'active',
        ],
    );
    $contactCreated = $db->fetchAll(
        'SELECT [CustomerContactId], CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId]
         FROM [customer].[CustomerContacts]
         WHERE [CustomerId] = :cid AND [NormalizedEmailAddress] = :email',
        [':cid' => $customerId, ':email' => $normalizedContact],
    );
    $contactId = (int) $contactCreated[0]['CustomerContactId'];
    $contactPublicId = (string) $contactCreated[0]['PublicId'];
    $contactAction = 'CREATED';
}

$linkRows = $db->fetchAll(
    'SELECT [CustomerContactUserId], [IsActive], [UnlinkedAtUtc]
     FROM [customer].[CustomerContactUsers]
     WHERE [CustomerContactId] = :contactId AND [UserId] = :userId',
    [':contactId' => $contactId, ':userId' => $linkUserId],
);
if ($linkRows !== []) {
    $linkAction = 'NO_CHANGE';
    if ((int) $linkRows[0]['IsActive'] !== 1 || $linkRows[0]['UnlinkedAtUtc'] !== null) {
        $db->execute(
            'UPDATE [customer].[CustomerContactUsers]
             SET [IsActive] = 1, [IsPrimary] = 1, [UnlinkedAtUtc] = NULL, [LinkedAtUtc] = SYSUTCDATETIME()
             WHERE [CustomerContactUserId] = :id',
            [':id' => (int) $linkRows[0]['CustomerContactUserId']],
        );
        $linkAction = 'REACTIVATED';
    }
} else {
    $db->execute(
        <<<'SQL'
INSERT INTO [customer].[CustomerContactUsers] (
    [CustomerContactId], [UserId], [IsPrimary], [IsActive], [LinkedAtUtc], [CreatedByUserId]
) VALUES (
    :contactId, :userId, 1, 1, SYSUTCDATETIME(), :actorUserId
)
SQL,
        [
            ':contactId' => $contactId,
            ':userId' => $linkUserId,
            ':actorUserId' => $linkUserId,
        ],
    );
    $linkAction = 'CREATED';
}

$manifest = [
    'phase' => '13.6B',
    'purpose' => 'NON-PRODUCTION pricing validation only',
    'asOf' => gmdate('c'),
    'customer' => [
        'action' => $customerAction,
        'code' => CUSTOMER_CODE,
        'publicId' => $customerPublicId,
        'country' => 'AU',
        'territory' => 'VIC',
        'market' => 'CONSULT1',
        'currency' => 'AUD',
    ],
    'contact' => [
        'action' => $contactAction,
        'publicId' => $contactPublicId,
        'email' => CONTACT_EMAIL,
    ],
    'userLink' => [
        'action' => $linkAction,
        'userPublicId' => $linkUserPublicId,
        'userEmail' => LINK_USER_EMAIL,
    ],
    'cleanup' => [
        'executed' => false,
        'plan' => [
            '1' => 'DELETE CustomerContactUsers for PH13 contact only',
            '2' => 'DELETE/archive PH13 CustomerContacts',
            '3' => 'DELETE PH13 Customers row',
            'never' => 'Do not delete Users, Markets, Territories, MEL-ELEC/SYD/BNE, or ProductVariantPrices',
        ],
    ],
];

file_put_contents(
    $basePath . '/tmp/phase13.6b-validation-customer-manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
);

fwrite(STDOUT, json_encode([
    'customerAction' => $customerAction,
    'contactAction' => $contactAction,
    'linkAction' => $linkAction,
    'customerCode' => CUSTOMER_CODE,
    'country' => 'AU',
    'territory' => 'VIC',
    'market' => 'CONSULT1',
    'userLinked' => true,
    'cleanupPlanOnly' => $cleanupPlan,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
