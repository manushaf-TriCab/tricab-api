-- Phase 12: Legacy Product Import and Migration Engine
-- Additive only. Does not write to product domain tables from this script.
-- Does not drop or modify legacy MySQL data.

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'migration')
BEGIN
    EXEC(N'CREATE SCHEMA [migration]');
END
GO

/* ------------------------------------------------------------------
   MigrationBatches
   ------------------------------------------------------------------ */
IF OBJECT_ID(N'[migration].[MigrationBatches]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[MigrationBatches] (
        [MigrationBatchId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_migration_MigrationBatches_PublicId DEFAULT (NEWSEQUENTIALID()),
        [Name] NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(2000) NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [SourceFilterJson] NVARCHAR(MAX) NULL,
        [DryRun] BIT NOT NULL CONSTRAINT DF_migration_MigrationBatches_DryRun DEFAULT (1),
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_MigrationBatches_Status DEFAULT (N'draft'),
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_MigrationBatches_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NOT NULL,
        [StartedAtUtc] DATETIME2(7) NULL,
        [CompletedAtUtc] DATETIME2(7) NULL,
        [RolledBackAtUtc] DATETIME2(7) NULL,
        [RolledBackByUserId] BIGINT NULL,
        [CancelledAtUtc] DATETIME2(7) NULL,
        [CancelledByUserId] BIGINT NULL,
        [TotalRecords] INT NOT NULL CONSTRAINT DF_migration_MigrationBatches_Total DEFAULT (0),
        [ImportedRecords] INT NOT NULL CONSTRAINT DF_migration_MigrationBatches_Imported DEFAULT (0),
        [UpdatedRecords] INT NOT NULL CONSTRAINT DF_migration_MigrationBatches_Updated DEFAULT (0),
        [SkippedRecords] INT NOT NULL CONSTRAINT DF_migration_MigrationBatches_Skipped DEFAULT (0),
        [WarningRecords] INT NOT NULL CONSTRAINT DF_migration_MigrationBatches_Warning DEFAULT (0),
        [FailedRecords] INT NOT NULL CONSTRAINT DF_migration_MigrationBatches_Failed DEFAULT (0),
        [ManualReviewRecords] INT NOT NULL CONSTRAINT DF_migration_MigrationBatches_ManualReview DEFAULT (0),
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT PK_migration_MigrationBatches PRIMARY KEY CLUSTERED ([MigrationBatchId]),
        CONSTRAINT UQ_migration_MigrationBatches_PublicId UNIQUE ([PublicId]),
        CONSTRAINT CK_migration_MigrationBatches_Status CHECK ([StatusCode] IN (
            N'draft', N'extracting', N'extracted', N'normalizing', N'normalized',
            N'validated', N'ready', N'running', N'completed', N'completed-with-warnings',
            N'failed', N'rolled-back', N'cancelled'
        )),
        CONSTRAINT CK_migration_MigrationBatches_SourceSystem CHECK ([SourceSystem] IN (
            N'wordpress', N'tc', N'files', N'combined'
        ))
    );
END
GO

CREATE NONCLUSTERED INDEX IX_migration_MigrationBatches_Status
    ON [migration].[MigrationBatches] ([StatusCode], [CreatedAtUtc] DESC);
GO

/* ------------------------------------------------------------------
   Raw staging (shared shape)
   ------------------------------------------------------------------ */
IF OBJECT_ID(N'[migration].[LegacyProductRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyProductRaw] (
        [LegacyProductRawId] BIGINT IDENTITY(1,1) NOT NULL,
        [MigrationBatchId] BIGINT NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [SourceTable] NVARCHAR(100) NOT NULL,
        [SourceIdentifier] NVARCHAR(200) NOT NULL,
        [ParentSourceIdentifier] NVARCHAR(200) NULL,
        [SourceHash] NVARCHAR(64) NOT NULL,
        [RawPayloadJson] NVARCHAR(MAX) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyProductRaw_Extracted DEFAULT (SYSUTCDATETIME()),
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_LegacyProductRaw_Status DEFAULT (N'extracted'),
        [ErrorMessage] NVARCHAR(2000) NULL,
        [WarningMessage] NVARCHAR(2000) NULL,
        CONSTRAINT PK_migration_LegacyProductRaw PRIMARY KEY CLUSTERED ([LegacyProductRawId]),
        CONSTRAINT FK_migration_LegacyProductRaw_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId]),
        CONSTRAINT UQ_migration_LegacyProductRaw_BatchSource UNIQUE ([MigrationBatchId], [SourceSystem], [SourceTable], [SourceIdentifier])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyVariantRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyVariantRaw] (
        [LegacyVariantRawId] BIGINT IDENTITY(1,1) NOT NULL,
        [MigrationBatchId] BIGINT NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [SourceTable] NVARCHAR(100) NOT NULL,
        [SourceIdentifier] NVARCHAR(200) NOT NULL,
        [ParentSourceIdentifier] NVARCHAR(200) NULL,
        [SourceHash] NVARCHAR(64) NOT NULL,
        [RawPayloadJson] NVARCHAR(MAX) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyVariantRaw_Extracted DEFAULT (SYSUTCDATETIME()),
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_LegacyVariantRaw_Status DEFAULT (N'extracted'),
        [ErrorMessage] NVARCHAR(2000) NULL,
        [WarningMessage] NVARCHAR(2000) NULL,
        CONSTRAINT PK_migration_LegacyVariantRaw PRIMARY KEY CLUSTERED ([LegacyVariantRawId]),
        CONSTRAINT FK_migration_LegacyVariantRaw_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId]),
        CONSTRAINT UQ_migration_LegacyVariantRaw_BatchSource UNIQUE ([MigrationBatchId], [SourceSystem], [SourceTable], [SourceIdentifier])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacySpecificationRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacySpecificationRaw] (
        [LegacySpecificationRawId] BIGINT IDENTITY(1,1) NOT NULL,
        [MigrationBatchId] BIGINT NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [SourceTable] NVARCHAR(100) NOT NULL,
        [SourceIdentifier] NVARCHAR(200) NOT NULL,
        [ParentSourceIdentifier] NVARCHAR(200) NULL,
        [SourceHash] NVARCHAR(64) NOT NULL,
        [RawPayloadJson] NVARCHAR(MAX) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacySpecificationRaw_Extracted DEFAULT (SYSUTCDATETIME()),
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_LegacySpecificationRaw_Status DEFAULT (N'extracted'),
        [ErrorMessage] NVARCHAR(2000) NULL,
        [WarningMessage] NVARCHAR(2000) NULL,
        CONSTRAINT PK_migration_LegacySpecificationRaw PRIMARY KEY CLUSTERED ([LegacySpecificationRawId]),
        CONSTRAINT FK_migration_LegacySpecificationRaw_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId]),
        CONSTRAINT UQ_migration_LegacySpecificationRaw_BatchSource UNIQUE ([MigrationBatchId], [SourceSystem], [SourceTable], [SourceIdentifier])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyMediaRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyMediaRaw] (
        [LegacyMediaRawId] BIGINT IDENTITY(1,1) NOT NULL,
        [MigrationBatchId] BIGINT NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [SourceTable] NVARCHAR(100) NOT NULL,
        [SourceIdentifier] NVARCHAR(200) NOT NULL,
        [ParentSourceIdentifier] NVARCHAR(200) NULL,
        [SourceHash] NVARCHAR(64) NOT NULL,
        [RawPayloadJson] NVARCHAR(MAX) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyMediaRaw_Extracted DEFAULT (SYSUTCDATETIME()),
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_LegacyMediaRaw_Status DEFAULT (N'extracted'),
        [ErrorMessage] NVARCHAR(2000) NULL,
        [WarningMessage] NVARCHAR(2000) NULL,
        CONSTRAINT PK_migration_LegacyMediaRaw PRIMARY KEY CLUSTERED ([LegacyMediaRawId]),
        CONSTRAINT FK_migration_LegacyMediaRaw_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId]),
        CONSTRAINT UQ_migration_LegacyMediaRaw_BatchSource UNIQUE ([MigrationBatchId], [SourceSystem], [SourceTable], [SourceIdentifier])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyDocumentRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyDocumentRaw] (
        [LegacyDocumentRawId] BIGINT IDENTITY(1,1) NOT NULL,
        [MigrationBatchId] BIGINT NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [SourceTable] NVARCHAR(100) NOT NULL,
        [SourceIdentifier] NVARCHAR(200) NOT NULL,
        [ParentSourceIdentifier] NVARCHAR(200) NULL,
        [SourceHash] NVARCHAR(64) NOT NULL,
        [RawPayloadJson] NVARCHAR(MAX) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyDocumentRaw_Extracted DEFAULT (SYSUTCDATETIME()),
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_LegacyDocumentRaw_Status DEFAULT (N'extracted'),
        [ErrorMessage] NVARCHAR(2000) NULL,
        [WarningMessage] NVARCHAR(2000) NULL,
        CONSTRAINT PK_migration_LegacyDocumentRaw PRIMARY KEY CLUSTERED ([LegacyDocumentRawId]),
        CONSTRAINT FK_migration_LegacyDocumentRaw_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId]),
        CONSTRAINT UQ_migration_LegacyDocumentRaw_BatchSource UNIQUE ([MigrationBatchId], [SourceSystem], [SourceTable], [SourceIdentifier])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyRelationshipRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyRelationshipRaw] (
        [LegacyRelationshipRawId] BIGINT IDENTITY(1,1) NOT NULL,
        [MigrationBatchId] BIGINT NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [SourceTable] NVARCHAR(100) NOT NULL,
        [SourceIdentifier] NVARCHAR(200) NOT NULL,
        [ParentSourceIdentifier] NVARCHAR(200) NULL,
        [SourceHash] NVARCHAR(64) NOT NULL,
        [RawPayloadJson] NVARCHAR(MAX) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyRelationshipRaw_Extracted DEFAULT (SYSUTCDATETIME()),
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_LegacyRelationshipRaw_Status DEFAULT (N'extracted'),
        [ErrorMessage] NVARCHAR(2000) NULL,
        [WarningMessage] NVARCHAR(2000) NULL,
        CONSTRAINT PK_migration_LegacyRelationshipRaw PRIMARY KEY CLUSTERED ([LegacyRelationshipRawId]),
        CONSTRAINT FK_migration_LegacyRelationshipRaw_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId]),
        CONSTRAINT UQ_migration_LegacyRelationshipRaw_BatchSource UNIQUE ([MigrationBatchId], [SourceSystem], [SourceTable], [SourceIdentifier])
    );
END
GO

IF OBJECT_ID(N'[migration].[LegacyClassificationRaw]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[LegacyClassificationRaw] (
        [LegacyClassificationRawId] BIGINT IDENTITY(1,1) NOT NULL,
        [MigrationBatchId] BIGINT NOT NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [SourceTable] NVARCHAR(100) NOT NULL,
        [SourceIdentifier] NVARCHAR(200) NOT NULL,
        [ParentSourceIdentifier] NVARCHAR(200) NULL,
        [SourceHash] NVARCHAR(64) NOT NULL,
        [RawPayloadJson] NVARCHAR(MAX) NOT NULL,
        [ExtractedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyClassificationRaw_Extracted DEFAULT (SYSUTCDATETIME()),
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_LegacyClassificationRaw_Status DEFAULT (N'extracted'),
        [ErrorMessage] NVARCHAR(2000) NULL,
        [WarningMessage] NVARCHAR(2000) NULL,
        CONSTRAINT PK_migration_LegacyClassificationRaw PRIMARY KEY CLUSTERED ([LegacyClassificationRawId]),
        CONSTRAINT FK_migration_LegacyClassificationRaw_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId]),
        CONSTRAINT UQ_migration_LegacyClassificationRaw_BatchSource UNIQUE ([MigrationBatchId], [SourceSystem], [SourceTable], [SourceIdentifier])
    );
END
GO

/* ------------------------------------------------------------------
   Canonical staging (migration-layer, not product domain)
   ------------------------------------------------------------------ */
IF OBJECT_ID(N'[migration].[CanonicalProducts]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[CanonicalProducts] (
        [CanonicalProductId] BIGINT IDENTITY(1,1) NOT NULL,
        [MigrationBatchId] BIGINT NOT NULL,
        [SourceKey] NVARCHAR(300) NOT NULL,
        [CanonicalJson] NVARCHAR(MAX) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_CanonicalProducts_Status DEFAULT (N'normalized'),
        [ProductDecisionCode] NVARCHAR(40) NULL,
        [ProductDecisionReason] NVARCHAR(1000) NULL,
        [MatchMethod] NVARCHAR(80) NULL,
        [MatchConfidence] NVARCHAR(40) NULL,
        [TargetProductPublicId] UNIQUEIDENTIFIER NULL,
        [NormalizedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_CanonicalProducts_Norm DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_migration_CanonicalProducts PRIMARY KEY CLUSTERED ([CanonicalProductId]),
        CONSTRAINT FK_migration_CanonicalProducts_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId]),
        CONSTRAINT UQ_migration_CanonicalProducts_BatchSource UNIQUE ([MigrationBatchId], [SourceKey])
    );
END
GO

/* ------------------------------------------------------------------
   Mapping tables
   ------------------------------------------------------------------ */
IF OBJECT_ID(N'[migration].[ClassificationMappings]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[ClassificationMappings] (
        [ClassificationMappingId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_migration_ClassificationMappings_PublicId DEFAULT (NEWSEQUENTIALID()),
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [LegacyClassificationValue] NVARCHAR(300) NOT NULL,
        [LegacyTaxonomy] NVARCHAR(100) NULL,
        [TargetFamilyPublicId] UNIQUEIDENTIFIER NULL,
        [TargetCategoryPublicId] UNIQUEIDENTIFIER NULL,
        [TargetSeriesPublicId] UNIQUEIDENTIFIER NULL,
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_ClassificationMappings_Status DEFAULT (N'needs-review'),
        [MappedByUserId] BIGINT NULL,
        [MappedAtUtc] DATETIME2(7) NULL,
        [Notes] NVARCHAR(2000) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_ClassificationMappings_Created DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_ClassificationMappings_Updated DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_migration_ClassificationMappings PRIMARY KEY CLUSTERED ([ClassificationMappingId]),
        CONSTRAINT UQ_migration_ClassificationMappings_PublicId UNIQUE ([PublicId]),
        CONSTRAINT UQ_migration_ClassificationMappings_Source UNIQUE ([SourceSystem], [LegacyClassificationValue], [LegacyTaxonomy]),
        CONSTRAINT CK_migration_ClassificationMappings_Status CHECK ([StatusCode] IN (N'mapped', N'needs-review', N'rejected', N'ignored'))
    );
END
GO

IF OBJECT_ID(N'[migration].[SpecificationFieldMappings]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[SpecificationFieldMappings] (
        [SpecificationFieldMappingId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_migration_SpecificationFieldMappings_PublicId DEFAULT (NEWSEQUENTIALID()),
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [SourceTable] NVARCHAR(100) NOT NULL,
        [SourceField] NVARCHAR(100) NOT NULL,
        [TargetSpecificationDefinitionPublicId] UNIQUEIDENTIFIER NULL,
        [TargetDefinitionCode] NVARCHAR(100) NULL,
        [TargetScopeCode] NVARCHAR(20) NULL,
        [TransformationRule] NVARCHAR(100) NOT NULL CONSTRAINT DF_migration_SpecificationFieldMappings_Rule DEFAULT (N'text'),
        [SourceUnit] NVARCHAR(50) NULL,
        [TargetUnit] NVARCHAR(50) NULL,
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_SpecificationFieldMappings_Status DEFAULT (N'needs-review'),
        [MappedByUserId] BIGINT NULL,
        [MappedAtUtc] DATETIME2(7) NULL,
        [Notes] NVARCHAR(2000) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_SpecificationFieldMappings_Created DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_SpecificationFieldMappings_Updated DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_migration_SpecificationFieldMappings PRIMARY KEY CLUSTERED ([SpecificationFieldMappingId]),
        CONSTRAINT UQ_migration_SpecificationFieldMappings_PublicId UNIQUE ([PublicId]),
        CONSTRAINT UQ_migration_SpecificationFieldMappings_Source UNIQUE ([SourceSystem], [SourceTable], [SourceField]),
        CONSTRAINT CK_migration_SpecificationFieldMappings_Status CHECK ([StatusCode] IN (N'mapped', N'needs-review', N'rejected', N'ignored'))
    );
END
GO

IF OBJECT_ID(N'[migration].[SpecificationOptionAliases]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[SpecificationOptionAliases] (
        [SpecificationOptionAliasId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_migration_SpecificationOptionAliases_PublicId DEFAULT (NEWSEQUENTIALID()),
        [DefinitionCode] NVARCHAR(100) NOT NULL,
        [LegacyValue] NVARCHAR(300) NOT NULL,
        [NormalizedLegacyValue] NVARCHAR(300) NOT NULL,
        [TargetOptionPublicId] UNIQUEIDENTIFIER NULL,
        [TargetOptionCode] NVARCHAR(100) NULL,
        [SourceSystem] NVARCHAR(50) NOT NULL,
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_SpecificationOptionAliases_Status DEFAULT (N'needs-review'),
        [MappedByUserId] BIGINT NULL,
        [MappedAtUtc] DATETIME2(7) NULL,
        [Notes] NVARCHAR(2000) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_SpecificationOptionAliases_Created DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_SpecificationOptionAliases_Updated DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_migration_SpecificationOptionAliases PRIMARY KEY CLUSTERED ([SpecificationOptionAliasId]),
        CONSTRAINT UQ_migration_SpecificationOptionAliases_PublicId UNIQUE ([PublicId]),
        CONSTRAINT UQ_migration_SpecificationOptionAliases_Source UNIQUE ([SourceSystem], [DefinitionCode], [NormalizedLegacyValue]),
        CONSTRAINT CK_migration_SpecificationOptionAliases_Status CHECK ([StatusCode] IN (N'mapped', N'needs-review', N'rejected', N'ignored'))
    );
END
GO

/* Extend LegacyProductMappings with hash / entity / batch columns when missing */
IF COL_LENGTH(N'[migration].[LegacyProductMappings]', N'MigrationBatchId') IS NULL
BEGIN
    ALTER TABLE [migration].[LegacyProductMappings] ADD [MigrationBatchId] BIGINT NULL;
END
GO
IF COL_LENGTH(N'[migration].[LegacyProductMappings]', N'SourceTable') IS NULL
BEGIN
    ALTER TABLE [migration].[LegacyProductMappings] ADD [SourceTable] NVARCHAR(100) NULL;
END
GO
IF COL_LENGTH(N'[migration].[LegacyProductMappings]', N'SourceHash') IS NULL
BEGIN
    ALTER TABLE [migration].[LegacyProductMappings] ADD [SourceHash] NVARCHAR(64) NULL;
END
GO
IF COL_LENGTH(N'[migration].[LegacyProductMappings]', N'TargetEntityType') IS NULL
BEGIN
    ALTER TABLE [migration].[LegacyProductMappings] ADD [TargetEntityType] NVARCHAR(50) NULL;
END
GO
IF COL_LENGTH(N'[migration].[LegacyProductMappings]', N'TargetPublicId') IS NULL
BEGIN
    ALTER TABLE [migration].[LegacyProductMappings] ADD [TargetPublicId] UNIQUEIDENTIFIER NULL;
END
GO

/* ------------------------------------------------------------------
   Issues / review / reconciliation / audit
   ------------------------------------------------------------------ */
IF OBJECT_ID(N'[migration].[MigrationIssues]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[MigrationIssues] (
        [MigrationIssueId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_migration_MigrationIssues_PublicId DEFAULT (NEWSEQUENTIALID()),
        [MigrationBatchId] BIGINT NOT NULL,
        [IssueTypeCode] NVARCHAR(80) NOT NULL,
        [SeverityCode] NVARCHAR(20) NOT NULL CONSTRAINT DF_migration_MigrationIssues_Severity DEFAULT (N'warning'),
        [SourceSystem] NVARCHAR(50) NULL,
        [SourceTable] NVARCHAR(100) NULL,
        [SourceIdentifier] NVARCHAR(200) NULL,
        [SourceKey] NVARCHAR(300) NULL,
        [Message] NVARCHAR(2000) NOT NULL,
        [SuggestedMatchJson] NVARCHAR(MAX) NULL,
        [MatchConfidence] NVARCHAR(40) NULL,
        [StatusCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_migration_MigrationIssues_Status DEFAULT (N'open'),
        [ResolutionJson] NVARCHAR(MAX) NULL,
        [ResolvedByUserId] BIGINT NULL,
        [ResolvedAtUtc] DATETIME2(7) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_MigrationIssues_Created DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_migration_MigrationIssues PRIMARY KEY CLUSTERED ([MigrationIssueId]),
        CONSTRAINT UQ_migration_MigrationIssues_PublicId UNIQUE ([PublicId]),
        CONSTRAINT FK_migration_MigrationIssues_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId]),
        CONSTRAINT CK_migration_MigrationIssues_Severity CHECK ([SeverityCode] IN (N'info', N'warning', N'blocking', N'manual-review')),
        CONSTRAINT CK_migration_MigrationIssues_Status CHECK ([StatusCode] IN (N'open', N'resolved', N'ignored', N'retried'))
    );
END
GO

CREATE NONCLUSTERED INDEX IX_migration_MigrationIssues_BatchStatus
    ON [migration].[MigrationIssues] ([MigrationBatchId], [StatusCode], [IssueTypeCode]);
GO

IF OBJECT_ID(N'[migration].[MigrationReconciliationReports]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[MigrationReconciliationReports] (
        [MigrationReconciliationReportId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_migration_MigrationReconciliationReports_PublicId DEFAULT (NEWSEQUENTIALID()),
        [MigrationBatchId] BIGINT NOT NULL,
        [ReportJson] NVARCHAR(MAX) NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_MigrationReconciliationReports_Created DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_migration_MigrationReconciliationReports PRIMARY KEY CLUSTERED ([MigrationReconciliationReportId]),
        CONSTRAINT UQ_migration_MigrationReconciliationReports_PublicId UNIQUE ([PublicId]),
        CONSTRAINT FK_migration_MigrationReconciliationReports_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId])
    );
END
GO

IF OBJECT_ID(N'[migration].[MigrationAuditEvents]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[MigrationAuditEvents] (
        [MigrationAuditEventId] BIGINT IDENTITY(1,1) NOT NULL,
        [MigrationBatchId] BIGINT NULL,
        [ActionCode] NVARCHAR(80) NOT NULL,
        [ActorUserId] BIGINT NULL,
        [CorrelationId] NVARCHAR(100) NULL,
        [PayloadJson] NVARCHAR(MAX) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_MigrationAuditEvents_Created DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_migration_MigrationAuditEvents PRIMARY KEY CLUSTERED ([MigrationAuditEventId])
    );
END
GO

IF OBJECT_ID(N'[migration].[MigrationDryRunReports]', N'U') IS NULL
BEGIN
    CREATE TABLE [migration].[MigrationDryRunReports] (
        [MigrationDryRunReportId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_migration_MigrationDryRunReports_PublicId DEFAULT (NEWSEQUENTIALID()),
        [MigrationBatchId] BIGINT NOT NULL,
        [ReportJson] NVARCHAR(MAX) NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_MigrationDryRunReports_Created DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_migration_MigrationDryRunReports PRIMARY KEY CLUSTERED ([MigrationDryRunReportId]),
        CONSTRAINT UQ_migration_MigrationDryRunReports_PublicId UNIQUE ([PublicId]),
        CONSTRAINT FK_migration_MigrationDryRunReports_Batch FOREIGN KEY ([MigrationBatchId])
            REFERENCES [migration].[MigrationBatches] ([MigrationBatchId])
    );
END
GO

PRINT N'Phase 12 migration schema applied.';
