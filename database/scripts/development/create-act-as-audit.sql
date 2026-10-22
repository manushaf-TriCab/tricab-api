/*
================================================================================
  Additive development migration for the "act as user" audit trail.
  Creates [audit].[ActAsSessions] (one row per impersonation session) and
  [audit].[ActAsActions] (one row per request made while impersonating) using
  Azure SQL-compatible syntax and idempotent guards.

  Shaped on [audit].[UserManagementEvents]: user ids are BIGINT and foreign
  keys point at [identity].[Users]([UserId]) with ON DELETE NO ACTION; times
  are DATETIMEOFFSET, defaulting to Melbourne time (AEST/AEDT offset). [EndedAt] is NULL while a session is active.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'audit')
    BEGIN
        EXEC(N'CREATE SCHEMA [audit]');
    END;

    IF OBJECT_ID(N'[audit].[ActAsSessions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [audit].[ActAsSessions] (
            [Id] UNIQUEIDENTIFIER NOT NULL,
            [AdminUserId] BIGINT NOT NULL,
            [TargetUserId] BIGINT NOT NULL,
            [Reason] NVARCHAR(500) NOT NULL,
            [StartedAt] DATETIMEOFFSET NOT NULL CONSTRAINT DF_audit_ActAsSessions_StartedAt DEFAULT (SYSDATETIMEOFFSET() AT TIME ZONE N'AUS Eastern Standard Time'),
            [ExpiresAt] DATETIMEOFFSET NOT NULL,
            [EndedAt] DATETIMEOFFSET NULL,
            [EndReason] NVARCHAR(20) NULL,
            [ClientIp] NVARCHAR(64) NULL,
            [UserAgent] NVARCHAR(512) NULL,
            CONSTRAINT PK_audit_ActAsSessions PRIMARY KEY CLUSTERED ([Id]),
            CONSTRAINT FK_audit_ActAsSessions_AdminUser FOREIGN KEY ([AdminUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT FK_audit_ActAsSessions_TargetUser FOREIGN KEY ([TargetUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT CK_audit_ActAsSessions_EndReason CHECK ([EndReason] IS NULL OR [EndReason] IN (N'exit', N'expired', N'revoked'))
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[ActAsSessions]') AND name = N'IX_audit_ActAsSessions_AdminUserId_StartedAt')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_audit_ActAsSessions_AdminUserId_StartedAt
            ON [audit].[ActAsSessions] ([AdminUserId], [StartedAt] DESC);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[ActAsSessions]') AND name = N'IX_audit_ActAsSessions_TargetUserId_StartedAt')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_audit_ActAsSessions_TargetUserId_StartedAt
            ON [audit].[ActAsSessions] ([TargetUserId], [StartedAt] DESC);
    END;

    IF OBJECT_ID(N'[audit].[ActAsActions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [audit].[ActAsActions] (
            [Id] BIGINT IDENTITY(1,1) NOT NULL,
            [SessionId] UNIQUEIDENTIFIER NOT NULL,
            [AdminUserId] BIGINT NOT NULL,
            [TargetUserId] BIGINT NOT NULL,
            [Method] VARCHAR(10) NOT NULL,
            [RoutePattern] NVARCHAR(400) NULL,
            [Path] NVARCHAR(2000) NOT NULL,
            [StatusCode] INT NOT NULL,
            [DurationMs] INT NOT NULL,
            [RequestId] NVARCHAR(100) NULL,
            [OccurredAt] DATETIMEOFFSET NOT NULL CONSTRAINT DF_audit_ActAsActions_OccurredAt DEFAULT (SYSDATETIMEOFFSET() AT TIME ZONE N'AUS Eastern Standard Time'),
            CONSTRAINT PK_audit_ActAsActions PRIMARY KEY CLUSTERED ([Id]),
            CONSTRAINT FK_audit_ActAsActions_Session FOREIGN KEY ([SessionId]) REFERENCES [audit].[ActAsSessions] ([Id]) ON DELETE NO ACTION,
            CONSTRAINT FK_audit_ActAsActions_AdminUser FOREIGN KEY ([AdminUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION,
            CONSTRAINT FK_audit_ActAsActions_TargetUser FOREIGN KEY ([TargetUserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE NO ACTION
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[ActAsActions]') AND name = N'IX_audit_ActAsActions_SessionId_OccurredAt')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_audit_ActAsActions_SessionId_OccurredAt
            ON [audit].[ActAsActions] ([SessionId], [OccurredAt]);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[audit].[ActAsActions]') AND name = N'IX_audit_ActAsActions_AdminUserId_OccurredAt')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_audit_ActAsActions_AdminUserId_OccurredAt
            ON [audit].[ActAsActions] ([AdminUserId], [OccurredAt]);
    END;

    -- Re-point the time defaults at Melbourne time on tables created before
    -- they used AT TIME ZONE.
    DECLARE @StaleDefault SYSNAME;

    SELECT @StaleDefault = dc.name
    FROM sys.default_constraints dc
    WHERE dc.parent_object_id = OBJECT_ID(N'[audit].[ActAsSessions]')
      AND dc.name = N'DF_audit_ActAsSessions_StartedAt'
      AND dc.definition NOT LIKE N'%AT TIME ZONE%';

    IF @StaleDefault IS NOT NULL
    BEGIN
        ALTER TABLE [audit].[ActAsSessions] DROP CONSTRAINT DF_audit_ActAsSessions_StartedAt;
        ALTER TABLE [audit].[ActAsSessions] ADD CONSTRAINT DF_audit_ActAsSessions_StartedAt
            DEFAULT (SYSDATETIMEOFFSET() AT TIME ZONE N'AUS Eastern Standard Time') FOR [StartedAt];
    END;

    SET @StaleDefault = NULL;

    SELECT @StaleDefault = dc.name
    FROM sys.default_constraints dc
    WHERE dc.parent_object_id = OBJECT_ID(N'[audit].[ActAsActions]')
      AND dc.name = N'DF_audit_ActAsActions_OccurredAt'
      AND dc.definition NOT LIKE N'%AT TIME ZONE%';

    IF @StaleDefault IS NOT NULL
    BEGIN
        ALTER TABLE [audit].[ActAsActions] DROP CONSTRAINT DF_audit_ActAsActions_OccurredAt;
        ALTER TABLE [audit].[ActAsActions] ADD CONSTRAINT DF_audit_ActAsActions_OccurredAt
            DEFAULT (SYSDATETIMEOFFSET() AT TIME ZONE N'AUS Eastern Standard Time') FOR [OccurredAt];
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
GO

PRINT N'act-as audit created/verified.';
GO
