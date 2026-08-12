/*
  DEVELOPMENT — TriCab Product 3D Studio

  Idempotent. Review before applying to production.
  Does not drop ProductARModels / Product360Sets / ProductImages.

  Apply:
    sqlcmd ... -i database/scripts/development/alter-product-3d-studio.sql
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF OBJECT_ID(N'[product].[Product3DModels]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[Product3DModels] (
        [Product3DModelId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Product3DModels_PublicId DEFAULT (NEWSEQUENTIALID()),
        [ProductId] BIGINT NOT NULL,
        [ProductVariantId] BIGINT NULL,
        [Name] NVARCHAR(200) NOT NULL,
        [ModelTypeCode] NVARCHAR(20) NOT NULL,
        [SourceTypeCode] NVARCHAR(30) NOT NULL,
        [StorageProviderCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_Product3DModels_StorageProvider DEFAULT (N'azure-blob'),
        [StorageContainer] NVARCHAR(200) NULL,
        [StoragePath] NVARCHAR(1000) NULL,
        [PublicUrl] NVARCHAR(2000) NULL,
        [FileName] NVARCHAR(260) NULL,
        [OriginalFileName] NVARCHAR(260) NULL,
        [MimeType] NVARCHAR(150) NULL,
        [FileSizeBytes] BIGINT NULL,
        [FileExtension] NVARCHAR(20) NULL,
        [PreviewImageStoragePath] NVARCHAR(1000) NULL,
        [PreviewImagePublicUrl] NVARCHAR(2000) NULL,
        [ViewerConfigurationJson] NVARCHAR(MAX) NOT NULL CONSTRAINT DF_product_Product3DModels_ViewerJson DEFAULT (N'{"version":1}'),
        [GenerationMetadataJson] NVARCHAR(MAX) NULL,
        [MaterialOverridesJson] NVARCHAR(MAX) NULL,
        [StatusCode] NVARCHAR(20) NOT NULL CONSTRAINT DF_product_Product3DModels_Status DEFAULT (N'draft'),
        [UploadStatusCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_Product3DModels_UploadStatus DEFAULT (N'pending_upload'),
        [ScanStatusCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_Product3DModels_ScanStatus DEFAULT (N'pending_upload'),
        [IsPrimary] BIT NOT NULL CONSTRAINT DF_product_Product3DModels_IsPrimary DEFAULT (0),
        [IsActive] BIT NOT NULL CONSTRAINT DF_product_Product3DModels_IsActive DEFAULT (1),
        [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_Product3DModels_DisplayOrder DEFAULT (0),
        [PublishedAtUtc] DATETIME2(7) NULL,
        [PublishedByUserId] BIGINT NULL,
        [UploadCompletedAtUtc] DATETIME2(7) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product3DModels_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product3DModels_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [UpdatedByUserId] BIGINT NULL,
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT PK_product_Product3DModels PRIMARY KEY CLUSTERED ([Product3DModelId]),
        CONSTRAINT UQ_product_Product3DModels_PublicId UNIQUE ([PublicId]),
        CONSTRAINT CK_product_Product3DModels_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([Name]))) > 0),
        CONSTRAINT CK_product_Product3DModels_ModelType CHECK ([ModelTypeCode] IN (N'procedural', N'glb', N'gltf')),
        CONSTRAINT CK_product_Product3DModels_SourceType CHECK ([SourceTypeCode] IN (N'specification', N'image_generation', N'upload')),
        CONSTRAINT CK_product_Product3DModels_Status CHECK ([StatusCode] IN (N'draft', N'processing', N'ready', N'failed', N'published', N'archived')),
        CONSTRAINT FK_product_Product3DModels_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
        CONSTRAINT FK_product_Product3DModels_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
        CONSTRAINT FK_product_Product3DModels_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_Product3DModels_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_Product3DModels_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_Product3DModels_PublishedBy FOREIGN KEY ([PublishedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[Product3DModels]')
      AND name = N'IX_product_Product3DModels_Product_Status'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_Product3DModels_Product_Status
        ON [product].[Product3DModels] ([ProductId], [StatusCode], [DisplayOrder])
        WHERE [DeletedAtUtc] IS NULL;
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[Product3DModels]')
      AND name = N'IX_product_Product3DModels_Variant'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_Product3DModels_Variant
        ON [product].[Product3DModels] ([ProductVariantId])
        WHERE [ProductVariantId] IS NOT NULL AND [DeletedAtUtc] IS NULL;
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[Product3DModels]')
      AND name = N'UQ_product_Product3DModels_Product_Primary'
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_product_Product3DModels_Product_Primary
        ON [product].[Product3DModels] ([ProductId])
        WHERE [IsPrimary] = 1 AND [DeletedAtUtc] IS NULL AND [ProductVariantId] IS NULL;
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[Product3DModels]')
      AND name = N'UQ_product_Product3DModels_Variant_Primary'
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_product_Product3DModels_Variant_Primary
        ON [product].[Product3DModels] ([ProductVariantId])
        WHERE [IsPrimary] = 1 AND [DeletedAtUtc] IS NULL AND [ProductVariantId] IS NOT NULL;
END;

IF OBJECT_ID(N'[product].[Product3DGenerationJobs]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[Product3DGenerationJobs] (
        [Product3DGenerationJobId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Product3DJobs_PublicId DEFAULT (NEWSEQUENTIALID()),
        [ProductId] BIGINT NOT NULL,
        [ProductVariantId] BIGINT NULL,
        [Product3DModelId] BIGINT NULL,
        [GenerationModeCode] NVARCHAR(30) NOT NULL,
        [ProviderCode] NVARCHAR(50) NOT NULL,
        [StatusCode] NVARCHAR(20) NOT NULL CONSTRAINT DF_product_Product3DJobs_Status DEFAULT (N'queued'),
        [InputJson] NVARCHAR(MAX) NOT NULL CONSTRAINT DF_product_Product3DJobs_Input DEFAULT (N'{}'),
        [OutputJson] NVARCHAR(MAX) NULL,
        [ErrorCode] NVARCHAR(80) NULL,
        [ErrorMessage] NVARCHAR(1000) NULL,
        [StartedAtUtc] DATETIME2(7) NULL,
        [CompletedAtUtc] DATETIME2(7) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product3DJobs_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product3DJobs_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT PK_product_Product3DGenerationJobs PRIMARY KEY CLUSTERED ([Product3DGenerationJobId]),
        CONSTRAINT UQ_product_Product3DGenerationJobs_PublicId UNIQUE ([PublicId]),
        CONSTRAINT CK_product_Product3DJobs_Mode CHECK ([GenerationModeCode] IN (N'specification', N'image_generation')),
        CONSTRAINT CK_product_Product3DJobs_Status CHECK ([StatusCode] IN (N'queued', N'processing', N'ready', N'failed', N'cancelled')),
        CONSTRAINT FK_product_Product3DJobs_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
        CONSTRAINT FK_product_Product3DJobs_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
        CONSTRAINT FK_product_Product3DJobs_Model FOREIGN KEY ([Product3DModelId]) REFERENCES [product].[Product3DModels] ([Product3DModelId]),
        CONSTRAINT FK_product_Product3DJobs_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[Product3DGenerationJobs]')
      AND name = N'IX_product_Product3DJobs_Product_Status'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_Product3DJobs_Product_Status
        ON [product].[Product3DGenerationJobs] ([ProductId], [StatusCode], [CreatedAtUtc] DESC);
END;

IF OBJECT_ID(N'[product].[Product3DGenerationJobMedia]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[Product3DGenerationJobMedia] (
        [Product3DGenerationJobMediaId] BIGINT IDENTITY(1,1) NOT NULL,
        [GenerationJobId] BIGINT NOT NULL,
        [ProductMediaId] BIGINT NOT NULL,
        [ViewTypeCode] NVARCHAR(20) NOT NULL,
        [SortOrder] INT NOT NULL CONSTRAINT DF_product_Product3DJobMedia_Sort DEFAULT (0),
        CONSTRAINT PK_product_Product3DGenerationJobMedia PRIMARY KEY CLUSTERED ([Product3DGenerationJobMediaId]),
        CONSTRAINT CK_product_Product3DJobMedia_ViewType CHECK ([ViewTypeCode] IN (N'front', N'side', N'top', N'reference')),
        CONSTRAINT FK_product_Product3DJobMedia_Job FOREIGN KEY ([GenerationJobId]) REFERENCES [product].[Product3DGenerationJobs] ([Product3DGenerationJobId]),
        CONSTRAINT FK_product_Product3DJobMedia_Media FOREIGN KEY ([ProductMediaId]) REFERENCES [product].[ProductImages] ([ProductImagesId])
    );
END;

COMMIT TRANSACTION;
