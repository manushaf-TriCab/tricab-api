-- Staff Migration Batch 1 mapping table (NEW Azure SQL only).

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'migration')
BEGIN
    EXEC(N'CREATE SCHEMA [migration]');
END
GO

IF OBJECT_ID(N'[migration].[LegacyStaffUserMappings]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyStaffUserMappings] (
        [LegacyStaffUserMappingId] BIGINT IDENTITY(1,1) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL CONSTRAINT DF_legacy_staff_map_source DEFAULT (N'wordpress'),
        [LegacyWordPressUserId] BIGINT NOT NULL,
        [LegacyUserLogin] NVARCHAR(200) NOT NULL,
        [LegacyEmailAddress] NVARCHAR(320) NOT NULL,
        [NormalizedEmailAddress] NVARCHAR(320) NOT NULL,
        [UserId] BIGINT NOT NULL,
        [BatchPublicId] UNIQUEIDENTIFIER NOT NULL,
        [SourceFingerprint] NVARCHAR(64) NOT NULL,
        [MigrationStatusCode] NVARCHAR(40) NOT NULL,
        [MigratedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_legacy_staff_map_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_legacy_staff_user_mappings PRIMARY KEY CLUSTERED ([LegacyStaffUserMappingId]),
        CONSTRAINT UQ_legacy_staff_map_wp_user UNIQUE ([LegacyWordPressUserId]),
        CONSTRAINT UQ_legacy_staff_map_norm_email UNIQUE ([NormalizedEmailAddress])
    );
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_legacy_staff_map_batch'
      AND object_id = OBJECT_ID(N'[migration].[LegacyStaffUserMappings]')
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_legacy_staff_map_batch
        ON [migration].[LegacyStaffUserMappings] ([BatchPublicId]);
END
GO
