/*
================================================================================
  Create: site.SiteConfigurations (shared runtime JSON configs)

  - Idempotent / safe to re-run
  - Seeds customer-view.theme with empty object (API merges defaults)
  - DEVELOPMENT script — review before production
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'site')
    BEGIN
        EXEC(N'CREATE SCHEMA [site]');
    END;

    IF OBJECT_ID(N'[site].[SiteConfigurations]', N'U') IS NULL
    BEGIN
        CREATE TABLE [site].[SiteConfigurations] (
            [SiteConfigurationId] BIGINT IDENTITY(1, 1) NOT NULL,
            [ConfigKey] NVARCHAR(100) NOT NULL,
            [ConfigJson] NVARCHAR(MAX) NOT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_site_SiteConfigurations_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            [RowVersion] ROWVERSION NOT NULL,
            CONSTRAINT [PK_site_SiteConfigurations] PRIMARY KEY ([SiteConfigurationId]),
            CONSTRAINT [UQ_site_SiteConfigurations_ConfigKey] UNIQUE ([ConfigKey]),
            CONSTRAINT [CK_site_SiteConfigurations_ConfigJson_NotEmpty]
                CHECK (LEN(LTRIM(RTRIM([ConfigJson]))) > 0),
            CONSTRAINT [FK_site_SiteConfigurations_UpdatedBy]
                FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId])
        );
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM [site].[SiteConfigurations]
        WHERE [ConfigKey] = N'customer-view.theme'
    )
    BEGIN
        INSERT INTO [site].[SiteConfigurations] ([ConfigKey], [ConfigJson])
        VALUES (N'customer-view.theme', N'{}');
    END;

    COMMIT TRANSACTION;
    PRINT N'site.SiteConfigurations ready.';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
