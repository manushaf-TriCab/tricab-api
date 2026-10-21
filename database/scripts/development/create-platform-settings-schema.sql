SET NOCOUNT ON;
SET XACT_ABORT ON;
BEGIN TRANSACTION;
IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'site') EXEC(N'CREATE SCHEMA [site]');
IF OBJECT_ID(N'[site].[PlatformSettings]', N'U') IS NULL
CREATE TABLE [site].[PlatformSettings] (
    [Environment] NVARCHAR(40) NOT NULL CONSTRAINT [PK_site_PlatformSettings] PRIMARY KEY,
    [Version] BIGINT NOT NULL,
    [OverridesJson] NVARCHAR(MAX) NOT NULL,
    [DefaultsJson] NVARCHAR(MAX) NOT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_site_PlatformSettings_UpdatedAtUtc] DEFAULT SYSUTCDATETIME(),
    [UpdatedByUserId] BIGINT NOT NULL,
    CONSTRAINT [CK_site_PlatformSettings_DefaultsJson] CHECK (ISJSON([DefaultsJson]) = 1),
    CONSTRAINT [CK_site_PlatformSettings_Json] CHECK (ISJSON([OverridesJson]) = 1),
    CONSTRAINT [FK_site_PlatformSettings_User] FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users]([UserId])
);
IF OBJECT_ID(N'[site].[PlatformSettingsHistory]', N'U') IS NULL
CREATE TABLE [site].[PlatformSettingsHistory] (
    [Environment] NVARCHAR(40) NOT NULL,
    [Version] BIGINT NOT NULL,
    [ChangedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT [DF_site_PlatformSettingsHistory_ChangedAtUtc] DEFAULT SYSUTCDATETIME(),
    [ActorUserId] BIGINT NOT NULL,
    [Operation] NVARCHAR(20) NOT NULL,
    [OverridesJson] NVARCHAR(MAX) NOT NULL,
    CONSTRAINT [PK_site_PlatformSettingsHistory] PRIMARY KEY ([Environment], [Version]),
    CONSTRAINT [CK_site_PlatformSettingsHistory_Json] CHECK (ISJSON([OverridesJson]) = 1),
    CONSTRAINT [FK_site_PlatformSettingsHistory_User] FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users]([UserId])
);
COMMIT TRANSACTION;
