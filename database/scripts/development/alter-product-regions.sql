/*
================================================================================
  Region management additive migration and development seed.

  Reuses [product].[Regions] and preserves all RegionIds and mappings.
  Idempotent and safe to re-run on Azure SQL.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[product].[Regions]', N'U') IS NULL
        THROW 50001, '[product].[Regions] must exist before this migration runs.', 1;

    IF COL_LENGTH(N'product.Regions', N'DisplayName') IS NULL
        ALTER TABLE [product].[Regions] ADD [DisplayName] NVARCHAR(150) NULL;
    IF COL_LENGTH(N'product.Regions', N'Flag') IS NULL
        ALTER TABLE [product].[Regions] ADD [Flag] NVARCHAR(20) NULL;
    IF COL_LENGTH(N'product.Regions', N'ThemeColour') IS NULL
        ALTER TABLE [product].[Regions] ADD [ThemeColour] NVARCHAR(20) NULL;
    IF COL_LENGTH(N'product.Regions', N'IsDefault') IS NULL
        ALTER TABLE [product].[Regions] ADD [IsDefault] BIT NOT NULL
            CONSTRAINT [DF_product_Regions_IsDefault] DEFAULT (0) WITH VALUES;
    IF COL_LENGTH(N'product.Regions', N'WebsiteEnabled') IS NULL
        ALTER TABLE [product].[Regions] ADD [WebsiteEnabled] BIT NOT NULL
            CONSTRAINT [DF_product_Regions_WebsiteEnabled] DEFAULT (0) WITH VALUES;
    IF COL_LENGTH(N'product.Regions', N'ShowInRegionPicker') IS NULL
        ALTER TABLE [product].[Regions] ADD [ShowInRegionPicker] BIT NOT NULL
            CONSTRAINT [DF_product_Regions_ShowInRegionPicker] DEFAULT (0) WITH VALUES;
    IF COL_LENGTH(N'product.Regions', N'IsDefaultWebsiteRegion') IS NULL
        ALTER TABLE [product].[Regions] ADD [IsDefaultWebsiteRegion] BIT NOT NULL
            CONSTRAINT [DF_product_Regions_IsDefaultWebsiteRegion] DEFAULT (0) WITH VALUES;
    IF COL_LENGTH(N'product.Regions', N'CurrencyCode') IS NULL
        ALTER TABLE [product].[Regions] ADD [CurrencyCode] NVARCHAR(3) NULL;
    IF COL_LENGTH(N'product.Regions', N'TimezoneId') IS NULL
        ALTER TABLE [product].[Regions] ADD [TimezoneId] NVARCHAR(100) NULL;
    IF COL_LENGTH(N'product.Regions', N'LanguageCode') IS NULL
        ALTER TABLE [product].[Regions] ADD [LanguageCode] NVARCHAR(10) NULL;
    IF COL_LENGTH(N'product.Regions', N'Office') IS NULL
        ALTER TABLE [product].[Regions] ADD [Office] NVARCHAR(500) NULL;
    IF COL_LENGTH(N'product.Regions', N'Phone') IS NULL
        ALTER TABLE [product].[Regions] ADD [Phone] NVARCHAR(50) NULL;
    IF COL_LENGTH(N'product.Regions', N'Email') IS NULL
        ALTER TABLE [product].[Regions] ADD [Email] NVARCHAR(254) NULL;
    IF COL_LENGTH(N'product.Regions', N'SalesContact') IS NULL
        ALTER TABLE [product].[Regions] ADD [SalesContact] NVARCHAR(200) NULL;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

/* A new batch is required so Azure SQL compiles references to added columns. */
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    UPDATE [product].[Regions]
    SET [DisplayName] = [RegionName]
    WHERE [DisplayName] IS NULL OR LTRIM(RTRIM([DisplayName])) = N'';

    MERGE [product].[Regions] AS target
    USING (VALUES
        (N'AU', N'Australia', N'Australia', N'🇦🇺', N'AU', 10, N'AUD', N'Australia/Sydney', N'en-AU'),
        (N'NZ', N'New Zealand', N'New Zealand', N'🇳🇿', N'NZ', 20, N'NZD', N'Pacific/Auckland', N'en-NZ'),
        (N'US', N'United States', N'United States', N'🇺🇸', N'US', 30, N'USD', N'America/New_York', N'en-US'),
        (N'AS', N'Asia', N'Asia', N'🌏', NULL, 40, NULL, NULL, NULL)
    ) AS source (
        [RegionCode], [RegionName], [DisplayName], [Flag], [CountryCodes],
        [DisplayOrder], [CurrencyCode], [TimezoneId], [LanguageCode]
    )
    ON target.[RegionCode] = source.[RegionCode]
    WHEN MATCHED THEN UPDATE SET
        target.[DisplayName] = source.[DisplayName],
        target.[Flag] = source.[Flag],
        target.[CountryCodes] = source.[CountryCodes],
        target.[CurrencyCode] = source.[CurrencyCode],
        target.[TimezoneId] = source.[TimezoneId],
        target.[LanguageCode] = source.[LanguageCode],
        target.[WebsiteEnabled] = 1,
        target.[ShowInRegionPicker] = 1,
        target.[UpdatedAtUtc] = SYSUTCDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (
            [RegionCode], [RegionName], [DisplayName], [Flag], [CountryCodes],
            [DisplayOrder], [IsActive], [WebsiteEnabled], [ShowInRegionPicker],
            [CurrencyCode], [TimezoneId], [LanguageCode]
        )
        VALUES (
            source.[RegionCode], source.[RegionName], source.[DisplayName], source.[Flag], source.[CountryCodes],
            source.[DisplayOrder], 1, 1, 1,
            source.[CurrencyCode], source.[TimezoneId], source.[LanguageCode]
        );

    UPDATE [product].[Regions]
    SET [WebsiteEnabled] = 0,
        [ShowInRegionPicker] = 0,
        [IsDefault] = 0,
        [IsDefaultWebsiteRegion] = 0
    WHERE [RegionCode] IN (N'APAC', N'EMEA', N'AMER');

    IF NOT EXISTS (
        SELECT 1 FROM [product].[Regions]
        WHERE [IsDefault] = 1 AND [DeletedAtUtc] IS NULL
    )
        UPDATE [product].[Regions]
        SET [IsDefault] = 1, [IsActive] = 1, [WebsiteEnabled] = 1, [ShowInRegionPicker] = 1
        WHERE [RegionCode] = N'AU' AND [DeletedAtUtc] IS NULL;

    IF NOT EXISTS (
        SELECT 1 FROM [product].[Regions]
        WHERE [IsDefaultWebsiteRegion] = 1 AND [DeletedAtUtc] IS NULL
    )
        UPDATE [product].[Regions]
        SET [IsDefaultWebsiteRegion] = 1, [IsActive] = 1, [WebsiteEnabled] = 1, [ShowInRegionPicker] = 1
        WHERE [RegionCode] = N'AU' AND [DeletedAtUtc] IS NULL;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE [object_id] = OBJECT_ID(N'[product].[Regions]')
          AND [name] = N'UX_product_Regions_OneDefault'
    )
        CREATE UNIQUE NONCLUSTERED INDEX [UX_product_Regions_OneDefault]
            ON [product].[Regions] ([IsDefault])
            WHERE [IsDefault] = 1 AND [DeletedAtUtc] IS NULL;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE [object_id] = OBJECT_ID(N'[product].[Regions]')
          AND [name] = N'UX_product_Regions_OneDefaultWebsite'
    )
        CREATE UNIQUE NONCLUSTERED INDEX [UX_product_Regions_OneDefaultWebsite]
            ON [product].[Regions] ([IsDefaultWebsiteRegion])
            WHERE [IsDefaultWebsiteRegion] = 1 AND [DeletedAtUtc] IS NULL;

    DECLARE @RegionPermissions TABLE (
        [PermissionCode] NVARCHAR(150) NOT NULL PRIMARY KEY,
        [PermissionName] NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(500) NOT NULL
    );
    INSERT INTO @RegionPermissions ([PermissionCode], [PermissionName], [Description])
    VALUES
        (N'REGION.READ', N'Read regions', N'View public and administrative region records.'),
        (N'REGION.CREATE', N'Create regions', N'Create region records.'),
        (N'REGION.UPDATE', N'Update regions', N'Update and activate or deactivate region records.'),
        (N'REGION.DELETE', N'Delete regions', N'Delete unused region records.');

    MERGE [identity].[Permissions] AS target
    USING @RegionPermissions AS source
    ON UPPER(target.[PermissionCode]) = source.[PermissionCode]
    WHEN MATCHED THEN UPDATE SET
        target.[PermissionName] = source.[PermissionName],
        target.[Description] = source.[Description],
        target.[ModuleCode] = N'region',
        target.[IsActive] = 1
    WHEN NOT MATCHED THEN
        INSERT ([PermissionCode], [PermissionName], [Description], [ModuleCode], [IsActive])
        VALUES (source.[PermissionCode], source.[PermissionName], source.[Description], N'region', 1);

    DECLARE @AdministratorRoleId BIGINT = (
        SELECT TOP (1) [RoleId]
        FROM [identity].[Roles]
        WHERE UPPER([RoleCode]) = N'ADMINISTRATOR'
        ORDER BY [RoleId]
    );

    IF @AdministratorRoleId IS NOT NULL
    BEGIN
        MERGE [identity].[RolePermissions] AS target
        USING (
            SELECT @AdministratorRoleId AS [RoleId], p.[PermissionId]
            FROM [identity].[Permissions] p
            INNER JOIN @RegionPermissions rp
                ON UPPER(p.[PermissionCode]) = rp.[PermissionCode]
        ) AS source
        ON target.[RoleId] = source.[RoleId] AND target.[PermissionId] = source.[PermissionId]
        WHEN MATCHED THEN UPDATE SET target.[IsActive] = 1
        WHEN NOT MATCHED THEN
            INSERT ([RoleId], [PermissionId], [IsActive])
            VALUES (source.[RoleId], source.[PermissionId], 1);
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
