/*
================================================================================
  Additive development migration for web/mobile user activity analytics.

  Creates the [analytics] schema with four tables. Nothing existing is altered.

    [analytics].[ActivitySessions]     one row per client session (browser tab or app run): platform,
                                       current module, state, last qualifying activity. Feeds "live".
    [analytics].[DailyUserActivity]    one row per Melbourne date, platform and user. Feeds daily active
                                       users; survives session expiry.
    [analytics].[HourlyUserActivity]   one row per UTC hour, platform and user. Feeds today's hourly chart.
    [analytics].[DailyModuleActivity]  one row per Melbourne date, module, platform and user, with the
                                       number of views. Feeds module users and activity share.

  Stores the internal user id and a staff/customer flag only: no names, email addresses, IP addresses,
  user agents, tokens or URLs. Rows are removed with the user (ON DELETE CASCADE) and by the retention job
  (bin/purge-user-activity.php): sessions 30 days, hourly 14 days, daily 400 days.

  Times are UTC DATETIME2(0). Reporting dates are Melbourne calendar dates computed by the API.

  DEVELOPMENT script — review and approve before running against any shared or production database.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'analytics')
    BEGIN
        EXEC(N'CREATE SCHEMA [analytics]');
    END;

    IF OBJECT_ID(N'[analytics].[ActivitySessions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [analytics].[ActivitySessions] (
            [Id] BIGINT IDENTITY(1,1) NOT NULL,
            [SessionId] UNIQUEIDENTIFIER NOT NULL,
            [UserId] BIGINT NOT NULL,
            [Platform] VARCHAR(10) NOT NULL,
            [IsStaff] BIT NOT NULL,
            [ModuleCode] VARCHAR(40) NOT NULL,
            [State] VARCHAR(12) NOT NULL,
            [StartedAtUtc] DATETIME2(0) NOT NULL,
            [LastActivityAtUtc] DATETIME2(0) NOT NULL,
            [LastSignalAtUtc] DATETIME2(0) NOT NULL,
            [EndedAtUtc] DATETIME2(0) NULL,
            CONSTRAINT PK_analytics_ActivitySessions PRIMARY KEY CLUSTERED ([Id]),
            CONSTRAINT UQ_analytics_ActivitySessions_SessionId UNIQUE NONCLUSTERED ([SessionId]),
            CONSTRAINT FK_analytics_ActivitySessions_User FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE CASCADE,
            CONSTRAINT CK_analytics_ActivitySessions_Platform CHECK ([Platform] IN ('web', 'mobile')),
            CONSTRAINT CK_analytics_ActivitySessions_State CHECK ([State] IN ('active', 'background', 'ended'))
        );
    END;

    -- Live queries: State = 'active' AND LastActivityAtUtc >= now - 2 minutes.
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[analytics].[ActivitySessions]') AND name = N'IX_analytics_ActivitySessions_Live')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_analytics_ActivitySessions_Live
            ON [analytics].[ActivitySessions] ([State], [LastActivityAtUtc])
            INCLUDE ([UserId], [Platform], [IsStaff], [ModuleCode], [LastSignalAtUtc]);
    END;

    -- Retention.
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[analytics].[ActivitySessions]') AND name = N'IX_analytics_ActivitySessions_LastSignal')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_analytics_ActivitySessions_LastSignal
            ON [analytics].[ActivitySessions] ([LastSignalAtUtc]);
    END;

    -- Cascade deletes from identity.Users.
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[analytics].[ActivitySessions]') AND name = N'IX_analytics_ActivitySessions_UserId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_analytics_ActivitySessions_UserId
            ON [analytics].[ActivitySessions] ([UserId]);
    END;

    IF OBJECT_ID(N'[analytics].[DailyUserActivity]', N'U') IS NULL
    BEGIN
        CREATE TABLE [analytics].[DailyUserActivity] (
            [ActivityDate] DATE NOT NULL,
            [Platform] VARCHAR(10) NOT NULL,
            [UserId] BIGINT NOT NULL,
            [IsStaff] BIT NOT NULL,
            [FirstSeenAtUtc] DATETIME2(0) NOT NULL,
            CONSTRAINT PK_analytics_DailyUserActivity PRIMARY KEY CLUSTERED ([ActivityDate], [Platform], [UserId]),
            CONSTRAINT FK_analytics_DailyUserActivity_User FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE CASCADE,
            CONSTRAINT CK_analytics_DailyUserActivity_Platform CHECK ([Platform] IN ('web', 'mobile'))
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[analytics].[DailyUserActivity]') AND name = N'IX_analytics_DailyUserActivity_UserId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_analytics_DailyUserActivity_UserId
            ON [analytics].[DailyUserActivity] ([UserId]);
    END;

    IF OBJECT_ID(N'[analytics].[HourlyUserActivity]', N'U') IS NULL
    BEGIN
        CREATE TABLE [analytics].[HourlyUserActivity] (
            [HourStartUtc] DATETIME2(0) NOT NULL,
            [Platform] VARCHAR(10) NOT NULL,
            [UserId] BIGINT NOT NULL,
            [IsStaff] BIT NOT NULL,
            CONSTRAINT PK_analytics_HourlyUserActivity PRIMARY KEY CLUSTERED ([HourStartUtc], [Platform], [UserId]),
            CONSTRAINT FK_analytics_HourlyUserActivity_User FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE CASCADE,
            CONSTRAINT CK_analytics_HourlyUserActivity_Platform CHECK ([Platform] IN ('web', 'mobile'))
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[analytics].[HourlyUserActivity]') AND name = N'IX_analytics_HourlyUserActivity_UserId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_analytics_HourlyUserActivity_UserId
            ON [analytics].[HourlyUserActivity] ([UserId]);
    END;

    IF OBJECT_ID(N'[analytics].[DailyModuleActivity]', N'U') IS NULL
    BEGIN
        CREATE TABLE [analytics].[DailyModuleActivity] (
            [ActivityDate] DATE NOT NULL,
            [ModuleCode] VARCHAR(40) NOT NULL,
            [Platform] VARCHAR(10) NOT NULL,
            [UserId] BIGINT NOT NULL,
            [IsStaff] BIT NOT NULL,
            [EventCount] INT NOT NULL CONSTRAINT DF_analytics_DailyModuleActivity_EventCount DEFAULT (0),
            CONSTRAINT PK_analytics_DailyModuleActivity PRIMARY KEY CLUSTERED ([ActivityDate], [ModuleCode], [Platform], [UserId]),
            CONSTRAINT FK_analytics_DailyModuleActivity_User FOREIGN KEY ([UserId]) REFERENCES [identity].[Users] ([UserId]) ON DELETE CASCADE,
            CONSTRAINT CK_analytics_DailyModuleActivity_Platform CHECK ([Platform] IN ('web', 'mobile')),
            CONSTRAINT CK_analytics_DailyModuleActivity_EventCount CHECK ([EventCount] >= 0)
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[analytics].[DailyModuleActivity]') AND name = N'IX_analytics_DailyModuleActivity_UserId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_analytics_DailyModuleActivity_UserId
            ON [analytics].[DailyModuleActivity] ([UserId]);
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

PRINT N'user activity analytics schema created/verified.';
GO
