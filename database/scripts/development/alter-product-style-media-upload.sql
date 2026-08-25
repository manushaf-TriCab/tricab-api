/*
================================================================================
  Additive development migration: style-owned media upload session columns.

  Adds pending-upload tracking to ProductStyleMediaAssets so style media can
  reuse the existing Product Blob/SAS initiate → HEAD → complete pipeline.

  Split into GO batches: SQL Server cannot compile a CHECK on a column that is
  added in the same batch.

  DEVELOPMENT / additive only — does not modify WordPress or tc MySQL.
================================================================================
*/

SET NOCOUNT ON;

IF OBJECT_ID(N'[product].[ProductStyleMediaAssets]', N'U') IS NULL
BEGIN
    THROW 50010, 'ProductStyleMediaAssets does not exist. Apply create-product-style-hierarchy-schema.sql first.', 1;
END;

IF COL_LENGTH(N'[product].[ProductStyleMediaAssets]', N'UploadStatusCode') IS NULL
BEGIN
    ALTER TABLE [product].[ProductStyleMediaAssets]
        ADD [UploadStatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_ProductStyleMediaAssets_UploadStatus DEFAULT (N'active');
END;

IF COL_LENGTH(N'[product].[ProductStyleMediaAssets]', N'ByteSize') IS NULL
BEGIN
    ALTER TABLE [product].[ProductStyleMediaAssets]
        ADD [ByteSize] BIGINT NULL;
END;

IF COL_LENGTH(N'[product].[ProductStyleMediaAssets]', N'OriginalFileName') IS NULL
BEGIN
    ALTER TABLE [product].[ProductStyleMediaAssets]
        ADD [OriginalFileName] NVARCHAR(255) NULL;
END;

GO

SET NOCOUNT ON;

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = N'CK_product_ProductStyleMediaAssets_UploadStatus'
      AND parent_object_id = OBJECT_ID(N'[product].[ProductStyleMediaAssets]')
)
BEGIN
    ALTER TABLE [product].[ProductStyleMediaAssets]
        ADD CONSTRAINT CK_product_ProductStyleMediaAssets_UploadStatus CHECK (
            [UploadStatusCode] IN (N'pending_upload', N'uploaded', N'active', N'failed', N'deleted', N'replaced')
        );
END;

GO

SET NOCOUNT ON;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_product_ProductStyleMediaAssets_StoragePath'
      AND object_id = OBJECT_ID(N'[product].[ProductStyleMediaAssets]')
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_ProductStyleMediaAssets_StoragePath
        ON [product].[ProductStyleMediaAssets] ([StoragePath], [StorageContainer])
        WHERE [DeletedAtUtc] IS NULL AND [StoragePath] IS NOT NULL;
END;
