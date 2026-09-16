<?php

declare(strict_types=1);

/**
 * Ensure TrustedDevices table exists on controlled Azure SQL.
 *
 * Refuses APP_ENV=production unless CONFIRM_TRUSTED_DEVICE_SCHEMA=1.
 * Still requires tricab-mel / tc.
 *
 * Usage (from tricab-api):
 *   php bin/ensure-trusted-device-schema.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\CoreAutoload;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';
CoreAutoload::register();

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if ($settings->isProduction() && getenv('CONFIRM_TRUSTED_DEVICE_SCHEMA') !== '1') {
    fwrite(STDERR, "STOP: APP_ENV=production requires CONFIRM_TRUSTED_DEVICE_SCHEMA=1.\n");
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

echo "Ensuring TrustedDevices schema on {$server} / {$database}...\n";
echo "Source: database/scripts/development/create-trusted-device-schema.sql\n";

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
    '[identity].[TrustedDevices]',
    <<<'SQL'
CREATE TABLE [identity].[TrustedDevices] (
    [TrustedDeviceId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_identity_TrustedDevices_PublicId DEFAULT (NEWSEQUENTIALID()),
    [UserId] BIGINT NOT NULL,
    [CredentialHash] BINARY(32) NOT NULL,
    [PreviousCredentialHash] BINARY(32) NULL,
    [PreviousCredentialValidUntilUtc] DATETIME2(7) NULL,
    [InstallationId] UNIQUEIDENTIFIER NOT NULL,
    [Platform] NVARCHAR(20) NOT NULL,
    [DeviceName] NVARCHAR(120) NULL,
    [CredentialVersion] INT NOT NULL CONSTRAINT DF_identity_TrustedDevices_CredentialVersion DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_identity_TrustedDevices_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [LastUsedAtUtc] DATETIME2(7) NULL,
    [ExpiresAtUtc] DATETIME2(7) NOT NULL,
    [RevokedAtUtc] DATETIME2(7) NULL,
    [CreatedIp] NVARCHAR(45) NULL,
    [LastUsedIp] NVARCHAR(45) NULL,
    CONSTRAINT PK_identity_TrustedDevices PRIMARY KEY CLUSTERED ([TrustedDeviceId]),
    CONSTRAINT UQ_identity_TrustedDevices_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_identity_TrustedDevices_CredentialHash UNIQUE ([CredentialHash]),
    CONSTRAINT FK_identity_TrustedDevices_Users FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
    CONSTRAINT CK_identity_TrustedDevices_Platform CHECK ([Platform] IN (N'ios', N'android')),
    CONSTRAINT CK_identity_TrustedDevices_CreatedBeforeExpires CHECK ([ExpiresAtUtc] > [CreatedAtUtc]),
    CONSTRAINT CK_identity_TrustedDevices_RevokedAfterCreated CHECK ([RevokedAtUtc] IS NULL OR [RevokedAtUtc] >= [CreatedAtUtc]),
    CONSTRAINT CK_identity_TrustedDevices_CredentialVersion CHECK ([CredentialVersion] >= 1)
)
SQL,
    $applied,
);

$indexes = [
    'IX_identity_TrustedDevices_UserId_ExpiresAtUtc' =>
        'CREATE NONCLUSTERED INDEX IX_identity_TrustedDevices_UserId_ExpiresAtUtc ON [identity].[TrustedDevices] ([UserId], [ExpiresAtUtc]) WHERE [RevokedAtUtc] IS NULL',
    'IX_identity_TrustedDevices_CredentialHash' =>
        'CREATE NONCLUSTERED INDEX IX_identity_TrustedDevices_CredentialHash ON [identity].[TrustedDevices] ([CredentialHash])',
    'IX_identity_TrustedDevices_InstallationId_Active' =>
        'CREATE NONCLUSTERED INDEX IX_identity_TrustedDevices_InstallationId_Active ON [identity].[TrustedDevices] ([InstallationId], [UserId]) WHERE [RevokedAtUtc] IS NULL',
];

foreach ($indexes as $name => $ddl) {
    $indexRows = $db->fetchAll(
        "SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[identity].[TrustedDevices]') AND name = N'{$name}') THEN 1 ELSE 0 END AS [IndexExists]",
    );
    if ((int) ($indexRows[0]['IndexExists'] ?? 0) !== 1) {
        $db->execute($ddl);
        $applied[] = "created index {$name}";
        echo "  + index {$name}\n";
    } else {
        echo "  = index {$name}\n";
    }
}

echo $applied === []
    ? "Applied: nothing (schema already present)\n"
    : "Applied:\n  - " . implode("\n  - ", $applied) . "\n";
echo "Done.\n";
