/*
  Proposed additive CatalogueNode → ProductStyle link table.
  NOT APPLIED in dry-run phase. Operator approval required before apply.

  Safe to re-run (IF OBJECT_ID / IF NOT EXISTS guards).
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF OBJECT_ID(N'[product].[CatalogueNodeProductStyles]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[CatalogueNodeProductStyles] (
        [CatalogueNodeProductStyleId] BIGINT IDENTITY(1,1) NOT NULL,
        [CatalogueNodeId] BIGINT NOT NULL,
        [ProductStyleId] BIGINT NOT NULL,
        [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_CatalogueNodeProductStyles_DisplayOrder DEFAULT (0),
        [IsActive] BIT NOT NULL CONSTRAINT DF_product_CatalogueNodeProductStyles_IsActive DEFAULT (1),
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CatalogueNodeProductStyles_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CatalogueNodeProductStyles_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [UpdatedByUserId] BIGINT NULL,
        CONSTRAINT PK_product_CatalogueNodeProductStyles PRIMARY KEY CLUSTERED ([CatalogueNodeProductStyleId]),
        CONSTRAINT UQ_product_CatalogueNodeProductStyles_Node_Style UNIQUE ([CatalogueNodeId], [ProductStyleId]),
        CONSTRAINT FK_product_CatalogueNodeProductStyles_Node FOREIGN KEY ([CatalogueNodeId]) REFERENCES [product].[CatalogueNodes] ([CatalogueNodeId]),
        CONSTRAINT FK_product_CatalogueNodeProductStyles_Style FOREIGN KEY ([ProductStyleId]) REFERENCES [product].[ProductStyles] ([ProductStyleId]),
        CONSTRAINT FK_product_CatalogueNodeProductStyles_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_CatalogueNodeProductStyles_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[CatalogueNodeProductStyles]')
      AND name = N'IX_product_CatalogueNodeProductStyles_Node_DisplayOrder'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_CatalogueNodeProductStyles_Node_DisplayOrder
        ON [product].[CatalogueNodeProductStyles] ([CatalogueNodeId], [DisplayOrder])
        WHERE [IsActive] = 1;
END;

COMMIT TRANSACTION;
