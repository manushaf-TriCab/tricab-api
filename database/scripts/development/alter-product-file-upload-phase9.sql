/*
  Phase 9 — Product file upload / scan metadata (non-production only).
  Apply to disposable Azure SQL (e.g. tc) after Phase 1 schema.
  Do NOT apply to production until approved.

  Adds upload/scan lifecycle columns and derivative references to existing
  ProductImages / ProductDocuments / Product360Frames / ProductARModels tables,
  plus ProductFileUploadSessions for abandoned-upload cleanup.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRANSACTION;
BEGIN TRY
    /* ---------- ProductImages ---------- */
    IF COL_LENGTH(N'product.ProductImages', N'UploadStatusCode') IS NULL
        ALTER TABLE [product].[ProductImages] ADD [UploadStatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_ProductImages_UploadStatus DEFAULT (N'active');
    IF COL_LENGTH(N'product.ProductImages', N'ScanStatusCode') IS NULL
        ALTER TABLE [product].[ProductImages] ADD [ScanStatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_ProductImages_ScanStatus DEFAULT (N'not_required');
    IF COL_LENGTH(N'product.ProductImages', N'OriginalFileName') IS NULL
        ALTER TABLE [product].[ProductImages] ADD [OriginalFileName] NVARCHAR(255) NULL;
    IF COL_LENGTH(N'product.ProductImages', N'ETag') IS NULL
        ALTER TABLE [product].[ProductImages] ADD [ETag] NVARCHAR(200) NULL;
    IF COL_LENGTH(N'product.ProductImages', N'UploadExpiresAtUtc') IS NULL
        ALTER TABLE [product].[ProductImages] ADD [UploadExpiresAtUtc] DATETIME2(7) NULL;
    IF COL_LENGTH(N'product.ProductImages', N'UploadCompletedAtUtc') IS NULL
        ALTER TABLE [product].[ProductImages] ADD [UploadCompletedAtUtc] DATETIME2(7) NULL;
    IF COL_LENGTH(N'product.ProductImages', N'ThumbnailStoragePath') IS NULL
        ALTER TABLE [product].[ProductImages] ADD [ThumbnailStoragePath] NVARCHAR(1000) NULL;
    IF COL_LENGTH(N'product.ProductImages', N'ThumbnailPublicUrl') IS NULL
        ALTER TABLE [product].[ProductImages] ADD [ThumbnailPublicUrl] NVARCHAR(2000) NULL;
    IF COL_LENGTH(N'product.ProductImages', N'OptimizedStoragePath') IS NULL
        ALTER TABLE [product].[ProductImages] ADD [OptimizedStoragePath] NVARCHAR(1000) NULL;
    IF COL_LENGTH(N'product.ProductImages', N'OptimizedPublicUrl') IS NULL
        ALTER TABLE [product].[ProductImages] ADD [OptimizedPublicUrl] NVARCHAR(2000) NULL;

    IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_product_ProductImages_UploadStatus')
        ALTER TABLE [product].[ProductImages] WITH NOCHECK ADD CONSTRAINT CK_product_ProductImages_UploadStatus CHECK (
            [UploadStatusCode] IN (N'pending_upload', N'uploaded', N'active', N'failed', N'deleted', N'replaced')
        );
    IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_product_ProductImages_ScanStatus')
        ALTER TABLE [product].[ProductImages] WITH NOCHECK ADD CONSTRAINT CK_product_ProductImages_ScanStatus CHECK (
            [ScanStatusCode] IN (N'not_required', N'pending_upload', N'pending_scan', N'clean', N'rejected', N'failed', N'skipped')
        );

    /* ---------- ProductDocuments ---------- */
    IF COL_LENGTH(N'product.ProductDocuments', N'UploadStatusCode') IS NULL
        ALTER TABLE [product].[ProductDocuments] ADD [UploadStatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_ProductDocuments_UploadStatus DEFAULT (N'active');
    IF COL_LENGTH(N'product.ProductDocuments', N'ScanStatusCode') IS NULL
        ALTER TABLE [product].[ProductDocuments] ADD [ScanStatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_ProductDocuments_ScanStatus DEFAULT (N'not_required');
    IF COL_LENGTH(N'product.ProductDocuments', N'OriginalFileName') IS NULL
        ALTER TABLE [product].[ProductDocuments] ADD [OriginalFileName] NVARCHAR(255) NULL;
    IF COL_LENGTH(N'product.ProductDocuments', N'ETag') IS NULL
        ALTER TABLE [product].[ProductDocuments] ADD [ETag] NVARCHAR(200) NULL;
    IF COL_LENGTH(N'product.ProductDocuments', N'UploadExpiresAtUtc') IS NULL
        ALTER TABLE [product].[ProductDocuments] ADD [UploadExpiresAtUtc] DATETIME2(7) NULL;
    IF COL_LENGTH(N'product.ProductDocuments', N'UploadCompletedAtUtc') IS NULL
        ALTER TABLE [product].[ProductDocuments] ADD [UploadCompletedAtUtc] DATETIME2(7) NULL;
    IF COL_LENGTH(N'product.ProductDocuments', N'ContentType') IS NULL
        ALTER TABLE [product].[ProductDocuments] ADD [ContentType] NVARCHAR(150) NULL;
    IF COL_LENGTH(N'product.ProductDocuments', N'FileExtension') IS NULL
        ALTER TABLE [product].[ProductDocuments] ADD [FileExtension] NVARCHAR(20) NULL;
    IF COL_LENGTH(N'product.ProductDocuments', N'ByteSize') IS NULL
        ALTER TABLE [product].[ProductDocuments] ADD [ByteSize] BIGINT NULL;
    IF COL_LENGTH(N'product.ProductDocuments', N'ChecksumSha256') IS NULL
        ALTER TABLE [product].[ProductDocuments] ADD [ChecksumSha256] NVARCHAR(64) NULL;

    IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_product_ProductDocuments_UploadStatus')
        ALTER TABLE [product].[ProductDocuments] WITH NOCHECK ADD CONSTRAINT CK_product_ProductDocuments_UploadStatus CHECK (
            [UploadStatusCode] IN (N'pending_upload', N'uploaded', N'active', N'failed', N'deleted', N'replaced')
        );
    IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_product_ProductDocuments_ScanStatus')
        ALTER TABLE [product].[ProductDocuments] WITH NOCHECK ADD CONSTRAINT CK_product_ProductDocuments_ScanStatus CHECK (
            [ScanStatusCode] IN (N'not_required', N'pending_upload', N'pending_scan', N'clean', N'rejected', N'failed', N'skipped')
        );

    /* ---------- Product360Frames ---------- */
    IF COL_LENGTH(N'product.Product360Frames', N'UploadStatusCode') IS NULL
        ALTER TABLE [product].[Product360Frames] ADD [UploadStatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_Product360Frames_UploadStatus DEFAULT (N'active');
    IF COL_LENGTH(N'product.Product360Frames', N'ScanStatusCode') IS NULL
        ALTER TABLE [product].[Product360Frames] ADD [ScanStatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_Product360Frames_ScanStatus DEFAULT (N'not_required');
    IF COL_LENGTH(N'product.Product360Frames', N'OriginalFileName') IS NULL
        ALTER TABLE [product].[Product360Frames] ADD [OriginalFileName] NVARCHAR(255) NULL;
    IF COL_LENGTH(N'product.Product360Frames', N'ETag') IS NULL
        ALTER TABLE [product].[Product360Frames] ADD [ETag] NVARCHAR(200) NULL;
    IF COL_LENGTH(N'product.Product360Frames', N'UploadExpiresAtUtc') IS NULL
        ALTER TABLE [product].[Product360Frames] ADD [UploadExpiresAtUtc] DATETIME2(7) NULL;
    IF COL_LENGTH(N'product.Product360Frames', N'UploadCompletedAtUtc') IS NULL
        ALTER TABLE [product].[Product360Frames] ADD [UploadCompletedAtUtc] DATETIME2(7) NULL;

    /* ---------- ProductARModels ---------- */
    IF COL_LENGTH(N'product.ProductARModels', N'UploadStatusCode') IS NULL
        ALTER TABLE [product].[ProductARModels] ADD [UploadStatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_ProductARModels_UploadStatus DEFAULT (N'active');
    IF COL_LENGTH(N'product.ProductARModels', N'ScanStatusCode') IS NULL
        ALTER TABLE [product].[ProductARModels] ADD [ScanStatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_ProductARModels_ScanStatus DEFAULT (N'not_required');
    IF COL_LENGTH(N'product.ProductARModels', N'OriginalFileName') IS NULL
        ALTER TABLE [product].[ProductARModels] ADD [OriginalFileName] NVARCHAR(255) NULL;
    IF COL_LENGTH(N'product.ProductARModels', N'ETag') IS NULL
        ALTER TABLE [product].[ProductARModels] ADD [ETag] NVARCHAR(200) NULL;
    IF COL_LENGTH(N'product.ProductARModels', N'UploadExpiresAtUtc') IS NULL
        ALTER TABLE [product].[ProductARModels] ADD [UploadExpiresAtUtc] DATETIME2(7) NULL;
    IF COL_LENGTH(N'product.ProductARModels', N'UploadCompletedAtUtc') IS NULL
        ALTER TABLE [product].[ProductARModels] ADD [UploadCompletedAtUtc] DATETIME2(7) NULL;
    IF COL_LENGTH(N'product.ProductARModels', N'ModelVersionLabel') IS NULL
        ALTER TABLE [product].[ProductARModels] ADD [ModelVersionLabel] NVARCHAR(50) NULL;
    IF COL_LENGTH(N'product.ProductARModels', N'PreviewImagePublicUrl') IS NULL
        ALTER TABLE [product].[ProductARModels] ADD [PreviewImagePublicUrl] NVARCHAR(2000) NULL;
    IF COL_LENGTH(N'product.ProductARModels', N'PlatformCompatibilityCode') IS NULL
        ALTER TABLE [product].[ProductARModels] ADD [PlatformCompatibilityCode] NVARCHAR(50) NULL;
    IF COL_LENGTH(N'product.ProductARModels', N'AccessCode') IS NULL
        ALTER TABLE [product].[ProductARModels] ADD [AccessCode] NVARCHAR(20) NOT NULL
            CONSTRAINT DF_product_ProductARModels_Access DEFAULT (N'public');

    /* ---------- Product360Sets activation ---------- */
    IF COL_LENGTH(N'product.Product360Sets', N'ActivationStatusCode') IS NULL
        ALTER TABLE [product].[Product360Sets] ADD [ActivationStatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_Product360Sets_Activation DEFAULT (N'draft');
    IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = N'CK_product_Product360Sets_Activation')
        ALTER TABLE [product].[Product360Sets] WITH NOCHECK ADD CONSTRAINT CK_product_Product360Sets_Activation CHECK (
            [ActivationStatusCode] IN (N'draft', N'incomplete', N'active', N'archived')
        );

    /* ---------- Upload session ledger (cleanup) ---------- */
    IF OBJECT_ID(N'[product].[ProductFileUploadSessions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductFileUploadSessions] (
            [ProductFileUploadSessionId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_FileUploadSessions_PublicId DEFAULT (NEWSEQUENTIALID()),
            [ProductId] BIGINT NOT NULL,
            [EntityTypeCode] NVARCHAR(40) NOT NULL,
            [EntityPublicId] UNIQUEIDENTIFIER NOT NULL,
            [FileKindCode] NVARCHAR(30) NOT NULL,
            [StorageContainer] NVARCHAR(200) NOT NULL,
            [StoragePath] NVARCHAR(1000) NOT NULL,
            [OriginalFileName] NVARCHAR(255) NOT NULL,
            [ContentType] NVARCHAR(150) NOT NULL,
            [DeclaredByteSize] BIGINT NOT NULL,
            [UploadStatusCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_FileUploadSessions_Status DEFAULT (N'pending_upload'),
            [ExpiresAtUtc] DATETIME2(7) NOT NULL,
            [CompletedAtUtc] DATETIME2(7) NULL,
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_FileUploadSessions_Created DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_FileUploadSessions_Updated DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            CONSTRAINT PK_product_ProductFileUploadSessions PRIMARY KEY CLUSTERED ([ProductFileUploadSessionId]),
            CONSTRAINT UQ_product_ProductFileUploadSessions_PublicId UNIQUE ([PublicId]),
            CONSTRAINT CK_product_FileUploadSessions_Kind CHECK (
                [FileKindCode] IN (N'image', N'document', N'cad', N'ar', N'360-frame')
            ),
            CONSTRAINT CK_product_FileUploadSessions_Status CHECK (
                [UploadStatusCode] IN (N'pending_upload', N'uploaded', N'expired', N'failed', N'cleaned')
            ),
            CONSTRAINT FK_product_FileUploadSessions_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
            CONSTRAINT FK_product_FileUploadSessions_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_FileUploadSessions_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId])
        );

        CREATE NONCLUSTERED INDEX IX_product_FileUploadSessions_Expires
            ON [product].[ProductFileUploadSessions] ([ExpiresAtUtc], [UploadStatusCode]);
    END;

    COMMIT TRANSACTION;
    PRINT N'Phase 9 product file upload schema applied.';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
