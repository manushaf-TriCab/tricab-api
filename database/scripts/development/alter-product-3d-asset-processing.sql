/*
  DEVELOPMENT — TriCab Product 3D asset analysis / optimisation (Phase 2D)

  Idempotent. Review before applying to production.
  Depends on [product].[Product3DModels] from alter-product-3d-studio.sql.

  Apply:
    sqlcmd ... -i database/scripts/development/alter-product-3d-asset-processing.sql
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

/* -------------------------------------------------------------------------- */
/* Product3DModelAssets                                                       */
/* -------------------------------------------------------------------------- */

IF OBJECT_ID(N'[product].[Product3DModelAssets]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[Product3DModelAssets] (
        [Product3DModelAssetId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Product3DModelAssets_PublicId DEFAULT (NEWSEQUENTIALID()),
        [Product3DModelId] BIGINT NOT NULL,
        [AssetTypeCode] NVARCHAR(20) NOT NULL,
        [StorageContainer] NVARCHAR(200) NULL,
        [StoragePath] NVARCHAR(1000) NULL,
        [PublicUrl] NVARCHAR(2000) NULL,
        [MimeType] NVARCHAR(150) NULL,
        [FileSizeBytes] BIGINT NULL,
        [FileExtension] NVARCHAR(20) NULL,
        [OriginalFileName] NVARCHAR(260) NULL,
        [Sha256] CHAR(64) NULL,
        [StatusCode] NVARCHAR(20) NOT NULL CONSTRAINT DF_product_Product3DModelAssets_Status DEFAULT (N'ready'),
        [ScanStatusCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_Product3DModelAssets_Scan DEFAULT (N'not_required'),
        [OptimisationProfile] NVARCHAR(40) NULL,
        [AnalysisJson] NVARCHAR(MAX) NULL,
        [OptimisationMetadataJson] NVARCHAR(MAX) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product3DModelAssets_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product3DModelAssets_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [UpdatedByUserId] BIGINT NULL,
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT PK_product_Product3DModelAssets PRIMARY KEY CLUSTERED ([Product3DModelAssetId]),
        CONSTRAINT UQ_product_Product3DModelAssets_PublicId UNIQUE ([PublicId]),
        CONSTRAINT CK_product_Product3DModelAssets_Type CHECK ([AssetTypeCode] IN (N'source', N'optimised')),
        CONSTRAINT CK_product_Product3DModelAssets_Status CHECK ([StatusCode] IN (N'ready', N'failed', N'active', N'superseded')),
        CONSTRAINT FK_product_Product3DModelAssets_Model FOREIGN KEY ([Product3DModelId])
            REFERENCES [product].[Product3DModels] ([Product3DModelId]),
        CONSTRAINT FK_product_Product3DModelAssets_CreatedBy FOREIGN KEY ([CreatedByUserId])
            REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_Product3DModelAssets_UpdatedBy FOREIGN KEY ([UpdatedByUserId])
            REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_Product3DModelAssets_DeletedBy FOREIGN KEY ([DeletedByUserId])
            REFERENCES [identity].[Users] ([UserId])
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[Product3DModelAssets]')
      AND name = N'IX_product_Product3DModelAssets_Model_Type'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_Product3DModelAssets_Model_Type
        ON [product].[Product3DModelAssets] ([Product3DModelId], [AssetTypeCode])
        WHERE [DeletedAtUtc] IS NULL;
END;

/* -------------------------------------------------------------------------- */
/* Product3DModels — ActiveAssetId + analysis / optimisation caches           */
/* -------------------------------------------------------------------------- */

IF COL_LENGTH(N'[product].[Product3DModels]', N'ActiveAssetId') IS NULL
BEGIN
    ALTER TABLE [product].[Product3DModels]
        ADD [ActiveAssetId] BIGINT NULL;
END;

IF COL_LENGTH(N'[product].[Product3DModels]', N'AnalysisJson') IS NULL
BEGIN
    ALTER TABLE [product].[Product3DModels]
        ADD [AnalysisJson] NVARCHAR(MAX) NULL;
END;

IF COL_LENGTH(N'[product].[Product3DModels]', N'LatestOptimisationMetadataJson') IS NULL
BEGIN
    ALTER TABLE [product].[Product3DModels]
        ADD [LatestOptimisationMetadataJson] NVARCHAR(MAX) NULL;
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE name = N'FK_product_Product3DModels_ActiveAsset'
      AND parent_object_id = OBJECT_ID(N'[product].[Product3DModels]')
)
BEGIN
    ALTER TABLE [product].[Product3DModels]
        ADD CONSTRAINT FK_product_Product3DModels_ActiveAsset
            FOREIGN KEY ([ActiveAssetId]) REFERENCES [product].[Product3DModelAssets] ([Product3DModelAssetId]);
END;

/* -------------------------------------------------------------------------- */
/* Product3DAssetProcessingJobs                                               */
/* -------------------------------------------------------------------------- */

IF OBJECT_ID(N'[product].[Product3DAssetProcessingJobs]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[Product3DAssetProcessingJobs] (
        [Product3DAssetProcessingJobId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Product3DAssetJobs_PublicId DEFAULT (NEWSEQUENTIALID()),
        [ProductId] BIGINT NOT NULL,
        [Product3DModelId] BIGINT NOT NULL,
        [Product3DModelAssetId] BIGINT NULL,
        [OperationTypeCode] NVARCHAR(30) NOT NULL,
        [ProfileCode] NVARCHAR(40) NULL,
        [StatusCode] NVARCHAR(20) NOT NULL CONSTRAINT DF_product_Product3DAssetJobs_Status DEFAULT (N'queued'),
        [InputJson] NVARCHAR(MAX) NOT NULL CONSTRAINT DF_product_Product3DAssetJobs_Input DEFAULT (N'{}'),
        [OutputJson] NVARCHAR(MAX) NULL,
        [ErrorCode] NVARCHAR(80) NULL,
        [ErrorMessage] NVARCHAR(1000) NULL,
        [StartedAtUtc] DATETIME2(7) NULL,
        [CompletedAtUtc] DATETIME2(7) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product3DAssetJobs_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product3DAssetJobs_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [UpdatedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT PK_product_Product3DAssetProcessingJobs PRIMARY KEY CLUSTERED ([Product3DAssetProcessingJobId]),
        CONSTRAINT UQ_product_Product3DAssetProcessingJobs_PublicId UNIQUE ([PublicId]),
        CONSTRAINT CK_product_Product3DAssetJobs_Operation CHECK (
            [OperationTypeCode] IN (N'analyse_glb', N'optimise_glb', N'validate_glb')
        ),
        CONSTRAINT CK_product_Product3DAssetJobs_Status CHECK (
            [StatusCode] IN (N'queued', N'processing', N'ready', N'failed', N'cancelled')
        ),
        CONSTRAINT FK_product_Product3DAssetJobs_Product FOREIGN KEY ([ProductId])
            REFERENCES [product].[Products] ([ProductId]),
        CONSTRAINT FK_product_Product3DAssetJobs_Model FOREIGN KEY ([Product3DModelId])
            REFERENCES [product].[Product3DModels] ([Product3DModelId]),
        CONSTRAINT FK_product_Product3DAssetJobs_Asset FOREIGN KEY ([Product3DModelAssetId])
            REFERENCES [product].[Product3DModelAssets] ([Product3DModelAssetId]),
        CONSTRAINT FK_product_Product3DAssetJobs_CreatedBy FOREIGN KEY ([CreatedByUserId])
            REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_Product3DAssetJobs_UpdatedBy FOREIGN KEY ([UpdatedByUserId])
            REFERENCES [identity].[Users] ([UserId])
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[Product3DAssetProcessingJobs]')
      AND name = N'IX_product_Product3DAssetJobs_Model_Status'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_Product3DAssetJobs_Model_Status
        ON [product].[Product3DAssetProcessingJobs] ([Product3DModelId], [StatusCode], [CreatedAtUtc] DESC);
END;

COMMIT TRANSACTION;
