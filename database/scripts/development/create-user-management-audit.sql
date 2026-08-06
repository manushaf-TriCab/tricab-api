/*
================================================================================
  Additive development migration for user-management audit logging.
  Creates the [audit] schema and [audit].[UserManagementEvents] table using
  Azure SQL-compatible syntax and idempotent guards.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'audit')
    BEGIN
        EXEC(N'CREATE SCHEMA [audit]');
    END;

    IF OBJECT_ID(N'[audit].[UserManagementEvents]', N'U') IS NULL
    BEGIN
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
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[UserManagementEvents]') AND name = N'IX_audit_UserManagementEvents_CreatedAtUtc')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_audit_UserManagementEvents_CreatedAtUtc
            ON [audit].[UserManagementEvents] ([CreatedAtUtc] DESC);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[UserManagementEvents]') AND name = N'IX_audit_UserManagementEvents_ActorUserId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_audit_UserManagementEvents_ActorUserId
            ON [audit].[UserManagementEvents] ([ActorUserId]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[UserManagementEvents]') AND name = N'IX_audit_UserManagementEvents_TargetUserId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_audit_UserManagementEvents_TargetUserId
            ON [audit].[UserManagementEvents] ([TargetUserId]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[UserManagementEvents]') AND name = N'IX_audit_UserManagementEvents_EventType')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_audit_UserManagementEvents_EventType
            ON [audit].[UserManagementEvents] ([EventType]);
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;

    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();

    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
