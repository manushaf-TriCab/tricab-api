<?php

declare(strict_types=1);

/**
 * Development helper: ensure [customer] / [migration] Stage 2 tables exist on Azure SQL.
 *
 * Usage (from tricab-api):
 *   php bin/ensure-customer-schema.php
 *   php bin/ensure-customer-schema.php --seed
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);
$withSeed = in_array('--seed', $argv, true);

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDOUT, "USER_REPOSITORY is not azure-sql — nothing to migrate.\n");
    exit(0);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$ensureSchema = static function (SqlConnection $db, string $schema): void {
    $rows = $db->fetchAll(
        'SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.schemas WHERE name = :name) THEN 1 ELSE 0 END AS [SchemaExists]',
        [':name' => $schema],
    );
    if ((int) ($rows[0]['SchemaExists'] ?? 0) === 1) {
        echo "  = schema [{$schema}] already exists\n";

        return;
    }

    $db->execute(sprintf('CREATE SCHEMA [%s]', $schema));
    echo "  + created schema [{$schema}]\n";
};

$tableExists = static function (SqlConnection $db, string $qualifiedName): bool {
    $rows = $db->fetchAll(
        'SELECT CASE WHEN OBJECT_ID(:name, N\'U\') IS NULL THEN 0 ELSE 1 END AS [TableExists]',
        [':name' => $qualifiedName],
    );

    return (int) ($rows[0]['TableExists'] ?? 0) === 1;
};

$ensureIndex = static function (SqlConnection $db, string $table, string $indexName, string $ddl): void {
    $rows = $db->fetchAll(
        'SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(:table) AND name = :name) THEN 1 ELSE 0 END AS [IndexExists]',
        [':table' => $table, ':name' => $indexName],
    );
    if ((int) ($rows[0]['IndexExists'] ?? 0) === 1) {
        echo "  = index {$indexName}\n";

        return;
    }

    $db->execute($ddl);
    echo "  + index {$indexName}\n";
};

echo "Ensuring customer / migration schemas...\n";
$ensureSchema($db, 'customer');
$ensureSchema($db, 'migration');

echo "Ensuring [customer].[Customers]...\n";
if ($tableExists($db, '[customer].[Customers]')) {
    echo "  = table already exists\n";
} else {
    $db->execute(
        <<<'SQL'
CREATE TABLE [customer].[Customers] (
    [CustomerId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_customer_Customers_PublicId DEFAULT (NEWSEQUENTIALID()),
    [LegacyCustomerId] INT NULL,
    [CustomerCode] NVARCHAR(100) NULL,
    [CustomerName] NVARCHAR(200) NOT NULL,
    [StatusCode] NVARCHAR(20) NOT NULL,
    [AddressLine1] NVARCHAR(100) NULL,
    [AddressLine2] NVARCHAR(100) NULL,
    [AddressLine3] NVARCHAR(100) NULL,
    [AddressLine4] NVARCHAR(100) NULL,
    [Suburb] NVARCHAR(60) NULL,
    [Postcode] NVARCHAR(20) NULL,
    [CountryCode] NVARCHAR(4) NULL,
    [MarketCode] NVARCHAR(45) NULL,
    [SalesRepresentative] NVARCHAR(100) NULL,
    [TerritoryCode] NVARCHAR(45) NULL,
    [PhoneNumber] NVARCHAR(50) NULL,
    [IsActive] BIT NOT NULL CONSTRAINT DF_customer_Customers_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_customer_Customers_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_customer_Customers_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT PK_customer_Customers PRIMARY KEY CLUSTERED ([CustomerId]),
    CONSTRAINT UQ_customer_Customers_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_customer_Customers_CustomerName_NotEmpty CHECK (LEN(LTRIM(RTRIM([CustomerName]))) > 0),
    CONSTRAINT CK_customer_Customers_StatusCode_NotEmpty CHECK (LEN(LTRIM(RTRIM([StatusCode]))) > 0)
)
SQL,
    );
    echo "  + created table\n";
}

$ensureIndex($db, '[customer].[Customers]', 'UX_customer_Customers_CustomerCode_NotNull', 'CREATE UNIQUE NONCLUSTERED INDEX UX_customer_Customers_CustomerCode_NotNull ON [customer].[Customers] ([CustomerCode]) WHERE [CustomerCode] IS NOT NULL');
$ensureIndex($db, '[customer].[Customers]', 'UX_customer_Customers_LegacyCustomerId_NotNull', 'CREATE UNIQUE NONCLUSTERED INDEX UX_customer_Customers_LegacyCustomerId_NotNull ON [customer].[Customers] ([LegacyCustomerId]) WHERE [LegacyCustomerId] IS NOT NULL');
$ensureIndex($db, '[customer].[Customers]', 'IX_customer_Customers_CustomerName', 'CREATE NONCLUSTERED INDEX IX_customer_Customers_CustomerName ON [customer].[Customers] ([CustomerName])');
$ensureIndex($db, '[customer].[Customers]', 'IX_customer_Customers_StatusCode', 'CREATE NONCLUSTERED INDEX IX_customer_Customers_StatusCode ON [customer].[Customers] ([StatusCode])');
$ensureIndex($db, '[customer].[Customers]', 'IX_customer_Customers_CountryCode', 'CREATE NONCLUSTERED INDEX IX_customer_Customers_CountryCode ON [customer].[Customers] ([CountryCode])');
$ensureIndex($db, '[customer].[Customers]', 'IX_customer_Customers_TerritoryCode', 'CREATE NONCLUSTERED INDEX IX_customer_Customers_TerritoryCode ON [customer].[Customers] ([TerritoryCode])');

echo "Ensuring [customer].[CustomerContacts]...\n";
if ($tableExists($db, '[customer].[CustomerContacts]')) {
    echo "  = table already exists\n";
} else {
    $db->execute(
        <<<'SQL'
CREATE TABLE [customer].[CustomerContacts] (
    [CustomerContactId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_customer_CustomerContacts_PublicId DEFAULT (NEWSEQUENTIALID()),
    [CustomerId] BIGINT NOT NULL,
    [LegacyContactRowId] BIGINT NULL,
    [LegacyContactId] INT NULL,
    [DisplayName] NVARCHAR(255) NOT NULL,
    [EmailAddress] NVARCHAR(320) NULL,
    [NormalizedEmailAddress] NVARCHAR(320) NULL,
    [PositionTitle] NVARCHAR(255) NULL,
    [PhoneNumber] NVARCHAR(50) NULL,
    [MobileNumber] NVARCHAR(50) NULL,
    [IsPrimaryContact] BIT NOT NULL CONSTRAINT DF_customer_CustomerContacts_IsPrimaryContact DEFAULT (0),
    [IsActive] BIT NOT NULL CONSTRAINT DF_customer_CustomerContacts_IsActive DEFAULT (1),
    [PortalAccessStatusCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_customer_CustomerContacts_PortalAccessStatusCode DEFAULT (N'not-invited'),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_customer_CustomerContacts_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_customer_CustomerContacts_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT PK_customer_CustomerContacts PRIMARY KEY CLUSTERED ([CustomerContactId]),
    CONSTRAINT UQ_customer_CustomerContacts_PublicId UNIQUE ([PublicId]),
    CONSTRAINT FK_customer_CustomerContacts_Customers FOREIGN KEY ([CustomerId]) REFERENCES [customer].[Customers] ([CustomerId]) ON DELETE NO ACTION,
    CONSTRAINT CK_customer_CustomerContacts_DisplayName_NotEmpty CHECK (LEN(LTRIM(RTRIM([DisplayName]))) > 0),
    CONSTRAINT CK_customer_CustomerContacts_PortalAccessStatusCode CHECK (
        [PortalAccessStatusCode] IN (N'not-invited', N'invited', N'active', N'suspended', N'declined')
    )
)
SQL,
    );
    echo "  + created table\n";
}

$ensureIndex($db, '[customer].[CustomerContacts]', 'UX_customer_CustomerContacts_LegacyContactRowId_NotNull', 'CREATE UNIQUE NONCLUSTERED INDEX UX_customer_CustomerContacts_LegacyContactRowId_NotNull ON [customer].[CustomerContacts] ([LegacyContactRowId]) WHERE [LegacyContactRowId] IS NOT NULL');
$ensureIndex($db, '[customer].[CustomerContacts]', 'IX_customer_CustomerContacts_CustomerId_IsActive', 'CREATE NONCLUSTERED INDEX IX_customer_CustomerContacts_CustomerId_IsActive ON [customer].[CustomerContacts] ([CustomerId], [IsActive])');
$ensureIndex($db, '[customer].[CustomerContacts]', 'IX_customer_CustomerContacts_NormalizedEmailAddress', 'CREATE NONCLUSTERED INDEX IX_customer_CustomerContacts_NormalizedEmailAddress ON [customer].[CustomerContacts] ([NormalizedEmailAddress])');
$ensureIndex($db, '[customer].[CustomerContacts]', 'IX_customer_CustomerContacts_CustomerId_NormalizedEmail', 'CREATE NONCLUSTERED INDEX IX_customer_CustomerContacts_CustomerId_NormalizedEmail ON [customer].[CustomerContacts] ([CustomerId], [NormalizedEmailAddress])');

echo "Ensuring [customer].[CustomerContactUsers]...\n";
if ($tableExists($db, '[customer].[CustomerContactUsers]')) {
    echo "  = table already exists\n";
} else {
    $db->execute(
        <<<'SQL'
CREATE TABLE [customer].[CustomerContactUsers] (
    [CustomerContactUserId] BIGINT IDENTITY(1,1) NOT NULL,
    [CustomerContactId] BIGINT NOT NULL,
    [UserId] BIGINT NOT NULL,
    [IsPrimary] BIT NOT NULL CONSTRAINT DF_customer_CustomerContactUsers_IsPrimary DEFAULT (1),
    [IsActive] BIT NOT NULL CONSTRAINT DF_customer_CustomerContactUsers_IsActive DEFAULT (1),
    [LinkedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_customer_CustomerContactUsers_LinkedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UnlinkedAtUtc] DATETIME2(7) NULL,
    [CreatedByUserId] BIGINT NULL,
    CONSTRAINT PK_customer_CustomerContactUsers PRIMARY KEY CLUSTERED ([CustomerContactUserId]),
    CONSTRAINT FK_customer_CustomerContactUsers_Contact FOREIGN KEY ([CustomerContactId]) REFERENCES [customer].[CustomerContacts] ([CustomerContactId]) ON DELETE NO ACTION,
    CONSTRAINT FK_customer_CustomerContactUsers_User FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
    CONSTRAINT FK_customer_CustomerContactUsers_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
    CONSTRAINT UQ_customer_CustomerContactUsers_Contact_User UNIQUE ([CustomerContactId], [UserId]),
    CONSTRAINT CK_customer_CustomerContactUsers_UnlinkedAtUtc CHECK (
        [UnlinkedAtUtc] IS NULL OR [UnlinkedAtUtc] >= [LinkedAtUtc]
    )
)
SQL,
    );
    echo "  + created table\n";
}

$ensureIndex($db, '[customer].[CustomerContactUsers]', 'UX_customer_CustomerContactUsers_ActivePrimary', 'CREATE UNIQUE NONCLUSTERED INDEX UX_customer_CustomerContactUsers_ActivePrimary ON [customer].[CustomerContactUsers] ([CustomerContactId]) WHERE [IsPrimary] = 1 AND [IsActive] = 1');
$ensureIndex($db, '[customer].[CustomerContactUsers]', 'IX_customer_CustomerContactUsers_UserId_IsActive', 'CREATE NONCLUSTERED INDEX IX_customer_CustomerContactUsers_UserId_IsActive ON [customer].[CustomerContactUsers] ([UserId], [IsActive])');
$ensureIndex($db, '[customer].[CustomerContactUsers]', 'IX_customer_CustomerContactUsers_ContactId_IsActive', 'CREATE NONCLUSTERED INDEX IX_customer_CustomerContactUsers_ContactId_IsActive ON [customer].[CustomerContactUsers] ([CustomerContactId], [IsActive])');

echo "Ensuring [migration].[LegacyCustomerUserMappings]...\n";
if ($tableExists($db, '[migration].[LegacyCustomerUserMappings]')) {
    echo "  = table already exists\n";
} else {
    $db->execute(
        <<<'SQL'
CREATE TABLE [migration].[LegacyCustomerUserMappings] (
    [LegacyCustomerUserMappingId] BIGINT IDENTITY(1,1) NOT NULL,
    [LegacyWordPressUserId] BIGINT NULL,
    [LegacyUserLogin] NVARCHAR(60) NULL,
    [LegacyEmailAddress] NVARCHAR(320) NULL,
    [LegacyCustomerId] INT NULL,
    [LegacyContactRowId] BIGINT NULL,
    [CustomerId] BIGINT NULL,
    [CustomerContactId] BIGINT NULL,
    [UserId] BIGINT NULL,
    [MigrationStatusCode] NVARCHAR(30) NOT NULL,
    [MigrationNotes] NVARCHAR(1000) NULL,
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyCustomerUserMappings_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyCustomerUserMappings_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [MigratedAtUtc] DATETIME2(7) NULL,
    CONSTRAINT PK_migration_LegacyCustomerUserMappings PRIMARY KEY CLUSTERED ([LegacyCustomerUserMappingId]),
    CONSTRAINT FK_migration_LegacyCustomerUserMappings_Customer FOREIGN KEY ([CustomerId]) REFERENCES [customer].[Customers] ([CustomerId]) ON DELETE NO ACTION,
    CONSTRAINT FK_migration_LegacyCustomerUserMappings_Contact FOREIGN KEY ([CustomerContactId]) REFERENCES [customer].[CustomerContacts] ([CustomerContactId]) ON DELETE NO ACTION,
    CONSTRAINT FK_migration_LegacyCustomerUserMappings_User FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
    CONSTRAINT CK_migration_LegacyCustomerUserMappings_StatusCode CHECK (
        [MigrationStatusCode] IN (
            N'pending',
            N'matched-contact',
            N'created-user',
            N'duplicate-email',
            N'missing-email',
            N'invalid-email',
            N'manual-review',
            N'skipped'
        )
    )
)
SQL,
    );
    echo "  + created table\n";
}

$ensureIndex($db, '[migration].[LegacyCustomerUserMappings]', 'IX_migration_LegacyCustomerUserMappings_LegacyWordPressUserId', 'CREATE NONCLUSTERED INDEX IX_migration_LegacyCustomerUserMappings_LegacyWordPressUserId ON [migration].[LegacyCustomerUserMappings] ([LegacyWordPressUserId])');
$ensureIndex($db, '[migration].[LegacyCustomerUserMappings]', 'IX_migration_LegacyCustomerUserMappings_LegacyEmailAddress', 'CREATE NONCLUSTERED INDEX IX_migration_LegacyCustomerUserMappings_LegacyEmailAddress ON [migration].[LegacyCustomerUserMappings] ([LegacyEmailAddress])');
$ensureIndex($db, '[migration].[LegacyCustomerUserMappings]', 'IX_migration_LegacyCustomerUserMappings_MigrationStatusCode', 'CREATE NONCLUSTERED INDEX IX_migration_LegacyCustomerUserMappings_MigrationStatusCode ON [migration].[LegacyCustomerUserMappings] ([MigrationStatusCode])');
$ensureIndex($db, '[migration].[LegacyCustomerUserMappings]', 'IX_migration_LegacyCustomerUserMappings_UserId', 'CREATE NONCLUSTERED INDEX IX_migration_LegacyCustomerUserMappings_UserId ON [migration].[LegacyCustomerUserMappings] ([UserId])');
$ensureIndex($db, '[migration].[LegacyCustomerUserMappings]', 'IX_migration_LegacyCustomerUserMappings_CustomerContactId', 'CREATE NONCLUSTERED INDEX IX_migration_LegacyCustomerUserMappings_CustomerContactId ON [migration].[LegacyCustomerUserMappings] ([CustomerContactId])');

echo "Schema ensure complete.\n";

if (!$withSeed) {
    echo "Tip: run with --seed to load development sample customers/contacts/user.\n";
    exit(0);
}

echo "Seeding development sample data...\n";
require __DIR__ . '/seed-customer-sample-data.php';
