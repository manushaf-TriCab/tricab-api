<?php

declare(strict_types=1);

/**
 * Development helper: ensure Guest Passkey tables exist on non-production Azure SQL.
 *
 * Applies only database/scripts/development/create-passkey-schema.sql.
 * Refuses APP_ENV=production and any target other than tricab-mel / tc.
 *
 * Usage (from tricab-api):
 *   php bin/ensure-passkey-schema.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if ($settings->isProduction()) {
    fwrite(STDERR, "STOP: APP_ENV=production. Passkey schema apply is not allowed.\n");
    exit(4);
}

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDOUT, "USER_REPOSITORY is not azure-sql — nothing to migrate.\n");
    exit(0);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

$envRows = $db->fetchAll('SELECT @@SERVERNAME AS [serverName], DB_NAME() AS [dbName]');
$server = (string) ($envRows[0]['serverName'] ?? $settings->dbHost);
$database = (string) ($envRows[0]['dbName'] ?? $settings->dbDatabase);
if (!str_contains(strtolower($server), 'tricab-mel') || strtolower($database) !== 'tc') {
    fwrite(STDERR, "STOP: target is {$server} / {$database}; expected tricab-mel / tc\n");
    exit(4);
}

echo "Ensuring Guest Passkey schema on {$server} / {$database}...\n";
echo "Source: database/scripts/development/create-passkey-schema.sql\n";

$applied = [];

$schemaRows = $db->fetchAll(
    "SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'identity') THEN 1 ELSE 0 END AS [SchemaExists]",
);
if ((int) ($schemaRows[0]['SchemaExists'] ?? 0) !== 1) {
    $db->execute('CREATE SCHEMA [identity]');
    $applied[] = 'created schema [identity]';
    echo "  + created schema [identity]\n";
} else {
    echo "  = schema [identity] already exists\n";
}

/**
 * @param list<string> $applied
 */
$ensureTable = static function (
    SqlConnection $db,
    string $table,
    string $ddl,
    array &$applied,
): void {
    $rows = $db->fetchAll(
        "SELECT CASE WHEN OBJECT_ID(N'{$table}', N'U') IS NULL THEN 0 ELSE 1 END AS [TableExists]",
    );
    if ((int) ($rows[0]['TableExists'] ?? 0) === 1) {
        echo "  = {$table} already exists\n";
        return;
    }
    $db->execute($ddl);
    $applied[] = "created {$table}";
    echo "  + created {$table}\n";
};

$ensureTable(
    $db,
    '[identity].[UserWebAuthnHandles]',
    <<<'SQL'
CREATE TABLE [identity].[UserWebAuthnHandles] (
    [UserId] BIGINT NOT NULL,
    [UserHandle] VARBINARY(32) NOT NULL,
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_UserWebAuthnHandles_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT PK_identity_UserWebAuthnHandles PRIMARY KEY CLUSTERED ([UserId]),
    CONSTRAINT UQ_identity_UserWebAuthnHandles_UserHandle UNIQUE ([UserHandle]),
    CONSTRAINT FK_identity_UserWebAuthnHandles_User FOREIGN KEY ([UserId])
        REFERENCES [identity].[Users] ([UserId])
)
SQL,
    $applied,
);

$ensureTable(
    $db,
    '[identity].[UserPasskeys]',
    <<<'SQL'
CREATE TABLE [identity].[UserPasskeys] (
    [UserPasskeyId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_UserPasskeys_PublicId DEFAULT (NEWSEQUENTIALID()),
    [UserId] BIGINT NOT NULL,
    [CredentialId] NVARCHAR(1024) NOT NULL,
    [PublicKey] NVARCHAR(MAX) NOT NULL,
    [SignCount] BIGINT NOT NULL CONSTRAINT DF_identity_UserPasskeys_SignCount DEFAULT (0),
    [Transports] NVARCHAR(200) NULL,
    [Aaguid] NVARCHAR(64) NULL,
    [DeviceLabel] NVARCHAR(120) NOT NULL CONSTRAINT DF_identity_UserPasskeys_DeviceLabel DEFAULT (N'Passkey'),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_UserPasskeys_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [LastUsedAtUtc] DATETIME2(7) NULL,
    [IsActive] BIT NOT NULL CONSTRAINT DF_identity_UserPasskeys_IsActive DEFAULT (1),
    CONSTRAINT PK_identity_UserPasskeys PRIMARY KEY CLUSTERED ([UserPasskeyId]),
    CONSTRAINT UQ_identity_UserPasskeys_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_identity_UserPasskeys_CredentialId UNIQUE ([CredentialId]),
    CONSTRAINT FK_identity_UserPasskeys_User FOREIGN KEY ([UserId])
        REFERENCES [identity].[Users] ([UserId])
)
SQL,
    $applied,
);

$indexRows = $db->fetchAll(
    "SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[identity].[UserPasskeys]') AND name = N'IX_identity_UserPasskeys_UserId') THEN 1 ELSE 0 END AS [IndexExists]",
);
if ((int) ($indexRows[0]['IndexExists'] ?? 0) !== 1) {
    $db->execute('CREATE INDEX IX_identity_UserPasskeys_UserId ON [identity].[UserPasskeys] ([UserId]) WHERE [IsActive] = 1');
    $applied[] = 'created index IX_identity_UserPasskeys_UserId';
    echo "  + index IX_identity_UserPasskeys_UserId\n";
} else {
    echo "  = index IX_identity_UserPasskeys_UserId\n";
}

$ensureTable(
    $db,
    '[identity].[GuestPasskeySetupTokens]',
    <<<'SQL'
CREATE TABLE [identity].[GuestPasskeySetupTokens] (
    [GuestPasskeySetupTokenId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_GuestPasskeySetupTokens_PublicId DEFAULT (NEWSEQUENTIALID()),
    [UserId] BIGINT NOT NULL,
    [TokenHash] VARBINARY(32) NOT NULL,
    [ExpiresAtUtc] DATETIME2(7) NOT NULL,
    [ConsumedAtUtc] DATETIME2(7) NULL,
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_GuestPasskeySetupTokens_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [RequestedIpHash] VARBINARY(32) NULL,
    CONSTRAINT PK_identity_GuestPasskeySetupTokens PRIMARY KEY CLUSTERED ([GuestPasskeySetupTokenId]),
    CONSTRAINT UQ_identity_GuestPasskeySetupTokens_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_identity_GuestPasskeySetupTokens_TokenHash UNIQUE ([TokenHash]),
    CONSTRAINT FK_identity_GuestPasskeySetupTokens_User FOREIGN KEY ([UserId])
        REFERENCES [identity].[Users] ([UserId])
)
SQL,
    $applied,
);

$tokenIndexRows = $db->fetchAll(
    "SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[identity].[GuestPasskeySetupTokens]') AND name = N'IX_identity_GuestPasskeySetupTokens_UserId') THEN 1 ELSE 0 END AS [IndexExists]",
);
if ((int) ($tokenIndexRows[0]['IndexExists'] ?? 0) !== 1) {
    $db->execute('CREATE INDEX IX_identity_GuestPasskeySetupTokens_UserId ON [identity].[GuestPasskeySetupTokens] ([UserId])');
    $applied[] = 'created index IX_identity_GuestPasskeySetupTokens_UserId';
    echo "  + index IX_identity_GuestPasskeySetupTokens_UserId\n";
} else {
    echo "  = index IX_identity_GuestPasskeySetupTokens_UserId\n";
}

$ensureTable(
    $db,
    '[identity].[PasskeyChallenges]',
    <<<'SQL'
CREATE TABLE [identity].[PasskeyChallenges] (
    [PasskeyChallengeId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_PasskeyChallenges_PublicId DEFAULT (NEWSEQUENTIALID()),
    [UserId] BIGINT NULL,
    [RegistrationSessionPublicId] UNIQUEIDENTIFIER NULL,
    [PurposeCode] NVARCHAR(40) NOT NULL,
    [Challenge] VARBINARY(64) NOT NULL,
    [ExpiresAtUtc] DATETIME2(7) NOT NULL,
    [ConsumedAtUtc] DATETIME2(7) NULL,
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_PasskeyChallenges_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT PK_identity_PasskeyChallenges PRIMARY KEY CLUSTERED ([PasskeyChallengeId]),
    CONSTRAINT UQ_identity_PasskeyChallenges_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_identity_PasskeyChallenges_Purpose CHECK ([PurposeCode] IN (N'registration', N'authentication'))
)
SQL,
    $applied,
);

$challengeIndexRows = $db->fetchAll(
    "SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[identity].[PasskeyChallenges]') AND name = N'IX_identity_PasskeyChallenges_Session') THEN 1 ELSE 0 END AS [IndexExists]",
);
if ((int) ($challengeIndexRows[0]['IndexExists'] ?? 0) !== 1) {
    $db->execute('CREATE INDEX IX_identity_PasskeyChallenges_Session ON [identity].[PasskeyChallenges] ([RegistrationSessionPublicId])');
    $applied[] = 'created index IX_identity_PasskeyChallenges_Session';
    echo "  + index IX_identity_PasskeyChallenges_Session\n";
} else {
    echo "  = index IX_identity_PasskeyChallenges_Session\n";
}

$ensureTable(
    $db,
    '[identity].[PasskeyRegistrationSessions]',
    <<<'SQL'
CREATE TABLE [identity].[PasskeyRegistrationSessions] (
    [PasskeyRegistrationSessionId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_PasskeyRegistrationSessions_PublicId DEFAULT (NEWSEQUENTIALID()),
    [UserId] BIGINT NOT NULL,
    [SetupTokenPublicId] UNIQUEIDENTIFIER NOT NULL,
    [SessionTokenHash] VARBINARY(32) NOT NULL,
    [ExpiresAtUtc] DATETIME2(7) NOT NULL,
    [ConsumedAtUtc] DATETIME2(7) NULL,
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_PasskeyRegistrationSessions_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT PK_identity_PasskeyRegistrationSessions PRIMARY KEY CLUSTERED ([PasskeyRegistrationSessionId]),
    CONSTRAINT UQ_identity_PasskeyRegistrationSessions_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_identity_PasskeyRegistrationSessions_SessionTokenHash UNIQUE ([SessionTokenHash]),
    CONSTRAINT FK_identity_PasskeyRegistrationSessions_User FOREIGN KEY ([UserId])
        REFERENCES [identity].[Users] ([UserId])
)
SQL,
    $applied,
);

echo $applied === []
    ? "Applied: nothing (schema already present)\n"
    : "Applied:\n  - " . implode("\n  - ", $applied) . "\n";
echo "Done.\n";
