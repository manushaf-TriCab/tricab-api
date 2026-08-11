/*
  Customer Catalogue Navigation (separate from Product Family/Category/Series).

  Tables:
    [product].[CatalogueTabs]
    [product].[CatalogueNodes]          -- adjacency-list hierarchy
    [product].[CatalogueNodeProducts]   -- many-to-many product placement
    [product].[CatalogueNodeRegions]    -- optional region visibility

  Safe to re-run (IF OBJECT_ID / IF NOT EXISTS guards).
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

/* ------------------------------------------------------------------
   [product].[CatalogueTabs]
   ------------------------------------------------------------------ */
IF OBJECT_ID(N'[product].[CatalogueTabs]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[CatalogueTabs] (
        [CatalogueTabId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_CatalogueTabs_PublicId DEFAULT (NEWSEQUENTIALID()),
        [TabCode] NVARCHAR(50) NOT NULL,
        [TabName] NVARCHAR(200) NOT NULL,
        [Description] NVARCHAR(2000) NULL,
        [Slug] NVARCHAR(120) NOT NULL,
        [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_CatalogueTabs_DisplayOrder DEFAULT (0),
        [IsActive] BIT NOT NULL CONSTRAINT DF_product_CatalogueTabs_IsActive DEFAULT (1),
        [IsPublic] BIT NOT NULL CONSTRAINT DF_product_CatalogueTabs_IsPublic DEFAULT (1),
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CatalogueTabs_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CatalogueTabs_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [UpdatedByUserId] BIGINT NULL,
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT PK_product_CatalogueTabs PRIMARY KEY CLUSTERED ([CatalogueTabId]),
        CONSTRAINT UQ_product_CatalogueTabs_PublicId UNIQUE ([PublicId]),
        CONSTRAINT UQ_product_CatalogueTabs_TabCode UNIQUE ([TabCode]),
        CONSTRAINT UQ_product_CatalogueTabs_Slug UNIQUE ([Slug]),
        CONSTRAINT CK_product_CatalogueTabs_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([TabName]))) > 0),
        CONSTRAINT FK_product_CatalogueTabs_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_CatalogueTabs_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_CatalogueTabs_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[CatalogueTabs]')
      AND name = N'IX_product_CatalogueTabs_IsActive_DisplayOrder'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_CatalogueTabs_IsActive_DisplayOrder
        ON [product].[CatalogueTabs] ([IsActive], [DisplayOrder])
        WHERE [DeletedAtUtc] IS NULL;
END;

/* ------------------------------------------------------------------
   [product].[CatalogueNodes]
   ------------------------------------------------------------------ */
IF OBJECT_ID(N'[product].[CatalogueNodes]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[CatalogueNodes] (
        [CatalogueNodeId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_CatalogueNodes_PublicId DEFAULT (NEWSEQUENTIALID()),
        [CatalogueTabId] BIGINT NOT NULL,
        [ParentNodeId] BIGINT NULL,
        [NodeCode] NVARCHAR(80) NOT NULL,
        [NodeName] NVARCHAR(200) NOT NULL,
        [Slug] NVARCHAR(160) NOT NULL,
        [Description] NVARCHAR(2000) NULL,
        [NodeTypeCode] NVARCHAR(40) NOT NULL CONSTRAINT DF_product_CatalogueNodes_NodeType DEFAULT (N'category'),
        [ImageUrl] NVARCHAR(1000) NULL,
        [ImageAltText] NVARCHAR(300) NULL,
        [ImageMediaPublicId] UNIQUEIDENTIFIER NULL,
        [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_CatalogueNodes_DisplayOrder DEFAULT (0),
        [IsActive] BIT NOT NULL CONSTRAINT DF_product_CatalogueNodes_IsActive DEFAULT (1),
        [IsPublic] BIT NOT NULL CONSTRAINT DF_product_CatalogueNodes_IsPublic DEFAULT (1),
        [SeoTitle] NVARCHAR(300) NULL,
        [SeoDescription] NVARCHAR(1000) NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CatalogueNodes_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CatalogueNodes_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [UpdatedByUserId] BIGINT NULL,
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT PK_product_CatalogueNodes PRIMARY KEY CLUSTERED ([CatalogueNodeId]),
        CONSTRAINT UQ_product_CatalogueNodes_PublicId UNIQUE ([PublicId]),
        CONSTRAINT UQ_product_CatalogueNodes_Tab_Code UNIQUE ([CatalogueTabId], [NodeCode]),
        CONSTRAINT CK_product_CatalogueNodes_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([NodeName]))) > 0),
        CONSTRAINT CK_product_CatalogueNodes_NotSelfParent CHECK ([ParentNodeId] IS NULL OR [ParentNodeId] <> [CatalogueNodeId]),
        CONSTRAINT FK_product_CatalogueNodes_Tab FOREIGN KEY ([CatalogueTabId]) REFERENCES [product].[CatalogueTabs] ([CatalogueTabId]),
        CONSTRAINT FK_product_CatalogueNodes_Parent FOREIGN KEY ([ParentNodeId]) REFERENCES [product].[CatalogueNodes] ([CatalogueNodeId]),
        CONSTRAINT FK_product_CatalogueNodes_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_CatalogueNodes_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_CatalogueNodes_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[CatalogueNodes]')
      AND name = N'IX_product_CatalogueNodes_Tab_Parent_DisplayOrder'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_CatalogueNodes_Tab_Parent_DisplayOrder
        ON [product].[CatalogueNodes] ([CatalogueTabId], [ParentNodeId], [DisplayOrder])
        WHERE [DeletedAtUtc] IS NULL;
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[CatalogueNodes]')
      AND name = N'UQ_product_CatalogueNodes_Tab_Parent_Slug'
)
BEGIN
    -- Sibling slug uniqueness (NULL parent treated as sentinel via filtered unique index + computed helper).
    CREATE UNIQUE NONCLUSTERED INDEX UQ_product_CatalogueNodes_Tab_Parent_Slug
        ON [product].[CatalogueNodes] ([CatalogueTabId], [ParentNodeId], [Slug])
        WHERE [DeletedAtUtc] IS NULL AND [ParentNodeId] IS NOT NULL;
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[CatalogueNodes]')
      AND name = N'UQ_product_CatalogueNodes_Tab_Root_Slug'
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_product_CatalogueNodes_Tab_Root_Slug
        ON [product].[CatalogueNodes] ([CatalogueTabId], [Slug])
        WHERE [DeletedAtUtc] IS NULL AND [ParentNodeId] IS NULL;
END;

/* ------------------------------------------------------------------
   [product].[CatalogueNodeProducts]
   ------------------------------------------------------------------ */
IF OBJECT_ID(N'[product].[CatalogueNodeProducts]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[CatalogueNodeProducts] (
        [CatalogueNodeProductId] BIGINT IDENTITY(1,1) NOT NULL,
        [CatalogueNodeId] BIGINT NOT NULL,
        [ProductId] BIGINT NOT NULL,
        [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_CatalogueNodeProducts_DisplayOrder DEFAULT (0),
        [IsFeatured] BIT NOT NULL CONSTRAINT DF_product_CatalogueNodeProducts_IsFeatured DEFAULT (0),
        [IsActive] BIT NOT NULL CONSTRAINT DF_product_CatalogueNodeProducts_IsActive DEFAULT (1),
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CatalogueNodeProducts_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CatalogueNodeProducts_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [UpdatedByUserId] BIGINT NULL,
        CONSTRAINT PK_product_CatalogueNodeProducts PRIMARY KEY CLUSTERED ([CatalogueNodeProductId]),
        CONSTRAINT UQ_product_CatalogueNodeProducts_Node_Product UNIQUE ([CatalogueNodeId], [ProductId]),
        CONSTRAINT FK_product_CatalogueNodeProducts_Node FOREIGN KEY ([CatalogueNodeId]) REFERENCES [product].[CatalogueNodes] ([CatalogueNodeId]),
        CONSTRAINT FK_product_CatalogueNodeProducts_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
        CONSTRAINT FK_product_CatalogueNodeProducts_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT FK_product_CatalogueNodeProducts_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[CatalogueNodeProducts]')
      AND name = N'IX_product_CatalogueNodeProducts_ProductId'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_CatalogueNodeProducts_ProductId
        ON [product].[CatalogueNodeProducts] ([ProductId], [IsActive], [DisplayOrder]);
END;

/* ------------------------------------------------------------------
   [product].[CatalogueNodeRegions]
   ------------------------------------------------------------------ */
IF OBJECT_ID(N'[product].[CatalogueNodeRegions]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[CatalogueNodeRegions] (
        [CatalogueNodeRegionId] BIGINT IDENTITY(1,1) NOT NULL,
        [CatalogueNodeId] BIGINT NOT NULL,
        [RegionId] BIGINT NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CatalogueNodeRegions_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        CONSTRAINT PK_product_CatalogueNodeRegions PRIMARY KEY CLUSTERED ([CatalogueNodeRegionId]),
        CONSTRAINT UQ_product_CatalogueNodeRegions_Node_Region UNIQUE ([CatalogueNodeId], [RegionId]),
        CONSTRAINT FK_product_CatalogueNodeRegions_Node FOREIGN KEY ([CatalogueNodeId]) REFERENCES [product].[CatalogueNodes] ([CatalogueNodeId]),
        CONSTRAINT FK_product_CatalogueNodeRegions_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
        CONSTRAINT FK_product_CatalogueNodeRegions_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );
END;

COMMIT TRANSACTION;
PRINT N'Customer catalogue navigation schema ready.';
