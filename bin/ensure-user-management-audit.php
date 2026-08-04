<?php

declare(strict_types=1);

/**
 * Development helper: ensure [audit].[UserManagementEvents] exists on Azure SQL.
 *
 * Usage (from tricab-api):
 *   php bin/ensure-user-management-audit.php
 */

use TriCab\Api\Bootstrap\ContainerFactory;
use TriCab\Api\Bootstrap\Environment;
use TriCab\Api\Infrastructure\Database\SqlConnection;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
$settings = Environment::settings($basePath);

if (!$settings->usesAzureSqlUserRepository()) {
    fwrite(STDOUT, "USER_REPOSITORY is not azure-sql — nothing to migrate.\n");
    exit(0);
}

$container = ContainerFactory::create($settings);
/** @var SqlConnection $db */
$db = $container->get(SqlConnection::class);

echo "Ensuring [audit].[UserManagementEvents] exists...\n";

$existsRows = $db->fetchAll(
    "SELECT CASE WHEN OBJECT_ID(N'[audit].[UserManagementEvents]', N'U') IS NULL THEN 0 ELSE 1 END AS [TableExists]",
);
$exists = (int) ($existsRows[0]['TableExists'] ?? 0) === 1;

if ($exists) {
    echo "  = table already exists\n";
} else {
    $schemaRows = $db->fetchAll(
        "SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'audit') THEN 1 ELSE 0 END AS [SchemaExists]",
    );
    if ((int) ($schemaRows[0]['SchemaExists'] ?? 0) !== 1) {
        $db->execute('CREATE SCHEMA [audit]');
        echo "  + created schema [audit]\n";
    } else {
        echo "  = schema [audit] already exists\n";
    }

    $db->execute(
        <<<'SQL'
CREATE TABLE [audit].[UserManagementEvents] (
    [UserManagementEventId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_audit_UserManagementEvents_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ActorUserId] BIGINT NOT NULL,
    [TargetUserId] BIGINT NULL,
    [TargetRoleId] BIGINT NULL,
    [TargetPermissionId] BIGINT NULL,
    [EventType] NVARCHAR(80) NOT NULL,
    [EventSummary] NVARCHAR(500) NOT NULL,
    [PreviousValuesJson] NVARCHAR(MAX) NULL,
    [NewValuesJson] NVARCHAR(MAX) NULL,
    [RequestCorrelationId] NVARCHAR(100) NULL,
    [IpAddress] NVARCHAR(64) NULL,
    [UserAgent] NVARCHAR(500) NULL,
    [CreatedAtUtc] DATETIMEOFFSET NOT NULL CONSTRAINT DF_audit_UserManagementEvents_CreatedAtUtc DEFAULT (SYSDATETIMEOFFSET()),
    CONSTRAINT PK_audit_UserManagementEvents PRIMARY KEY CLUSTERED ([UserManagementEventId]),
    CONSTRAINT UQ_audit_UserManagementEvents_PublicId UNIQUE ([PublicId]),
    CONSTRAINT FK_audit_UserManagementEvents_ActorUser FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
    CONSTRAINT FK_audit_UserManagementEvents_TargetUser FOREIGN KEY ([TargetUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
    CONSTRAINT FK_audit_UserManagementEvents_TargetRole FOREIGN KEY ([TargetRoleId]) REFERENCES [identity].[Roles] ([RoleId]) ON DELETE NO ACTION,
    CONSTRAINT FK_audit_UserManagementEvents_TargetPermission FOREIGN KEY ([TargetPermissionId]) REFERENCES [identity].[Permissions] ([PermissionId]) ON DELETE NO ACTION,
    CONSTRAINT CK_audit_UserManagementEvents_EventType_NotEmpty CHECK (LEN(LTRIM(RTRIM([EventType]))) > 0),
    CONSTRAINT CK_audit_UserManagementEvents_EventSummary_NotEmpty CHECK (LEN(LTRIM(RTRIM([EventSummary]))) > 0)
)
SQL,
    );
    echo "  + created table [audit].[UserManagementEvents]\n";
}

$indexes = [
    'IX_audit_UserManagementEvents_CreatedAtUtc' => 'CREATE NONCLUSTERED INDEX IX_audit_UserManagementEvents_CreatedAtUtc ON [audit].[UserManagementEvents] ([CreatedAtUtc] DESC)',
    'IX_audit_UserManagementEvents_ActorUserId' => 'CREATE NONCLUSTERED INDEX IX_audit_UserManagementEvents_ActorUserId ON [audit].[UserManagementEvents] ([ActorUserId])',
    'IX_audit_UserManagementEvents_TargetUserId' => 'CREATE NONCLUSTERED INDEX IX_audit_UserManagementEvents_TargetUserId ON [audit].[UserManagementEvents] ([TargetUserId])',
    'IX_audit_UserManagementEvents_EventType' => 'CREATE NONCLUSTERED INDEX IX_audit_UserManagementEvents_EventType ON [audit].[UserManagementEvents] ([EventType])',
];

foreach ($indexes as $name => $ddl) {
    $indexRows = $db->fetchAll(
        'SELECT CASE WHEN EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N\'[audit].[UserManagementEvents]\') AND name = :name) THEN 1 ELSE 0 END AS [IndexExists]',
        [':name' => $name],
    );
    if ((int) ($indexRows[0]['IndexExists'] ?? 0) === 1) {
        echo "  = index {$name}\n";
        continue;
    }
    $db->execute($ddl);
    echo "  + index {$name}\n";
}

$countRows = $db->fetchAll('SELECT COUNT(*) AS [Total] FROM [audit].[UserManagementEvents]');
echo '  rows=' . (int) ($countRows[0]['Total'] ?? 0) . "\n";
echo "Done.\n";
