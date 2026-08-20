-- Legacy Migration Framework control plane.
-- Additive only. Does not modify product migration.MigrationBatches or production identity/customer tables.
-- Does not connect to WordPress, Moodle, or the business database.

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'migration')
BEGIN
    EXEC(N'CREATE SCHEMA [migration]');
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationSources]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationSources] (
        [SourceCode] NVARCHAR(50) NOT NULL,
        [Label] NVARCHAR(200) NOT NULL,
        [Kind] NVARCHAR(50) NOT NULL,
        [Health] NVARCHAR(40) NOT NULL CONSTRAINT DF_legacy_sources_health DEFAULT (N'unconfigured'),
        [Version] NVARCHAR(100) NULL,
        [LastSyncAtUtc] DATETIME2(7) NULL,
        [RecordCountsJson] NVARCHAR(MAX) NULL,
        [ErrorsJson] NVARCHAR(MAX) NULL,
        [ConnectionJson] NVARCHAR(MAX) NULL,
        CONSTRAINT PK_legacy_sources PRIMARY KEY CLUSTERED ([SourceCode])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationSourceConnections]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationSourceConnections] (
        [SourceConnectionId] BIGINT IDENTITY(1,1) NOT NULL,
        [SourceCode] NVARCHAR(50) NOT NULL,
        [ReadOnly] BIT NOT NULL CONSTRAINT DF_legacy_source_conn_ro DEFAULT (1),
        [Configured] BIT NOT NULL CONSTRAINT DF_legacy_source_conn_cfg DEFAULT (0),
        [SecretRef] NVARCHAR(200) NULL,
        CONSTRAINT PK_legacy_source_conn PRIMARY KEY CLUSTERED ([SourceConnectionId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationBatches]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationBatches] (
        [LegacyMigrationBatchId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_legacy_batches_public DEFAULT (NEWSEQUENTIALID()),
        [Name] NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(2000) NULL,
        [ModuleCode] NVARCHAR(50) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [SourceFilterJson] NVARCHAR(MAX) NULL,
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_legacy_batches_status DEFAULT (N'created'),
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_legacy_batches_created DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NOT NULL,
        [ApprovedAtUtc] DATETIME2(7) NULL,
        [ApprovedByUserId] BIGINT NULL,
        [StartedAtUtc] DATETIME2(7) NULL,
        [CompletedAtUtc] DATETIME2(7) NULL,
        [RolledBackAtUtc] DATETIME2(7) NULL,
        [RolledBackByUserId] BIGINT NULL,
        [TotalRecords] INT NOT NULL CONSTRAINT DF_legacy_batches_total DEFAULT (0),
        [ImportedRecords] INT NOT NULL CONSTRAINT DF_legacy_batches_imported DEFAULT (0),
        [UpdatedRecords] INT NOT NULL CONSTRAINT DF_legacy_batches_updated DEFAULT (0),
        [SkippedRecords] INT NOT NULL CONSTRAINT DF_legacy_batches_skipped DEFAULT (0),
        [WarningRecords] INT NOT NULL CONSTRAINT DF_legacy_batches_warning DEFAULT (0),
        [FailedRecords] INT NOT NULL CONSTRAINT DF_legacy_batches_failed DEFAULT (0),
        [ManualReviewRecords] INT NOT NULL CONSTRAINT DF_legacy_batches_review DEFAULT (0),
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT PK_legacy_batches PRIMARY KEY CLUSTERED ([LegacyMigrationBatchId]),
        CONSTRAINT UQ_legacy_batches_public UNIQUE ([PublicId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationBatchItems]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationBatchItems] (
        [ItemId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_legacy_items_public DEFAULT (NEWSEQUENTIALID()),
        [LegacyMigrationBatchId] BIGINT NOT NULL,
        [SourceIdentifier] NVARCHAR(200) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        [PayloadJson] NVARCHAR(MAX) NULL,
        [CanonicalJson] NVARCHAR(MAX) NULL,
        [SourceHash] NVARCHAR(64) NULL,
        CONSTRAINT PK_legacy_items PRIMARY KEY CLUSTERED ([ItemId]),
        CONSTRAINT FK_legacy_items_batch FOREIGN KEY ([LegacyMigrationBatchId])
            REFERENCES [migration].[LegacyMigrationBatches] ([LegacyMigrationBatchId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationLogs]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationLogs] (
        [LogId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_legacy_logs_public DEFAULT (NEWSEQUENTIALID()),
        [LegacyMigrationBatchId] BIGINT NULL,
        [ActorUserId] BIGINT NULL,
        [ActorPublicId] NVARCHAR(36) NULL,
        [IpAddress] NVARCHAR(64) NULL,
        [ActionCode] NVARCHAR(80) NOT NULL,
        [ResultCode] NVARCHAR(40) NOT NULL,
        [OldStatus] NVARCHAR(40) NULL,
        [NewStatus] NVARCHAR(40) NULL,
        [DurationMs] INT NOT NULL CONSTRAINT DF_legacy_logs_duration DEFAULT (0),
        [DetailsJson] NVARCHAR(MAX) NULL,
        [OccurredAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_legacy_logs_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_legacy_logs PRIMARY KEY CLUSTERED ([LogId])
    );
END
GO

IF COL_LENGTH(N'migration.LegacyMigrationLogs', N'DurationMs') IS NULL
BEGIN
    ALTER TABLE [migration].[LegacyMigrationLogs]
        ADD [DurationMs] INT NOT NULL CONSTRAINT DF_legacy_logs_duration_add DEFAULT (0);
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationConflicts]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationConflicts] (
        [ConflictId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_legacy_conflicts_public DEFAULT (NEWSEQUENTIALID()),
        [LegacyMigrationBatchId] BIGINT NOT NULL,
        [ConflictType] NVARCHAR(80) NOT NULL,
        [Description] NVARCHAR(2000) NOT NULL,
        [SourceIdentifier] NVARCHAR(200) NULL,
        [DecisionCode] NVARCHAR(40) NULL,
        [ManualMapping] NVARCHAR(500) NULL,
        [ResolvedByUserId] BIGINT NULL,
        [ResolvedAtUtc] DATETIME2(7) NULL,
        CONSTRAINT PK_legacy_conflicts PRIMARY KEY CLUSTERED ([ConflictId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationWarnings]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationWarnings] (
        [WarningId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NOT NULL,
        [PayloadJson] NVARCHAR(MAX) NOT NULL,
        CONSTRAINT PK_legacy_warnings PRIMARY KEY CLUSTERED ([WarningId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationValidationResults]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationValidationResults] (
        [ValidationId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NOT NULL,
        [PayloadJson] NVARCHAR(MAX) NOT NULL,
        CONSTRAINT PK_legacy_validation PRIMARY KEY CLUSTERED ([ValidationId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationReports]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationReports] (
        [ReportId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_legacy_reports_public DEFAULT (NEWSEQUENTIALID()),
        [LegacyMigrationBatchId] BIGINT NULL,
        [ReportType] NVARCHAR(40) NOT NULL,
        [PayloadJson] NVARCHAR(MAX) NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_legacy_reports_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_legacy_reports PRIMARY KEY CLUSTERED ([ReportId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationRollbackHistory]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationRollbackHistory] (
        [RollbackId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_legacy_rollback_public DEFAULT (NEWSEQUENTIALID()),
        [LegacyMigrationBatchId] BIGINT NOT NULL,
        [RemovedSnapshots] INT NOT NULL,
        [ActorUserId] BIGINT NOT NULL,
        [Scope] NVARCHAR(40) NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_legacy_rollback_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_legacy_rollback PRIMARY KEY CLUSTERED ([RollbackId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationJobs]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationJobs] (
        [JobId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_legacy_jobs_public DEFAULT (NEWSEQUENTIALID()),
        [LegacyMigrationBatchId] BIGINT NULL,
        [TypeCode] NVARCHAR(40) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_legacy_jobs_at DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_legacy_jobs PRIMARY KEY CLUSTERED ([JobId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationExecutionHistory]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationExecutionHistory] (
        [ExecutionId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [ActionCode] NVARCHAR(40) NOT NULL,
        [ResultCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_exec PRIMARY KEY CLUSTERED ([ExecutionId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationSnapshots]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationSnapshots] (
        [SnapshotId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_legacy_snap_public DEFAULT (NEWSEQUENTIALID()),
        [LegacyMigrationBatchId] BIGINT NOT NULL,
        [ModuleCode] NVARCHAR(50) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [PayloadJson] NVARCHAR(MAX) NOT NULL,
        CONSTRAINT PK_legacy_snap PRIMARY KEY CLUSTERED ([SnapshotId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationLocks]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationLocks] (
        [BatchPublicId] UNIQUEIDENTIFIER NOT NULL,
        [OwnerPublicId] NVARCHAR(36) NOT NULL,
        [UntilUtc] DATETIME2(7) NOT NULL,
        CONSTRAINT PK_legacy_locks PRIMARY KEY CLUSTERED ([BatchPublicId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationStatistics]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationStatistics] (
        [StatisticId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [Success] BIT NOT NULL,
        [DurationMs] INT NOT NULL,
        [RecordCount] INT NOT NULL,
        CONSTRAINT PK_legacy_stats PRIMARY KEY CLUSTERED ([StatisticId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMigrationSettings]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMigrationSettings] (
        [SettingKey] NVARCHAR(100) NOT NULL,
        [SettingValue] NVARCHAR(1000) NOT NULL,
        CONSTRAINT PK_legacy_settings PRIMARY KEY CLUSTERED ([SettingKey])
    );
END
GO

IF OBJECT_ID(N'dbo.CreateLegacyRaw') IS NOT NULL
    DROP PROCEDURE dbo.CreateLegacyRaw;
GO
-- Raw staging tables share a shape. Created individually below.

IF OBJECT_ID(N'[migration].[LegacyUsersRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyUsersRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_users_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyUserMetaRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyUserMetaRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_usermeta_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyCustomersRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyCustomersRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_customers_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyContactsRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyContactsRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_contacts_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyEmployeesRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyEmployeesRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_employees_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyRolesRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyRolesRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_roles_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyPermissionsRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyPermissionsRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_permissions_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyCertificatesRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyCertificatesRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_certs_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyQuotesRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyQuotesRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_quotes_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyRfqsRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyRfqsRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_rfqs_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyCustomerNotesRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyCustomerNotesRaw] (
        [RawId] BIGINT IDENTITY(1,1) NOT NULL,
        [LegacyId] NVARCHAR(200) NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL,
        [LegacyMigrationBatchId] BIGINT NULL,
        [OriginalJson] NVARCHAR(MAX) NOT NULL,
        [Hash] NVARCHAR(64) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL,
        CONSTRAINT PK_legacy_notes_raw PRIMARY KEY CLUSTERED ([RawId])
    );
END
GO

IF COL_LENGTH(N'migration.LegacyMigrationConflicts', N'StatusCode') IS NULL
BEGIN
    ALTER TABLE [migration].[LegacyMigrationConflicts]
        ADD [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_legacy_conflicts_status DEFAULT (N'open');
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UQ_legacy_users_raw_batch_legacy'
      AND object_id = OBJECT_ID(N'[migration].[LegacyUsersRaw]')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_legacy_users_raw_batch_legacy
        ON [migration].[LegacyUsersRaw] ([LegacyMigrationBatchId], [LegacyId])
        WHERE [LegacyMigrationBatchId] IS NOT NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UQ_legacy_usermeta_raw_batch_legacy'
      AND object_id = OBJECT_ID(N'[migration].[LegacyUserMetaRaw]')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_legacy_usermeta_raw_batch_legacy
        ON [migration].[LegacyUserMetaRaw] ([LegacyMigrationBatchId], [LegacyId])
        WHERE [LegacyMigrationBatchId] IS NOT NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UQ_legacy_employees_raw_batch_legacy'
      AND object_id = OBJECT_ID(N'[migration].[LegacyEmployeesRaw]')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_legacy_employees_raw_batch_legacy
        ON [migration].[LegacyEmployeesRaw] ([LegacyMigrationBatchId], [LegacyId])
        WHERE [LegacyMigrationBatchId] IS NOT NULL;
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'UQ_legacy_contacts_raw_batch_legacy'
      AND object_id = OBJECT_ID(N'[migration].[LegacyContactsRaw]')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_legacy_contacts_raw_batch_legacy
        ON [migration].[LegacyContactsRaw] ([LegacyMigrationBatchId], [LegacyId])
        WHERE [LegacyMigrationBatchId] IS NOT NULL;
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
