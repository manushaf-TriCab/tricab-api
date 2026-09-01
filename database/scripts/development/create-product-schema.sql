/*
================================================================================
  Additive development migration: Product Module foundation (Phase 1).

  Creates [product] schema and Product Module tables for:
    hierarchy, statuses, specifications, media metadata, markets,
    relationships, pricing foundation, SEO/content, integration readiness,
    product audit, and migration.LegacyProductMappings.

  Conventions:
    - BIGINT IDENTITY clustered PKs + PublicId UNIQUEIDENTIFIER (NEWSEQUENTIALID)
    - IsActive + soft-delete (DeletedAtUtc / DeletedByUserId)
    - Audit: CreatedAtUtc / CreatedByUserId / UpdatedAtUtc / UpdatedByUserId
    - Optimistic concurrency: RowVersion
    - Media/documents store Azure Blob metadata only (no binaries)
    - Hierarchy levels on Products are optional (nullable FKs)
    - Typed specification value columns (no JSON primary storage)
    - Azure SQL-compatible, idempotent guards

  NOTE: No approved ERD file was present in-repo. This schema is derived from
  the Phase 1 Product Module prompt and aligned to existing Customer/Identity
  conventions. Review before production deploy.

  DEVELOPMENT / additive only — does not modify legacy MySQL product data.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'product')
    BEGIN
        EXEC(N'CREATE SCHEMA [product]');
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'migration')
    BEGIN
        EXEC(N'CREATE SCHEMA [migration]');
    END;


    /* ------------------------------------------------------------------
       [product].[ProductStatuses]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductStatuses]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductStatuses] (
    [ProductStatusId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductStatuses_PublicId DEFAULT (NEWSEQUENTIALID()),
    [StatusCode] NVARCHAR(50) NOT NULL,
    [StatusName] NVARCHAR(100) NOT NULL,
    [Description] NVARCHAR(500) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductStatuses_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductStatuses_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStatuses_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStatuses_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductStatuses PRIMARY KEY CLUSTERED ([ProductStatusId]),
    CONSTRAINT UQ_product_ProductStatuses_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductStatuses_StatusCode UNIQUE ([StatusCode]),
    CONSTRAINT CK_product_ProductStatuses_StatusCode_NotEmpty CHECK (LEN(LTRIM(RTRIM([StatusCode]))) > 0),
    CONSTRAINT CK_product_ProductStatuses_StatusName_NotEmpty CHECK (LEN(LTRIM(RTRIM([StatusName]))) > 0),
    CONSTRAINT FK_product_ProductStatuses_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductStatuses_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductStatuses_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductLifecycleStatuses]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductLifecycleStatuses]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductLifecycleStatuses] (
    [ProductLifecycleStatusId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductLifecycleStatuses_PublicId DEFAULT (NEWSEQUENTIALID()),
    [LifecycleStatusCode] NVARCHAR(50) NOT NULL,
    [LifecycleStatusName] NVARCHAR(100) NOT NULL,
    [Description] NVARCHAR(500) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductLifecycleStatuses_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductLifecycleStatuses_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductLifecycleStatuses_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductLifecycleStatuses_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductLifecycleStatuses PRIMARY KEY CLUSTERED ([ProductLifecycleStatusId]),
    CONSTRAINT UQ_product_ProductLifecycleStatuses_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductLifecycleStatuses_LifecycleStatusCode UNIQUE ([LifecycleStatusCode]),
    CONSTRAINT CK_product_ProductLifecycleStatuses_Code_NotEmpty CHECK (LEN(LTRIM(RTRIM([LifecycleStatusCode]))) > 0),
    CONSTRAINT CK_product_ProductLifecycleStatuses_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([LifecycleStatusName]))) > 0),
    CONSTRAINT FK_product_ProductLifecycleStatuses_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductLifecycleStatuses_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductLifecycleStatuses_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[MediaTypes]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[MediaTypes]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[MediaTypes] (
    [MediaTypeId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_MediaTypes_PublicId DEFAULT (NEWSEQUENTIALID()),
    [MediaTypeCode] NVARCHAR(50) NOT NULL,
    [MediaTypeName] NVARCHAR(100) NOT NULL,
    [Description] NVARCHAR(500) NULL,
    [AllowedExtensions] NVARCHAR(200) NULL,
    [MaxFileSizeBytes] BIGINT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_MediaTypes_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_MediaTypes_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_MediaTypes_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_MediaTypes_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_MediaTypes PRIMARY KEY CLUSTERED ([MediaTypeId]),
    CONSTRAINT UQ_product_MediaTypes_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_MediaTypes_MediaTypeCode UNIQUE ([MediaTypeCode]),
    CONSTRAINT CK_product_MediaTypes_Code_NotEmpty CHECK (LEN(LTRIM(RTRIM([MediaTypeCode]))) > 0),
    CONSTRAINT FK_product_MediaTypes_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_MediaTypes_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_MediaTypes_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[DocumentTypes]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[DocumentTypes]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[DocumentTypes] (
    [DocumentTypeId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_DocumentTypes_PublicId DEFAULT (NEWSEQUENTIALID()),
    [DocumentTypeCode] NVARCHAR(50) NOT NULL,
    [DocumentTypeName] NVARCHAR(100) NOT NULL,
    [Description] NVARCHAR(500) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_DocumentTypes_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_DocumentTypes_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_DocumentTypes_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_DocumentTypes_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_DocumentTypes PRIMARY KEY CLUSTERED ([DocumentTypeId]),
    CONSTRAINT UQ_product_DocumentTypes_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_DocumentTypes_DocumentTypeCode UNIQUE ([DocumentTypeCode]),
    CONSTRAINT CK_product_DocumentTypes_Code_NotEmpty CHECK (LEN(LTRIM(RTRIM([DocumentTypeCode]))) > 0),
    CONSTRAINT FK_product_DocumentTypes_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_DocumentTypes_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_DocumentTypes_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[Currencies]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[Currencies]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[Currencies] (
    [CurrencyId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Currencies_PublicId DEFAULT (NEWSEQUENTIALID()),
    [CurrencyCode] NVARCHAR(3) NOT NULL,
    [CurrencyName] NVARCHAR(100) NOT NULL,
    [Symbol] NVARCHAR(10) NULL,
    [DecimalPlaces] TINYINT NOT NULL CONSTRAINT DF_product_Currencies_DecimalPlaces DEFAULT (2),
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_Currencies_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_Currencies_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Currencies_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Currencies_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_Currencies PRIMARY KEY CLUSTERED ([CurrencyId]),
    CONSTRAINT UQ_product_Currencies_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_Currencies_CurrencyCode UNIQUE ([CurrencyCode]),
    CONSTRAINT CK_product_Currencies_Code_Len CHECK (LEN([CurrencyCode]) = 3),
    CONSTRAINT CK_product_Currencies_DecimalPlaces CHECK ([DecimalPlaces] BETWEEN 0 AND 6),
    CONSTRAINT FK_product_Currencies_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Currencies_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Currencies_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductFamilies]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductFamilies]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductFamilies] (
    [ProductFamilyId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductFamilies_PublicId DEFAULT (NEWSEQUENTIALID()),
    [FamilyCode] NVARCHAR(50) NOT NULL,
    [FamilyName] NVARCHAR(200) NOT NULL,
    [Description] NVARCHAR(2000) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductFamilies_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductFamilies_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductFamilies_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductFamilies_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductFamilies PRIMARY KEY CLUSTERED ([ProductFamilyId]),
    CONSTRAINT UQ_product_ProductFamilies_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductFamilies_FamilyCode UNIQUE ([FamilyCode]),
    CONSTRAINT CK_product_ProductFamilies_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([FamilyName]))) > 0),
    CONSTRAINT FK_product_ProductFamilies_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductFamilies_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductFamilies_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductFamilies]') AND name = N'IX_product_ProductFamilies_IsActive_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductFamilies_IsActive_DisplayOrder ON [product].[ProductFamilies] ([IsActive], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductCategories]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductCategories]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductCategories] (
    [ProductCategoryId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductCategories_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductFamilyId] BIGINT NULL,
    [CategoryCode] NVARCHAR(50) NOT NULL,
    [CategoryName] NVARCHAR(200) NOT NULL,
    [Description] NVARCHAR(2000) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductCategories_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductCategories_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCategories_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCategories_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductCategories PRIMARY KEY CLUSTERED ([ProductCategoryId]),
    CONSTRAINT UQ_product_ProductCategories_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductCategories_CategoryCode UNIQUE ([CategoryCode]),
    CONSTRAINT CK_product_ProductCategories_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([CategoryName]))) > 0),
    CONSTRAINT FK_product_ProductCategories_Family FOREIGN KEY ([ProductFamilyId]) REFERENCES [product].[ProductFamilies] ([ProductFamilyId]),
    CONSTRAINT FK_product_ProductCategories_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCategories_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCategories_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductCategories]') AND name = N'IX_product_ProductCategories_FamilyId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductCategories_FamilyId ON [product].[ProductCategories] ([ProductFamilyId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductCategories]') AND name = N'IX_product_ProductCategories_IsActive_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductCategories_IsActive_DisplayOrder ON [product].[ProductCategories] ([IsActive], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductSeries]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductSeries]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductSeries] (
    [ProductSeriesId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductSeries_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductCategoryId] BIGINT NULL,
    [SeriesCode] NVARCHAR(50) NOT NULL,
    [SeriesName] NVARCHAR(200) NOT NULL,
    [Description] NVARCHAR(2000) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductSeries_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductSeries_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductSeries_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductSeries_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductSeries PRIMARY KEY CLUSTERED ([ProductSeriesId]),
    CONSTRAINT UQ_product_ProductSeries_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductSeries_SeriesCode UNIQUE ([SeriesCode]),
    CONSTRAINT CK_product_ProductSeries_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([SeriesName]))) > 0),
    CONSTRAINT FK_product_ProductSeries_Category FOREIGN KEY ([ProductCategoryId]) REFERENCES [product].[ProductCategories] ([ProductCategoryId]),
    CONSTRAINT FK_product_ProductSeries_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductSeries_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductSeries_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductSeries]') AND name = N'IX_product_ProductSeries_CategoryId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductSeries_CategoryId ON [product].[ProductSeries] ([ProductCategoryId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductSeries]') AND name = N'IX_product_ProductSeries_IsActive_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductSeries_IsActive_DisplayOrder ON [product].[ProductSeries] ([IsActive], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[Products]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[Products]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[Products] (
    [ProductId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Products_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductFamilyId] BIGINT NULL,
    [ProductCategoryId] BIGINT NULL,
    [ProductSeriesId] BIGINT NULL,
    [ProductStatusId] BIGINT NOT NULL,
    [ProductLifecycleStatusId] BIGINT NOT NULL,
    [ProductName] NVARCHAR(200) NOT NULL,
    [InternalName] NVARCHAR(200) NULL,
    [InternalCode] NVARCHAR(100) NULL,
    [ShortDescription] NVARCHAR(500) NULL,
    [FullDescription] NVARCHAR(MAX) NULL,
    [LegacyProductId] NVARCHAR(100) NULL,
    [ErpReference] NVARCHAR(100) NULL,
    [IsFeatured] BIT NOT NULL CONSTRAINT DF_product_Products_IsFeatured DEFAULT (0),
    [PublishAtUtc] DATETIME2(7) NULL,
    [UnpublishAtUtc] DATETIME2(7) NULL,
    [DiscontinueAtUtc] DATETIME2(7) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_Products_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Products_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Products_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_Products PRIMARY KEY CLUSTERED ([ProductId]),
    CONSTRAINT UQ_product_Products_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_Products_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([ProductName]))) > 0),
    CONSTRAINT CK_product_Products_PublishWindow CHECK (
        [UnpublishAtUtc] IS NULL OR [PublishAtUtc] IS NULL OR [UnpublishAtUtc] >= [PublishAtUtc]
    ),
    CONSTRAINT FK_product_Products_Family FOREIGN KEY ([ProductFamilyId]) REFERENCES [product].[ProductFamilies] ([ProductFamilyId]),
    CONSTRAINT FK_product_Products_Category FOREIGN KEY ([ProductCategoryId]) REFERENCES [product].[ProductCategories] ([ProductCategoryId]),
    CONSTRAINT FK_product_Products_Series FOREIGN KEY ([ProductSeriesId]) REFERENCES [product].[ProductSeries] ([ProductSeriesId]),
    CONSTRAINT FK_product_Products_Status FOREIGN KEY ([ProductStatusId]) REFERENCES [product].[ProductStatuses] ([ProductStatusId]),
    CONSTRAINT FK_product_Products_Lifecycle FOREIGN KEY ([ProductLifecycleStatusId]) REFERENCES [product].[ProductLifecycleStatuses] ([ProductLifecycleStatusId]),
    CONSTRAINT FK_product_Products_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Products_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Products_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[Products]') AND name = N'UX_product_Products_InternalCode_NotDeleted')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_product_Products_InternalCode_NotDeleted ON [product].[Products] ([InternalCode]) WHERE [InternalCode] IS NOT NULL AND [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[Products]') AND name = N'UX_product_Products_LegacyProductId_NotNull')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_product_Products_LegacyProductId_NotNull ON [product].[Products] ([LegacyProductId]) WHERE [LegacyProductId] IS NOT NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[Products]') AND name = N'IX_product_Products_Name')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_Products_Name ON [product].[Products] ([ProductName]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[Products]') AND name = N'IX_product_Products_Status_Lifecycle')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_Products_Status_Lifecycle ON [product].[Products] ([ProductStatusId], [ProductLifecycleStatusId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[Products]') AND name = N'IX_product_Products_Family_Category_Series')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_Products_Family_Category_Series ON [product].[Products] ([ProductFamilyId], [ProductCategoryId], [ProductSeriesId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[Products]') AND name = N'IX_product_Products_UpdatedAtUtc')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_Products_UpdatedAtUtc ON [product].[Products] ([UpdatedAtUtc] DESC) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductVariants]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductVariants]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductVariants] (
    [ProductVariantId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductVariants_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [ProductStatusId] BIGINT NOT NULL,
    [VariantName] NVARCHAR(200) NOT NULL,
    [Sku] NVARCHAR(100) NULL,
    [VariantCode] NVARCHAR(100) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductVariants_DisplayOrder DEFAULT (0),
    [MinimumOrderQuantity] DECIMAL(18,4) NULL,
    [WeightKg] DECIMAL(18,6) NULL,
    [LengthMm] DECIMAL(18,4) NULL,
    [WidthMm] DECIMAL(18,4) NULL,
    [HeightMm] DECIMAL(18,4) NULL,
    [PackagingDescription] NVARCHAR(500) NULL,
    [ErpReference] NVARCHAR(100) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductVariants_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductVariants_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductVariants_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductVariants PRIMARY KEY CLUSTERED ([ProductVariantId]),
    CONSTRAINT UQ_product_ProductVariants_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductVariants_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([VariantName]))) > 0),
    CONSTRAINT CK_product_ProductVariants_Moq CHECK ([MinimumOrderQuantity] IS NULL OR [MinimumOrderQuantity] > 0),
    CONSTRAINT FK_product_ProductVariants_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductVariants_Status FOREIGN KEY ([ProductStatusId]) REFERENCES [product].[ProductStatuses] ([ProductStatusId]),
    CONSTRAINT FK_product_ProductVariants_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductVariants_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductVariants_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductVariants]') AND name = N'UX_product_ProductVariants_Sku_NotDeleted')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_product_ProductVariants_Sku_NotDeleted ON [product].[ProductVariants] ([Sku]) WHERE [Sku] IS NOT NULL AND [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductVariants]') AND name = N'UX_product_ProductVariants_VariantCode_NotDeleted')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_product_ProductVariants_VariantCode_NotDeleted ON [product].[ProductVariants] ([VariantCode]) WHERE [VariantCode] IS NOT NULL AND [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductVariants]') AND name = N'IX_product_ProductVariants_ProductId_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductVariants_ProductId_DisplayOrder ON [product].[ProductVariants] ([ProductId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductCodes]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductCodes]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductCodes] (
    [ProductCodeId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductCodes_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NULL,
    [ProductVariantId] BIGINT NULL,
    [CodeValue] NVARCHAR(200) NOT NULL,
    [CodeExplanation] NVARCHAR(1000) NULL,
    [IsPrimary] BIT NOT NULL CONSTRAINT DF_product_ProductCodes_IsPrimary DEFAULT (1),
    [SourceRuleSetId] BIGINT NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductCodes_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCodes_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCodes_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductCodes PRIMARY KEY CLUSTERED ([ProductCodeId]),
    CONSTRAINT UQ_product_ProductCodes_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductCodes_Code_NotEmpty CHECK (LEN(LTRIM(RTRIM([CodeValue]))) > 0),
    CONSTRAINT CK_product_ProductCodes_Owner CHECK (
        ([ProductId] IS NOT NULL AND [ProductVariantId] IS NULL)
        OR ([ProductId] IS NULL AND [ProductVariantId] IS NOT NULL)
        OR ([ProductId] IS NOT NULL AND [ProductVariantId] IS NOT NULL)
    ),
    CONSTRAINT FK_product_ProductCodes_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductCodes_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_ProductCodes_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCodes_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCodes_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductCodes]') AND name = N'UX_product_ProductCodes_CodeValue_NotDeleted')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_product_ProductCodes_CodeValue_NotDeleted ON [product].[ProductCodes] ([CodeValue]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductCodes]') AND name = N'IX_product_ProductCodes_ProductId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductCodes_ProductId ON [product].[ProductCodes] ([ProductId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductCodes]') AND name = N'IX_product_ProductCodes_VariantId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductCodes_VariantId ON [product].[ProductCodes] ([ProductVariantId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductCodeRuleSets]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductCodeRuleSets]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductCodeRuleSets] (
    [ProductCodeRuleSetId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductCodeRuleSets_PublicId DEFAULT (NEWSEQUENTIALID()),
    [RuleSetCode] NVARCHAR(50) NOT NULL,
    [RuleSetName] NVARCHAR(200) NOT NULL,
    [Description] NVARCHAR(1000) NULL,
    [ProductFamilyId] BIGINT NULL,
    [ProductCategoryId] BIGINT NULL,
    [ProductSeriesId] BIGINT NULL,
    [IsDefault] BIT NOT NULL CONSTRAINT DF_product_ProductCodeRuleSets_IsDefault DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductCodeRuleSets_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCodeRuleSets_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCodeRuleSets_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductCodeRuleSets PRIMARY KEY CLUSTERED ([ProductCodeRuleSetId]),
    CONSTRAINT UQ_product_ProductCodeRuleSets_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductCodeRuleSets_RuleSetCode UNIQUE ([RuleSetCode]),
    CONSTRAINT FK_product_ProductCodeRuleSets_Family FOREIGN KEY ([ProductFamilyId]) REFERENCES [product].[ProductFamilies] ([ProductFamilyId]),
    CONSTRAINT FK_product_ProductCodeRuleSets_Category FOREIGN KEY ([ProductCategoryId]) REFERENCES [product].[ProductCategories] ([ProductCategoryId]),
    CONSTRAINT FK_product_ProductCodeRuleSets_Series FOREIGN KEY ([ProductSeriesId]) REFERENCES [product].[ProductSeries] ([ProductSeriesId]),
    CONSTRAINT FK_product_ProductCodeRuleSets_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCodeRuleSets_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCodeRuleSets_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductCodeRuleSegments]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductCodeRuleSegments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductCodeRuleSegments] (
    [ProductCodeRuleSegmentId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductCodeRuleSegments_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductCodeRuleSetId] BIGINT NOT NULL,
    [SegmentCode] NVARCHAR(50) NOT NULL,
    [SegmentName] NVARCHAR(200) NOT NULL,
    [SegmentOrder] INT NOT NULL,
    [SegmentTypeCode] NVARCHAR(30) NOT NULL,
    [LiteralValue] NVARCHAR(100) NULL,
    [SpecificationDefinitionId] BIGINT NULL,
    [IsRequired] BIT NOT NULL CONSTRAINT DF_product_ProductCodeRuleSegments_IsRequired DEFAULT (1),
    [SeparatorAfter] NVARCHAR(10) NULL,
    [PadLength] INT NULL,
    [PadCharacter] NVARCHAR(1) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductCodeRuleSegments_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCodeRuleSegments_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCodeRuleSegments_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductCodeRuleSegments PRIMARY KEY CLUSTERED ([ProductCodeRuleSegmentId]),
    CONSTRAINT UQ_product_ProductCodeRuleSegments_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductCodeRuleSegments_Set_Order UNIQUE ([ProductCodeRuleSetId], [SegmentOrder]),
    CONSTRAINT CK_product_ProductCodeRuleSegments_Type CHECK (
        [SegmentTypeCode] IN (N'literal', N'specification', N'variant-attribute', N'sequence', N'free-text')
    ),
    CONSTRAINT FK_product_ProductCodeRuleSegments_RuleSet FOREIGN KEY ([ProductCodeRuleSetId]) REFERENCES [product].[ProductCodeRuleSets] ([ProductCodeRuleSetId]),
    CONSTRAINT FK_product_ProductCodeRuleSegments_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCodeRuleSegments_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCodeRuleSegments_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    
    /* ------------------------------------------------------------------
       [product].[SpecificationUnits]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[SpecificationUnits]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[SpecificationUnits] (
    [SpecificationUnitId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_SpecificationUnits_PublicId DEFAULT (NEWSEQUENTIALID()),
    [UnitCode] NVARCHAR(50) NOT NULL,
    [UnitName] NVARCHAR(200) NOT NULL,
    [Symbol] NVARCHAR(50) NOT NULL,
    [Description] NVARCHAR(1000) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_SpecificationUnits_DisplayOrder DEFAULT (0),
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_SpecificationUnits_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationUnits_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationUnits_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_SpecificationUnits PRIMARY KEY CLUSTERED ([SpecificationUnitId]),
    CONSTRAINT UQ_product_SpecificationUnits_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_SpecificationUnits_UnitCode UNIQUE ([UnitCode]),
    CONSTRAINT CK_product_SpecificationUnits_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([UnitName]))) > 0),
    CONSTRAINT CK_product_SpecificationUnits_Symbol_NotEmpty CHECK (LEN(LTRIM(RTRIM([Symbol]))) > 0),
    CONSTRAINT FK_product_SpecificationUnits_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_SpecificationUnits_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_SpecificationUnits_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


/* ------------------------------------------------------------------
       [product].[SpecificationGroups]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[SpecificationGroups]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[SpecificationGroups] (
    [SpecificationGroupId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_SpecificationGroups_PublicId DEFAULT (NEWSEQUENTIALID()),
    [GroupCode] NVARCHAR(50) NOT NULL,
    [GroupName] NVARCHAR(200) NOT NULL,
    [Description] NVARCHAR(1000) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_SpecificationGroups_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_SpecificationGroups_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationGroups_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationGroups_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_SpecificationGroups PRIMARY KEY CLUSTERED ([SpecificationGroupId]),
    CONSTRAINT UQ_product_SpecificationGroups_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_SpecificationGroups_GroupCode UNIQUE ([GroupCode]),
    CONSTRAINT CK_product_SpecificationGroups_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([GroupName]))) > 0),
    CONSTRAINT FK_product_SpecificationGroups_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_SpecificationGroups_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_SpecificationGroups_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[SpecificationDefinitions]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[SpecificationDefinitions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[SpecificationDefinitions] (
    [SpecificationDefinitionId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_SpecificationDefinitions_PublicId DEFAULT (NEWSEQUENTIALID()),
    [SpecificationGroupId] BIGINT NULL,
    [DefinitionCode] NVARCHAR(50) NOT NULL,
    [DefinitionName] NVARCHAR(200) NOT NULL,
    [Description] NVARCHAR(1000) NULL,
    [DataTypeCode] NVARCHAR(30) NOT NULL,
    [Unit] NVARCHAR(50) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_SpecificationDefinitions_DisplayOrder DEFAULT (0),
    [IsRequired] BIT NOT NULL CONSTRAINT DF_product_SpecificationDefinitions_IsRequired DEFAULT (0),
    [IsFilterable] BIT NOT NULL CONSTRAINT DF_product_SpecificationDefinitions_IsFilterable DEFAULT (0),
    [IsSearchable] BIT NOT NULL CONSTRAINT DF_product_SpecificationDefinitions_IsSearchable DEFAULT (0),
    [IsComparable] BIT NOT NULL CONSTRAINT DF_product_SpecificationDefinitions_IsComparable DEFAULT (0),
    [ScopeCode] NVARCHAR(20) NOT NULL CONSTRAINT DF_product_SpecificationDefinitions_ScopeCode DEFAULT (N'product'),
    [ValidationRulesJson] NVARCHAR(MAX) NULL,
    [MinimumValue] DECIMAL(18,6) NULL,
    [MaximumValue] DECIMAL(18,6) NULL,
    [DecimalPrecision] TINYINT NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_SpecificationDefinitions_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationDefinitions_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationDefinitions_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_SpecificationDefinitions PRIMARY KEY CLUSTERED ([SpecificationDefinitionId]),
    CONSTRAINT UQ_product_SpecificationDefinitions_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_SpecificationDefinitions_DefinitionCode UNIQUE ([DefinitionCode]),
    CONSTRAINT CK_product_SpecificationDefinitions_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([DefinitionName]))) > 0),
    CONSTRAINT CK_product_SpecificationDefinitions_DataType CHECK (
        [DataTypeCode] IN (
            N'text', N'long-text', N'integer', N'decimal', N'boolean', N'date',
            N'single-select', N'multi-select', N'measurement', N'range', N'url'
        )
    ),
    CONSTRAINT CK_product_SpecificationDefinitions_Scope CHECK ([ScopeCode] IN (N'product', N'variant', N'both')),
    CONSTRAINT CK_product_SpecificationDefinitions_MinMax CHECK (
        [MinimumValue] IS NULL OR [MaximumValue] IS NULL OR [MinimumValue] <= [MaximumValue]
    ),
    CONSTRAINT FK_product_SpecificationDefinitions_Group FOREIGN KEY ([SpecificationGroupId]) REFERENCES [product].[SpecificationGroups] ([SpecificationGroupId]),
    CONSTRAINT FK_product_SpecificationDefinitions_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_SpecificationDefinitions_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_SpecificationDefinitions_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[SpecificationDefinitions]') AND name = N'IX_product_SpecificationDefinitions_GroupId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_SpecificationDefinitions_GroupId ON [product].[SpecificationDefinitions] ([SpecificationGroupId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[SpecificationDefinitions]') AND name = N'IX_product_SpecificationDefinitions_Scope_Filterable')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_SpecificationDefinitions_Scope_Filterable ON [product].[SpecificationDefinitions] ([ScopeCode], [IsFilterable]) WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1;
    END;


    /* ------------------------------------------------------------------
       [product].[SpecificationOptions]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[SpecificationOptions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[SpecificationOptions] (
    [SpecificationOptionId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_SpecificationOptions_PublicId DEFAULT (NEWSEQUENTIALID()),
    [SpecificationDefinitionId] BIGINT NOT NULL,
    [OptionCode] NVARCHAR(50) NOT NULL,
    [OptionLabel] NVARCHAR(200) NOT NULL,
    [OptionValue] NVARCHAR(200) NULL,
    [ImageUrl] NVARCHAR(2000) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_SpecificationOptions_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_SpecificationOptions_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationOptions_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_SpecificationOptions_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_SpecificationOptions PRIMARY KEY CLUSTERED ([SpecificationOptionId]),
    CONSTRAINT UQ_product_SpecificationOptions_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_SpecificationOptions_Definition_Code UNIQUE ([SpecificationDefinitionId], [OptionCode]),
    CONSTRAINT CK_product_SpecificationOptions_Label_NotEmpty CHECK (LEN(LTRIM(RTRIM([OptionLabel]))) > 0),
    CONSTRAINT FK_product_SpecificationOptions_Definition FOREIGN KEY ([SpecificationDefinitionId]) REFERENCES [product].[SpecificationDefinitions] ([SpecificationDefinitionId]),
    CONSTRAINT FK_product_SpecificationOptions_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_SpecificationOptions_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_SpecificationOptions_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[SpecificationOptions]') AND name = N'IX_product_SpecificationOptions_Definition_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_SpecificationOptions_Definition_DisplayOrder ON [product].[SpecificationOptions] ([SpecificationDefinitionId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductSpecificationValues]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductSpecificationValues]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductSpecificationValues] (
    [ProductSpecificationValueId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductSpecificationValues_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [SpecificationDefinitionId] BIGINT NOT NULL,
    
    [ValueText] NVARCHAR(500) NULL,
    [ValueLongText] NVARCHAR(MAX) NULL,
    [ValueInteger] BIGINT NULL,
    [ValueDecimal] DECIMAL(18,6) NULL,
    [ValueBoolean] BIT NULL,
    [ValueDate] DATE NULL,
    [ValueUrl] NVARCHAR(2000) NULL,
    [ValueMinDecimal] DECIMAL(18,6) NULL,
    [ValueMaxDecimal] DECIMAL(18,6) NULL,
    [UnitOverride] NVARCHAR(50) NULL,
    [SpecificationOptionId] BIGINT NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductSpecificationValues_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductSpecificationValues_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductSpecificationValues_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductSpecificationValues PRIMARY KEY CLUSTERED ([ProductSpecificationValueId]),
    CONSTRAINT UQ_product_ProductSpecificationValues_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductSpecificationValues_Product_Definition UNIQUE ([ProductId], [SpecificationDefinitionId]),
    CONSTRAINT CK_product_ProductSpecificationValues_Range CHECK (
        [ValueMinDecimal] IS NULL OR [ValueMaxDecimal] IS NULL OR [ValueMinDecimal] <= [ValueMaxDecimal]
    ),
    CONSTRAINT FK_product_ProductSpecificationValues_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductSpecificationValues_Definition FOREIGN KEY ([SpecificationDefinitionId]) REFERENCES [product].[SpecificationDefinitions] ([SpecificationDefinitionId]),
    CONSTRAINT FK_product_ProductSpecificationValues_Option FOREIGN KEY ([SpecificationOptionId]) REFERENCES [product].[SpecificationOptions] ([SpecificationOptionId]),
    CONSTRAINT FK_product_ProductSpecificationValues_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductSpecificationValues_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductSpecificationValues_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductSpecificationValues]') AND name = N'IX_product_ProductSpecificationValues_Definition')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductSpecificationValues_Definition ON [product].[ProductSpecificationValues] ([SpecificationDefinitionId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductVariantSpecificationValues]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductVariantSpecificationValues]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductVariantSpecificationValues] (
    [ProductVariantSpecificationValueId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductVariantSpecificationValues_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductVariantId] BIGINT NOT NULL,
    [SpecificationDefinitionId] BIGINT NOT NULL,
    
    [ValueText] NVARCHAR(500) NULL,
    [ValueLongText] NVARCHAR(MAX) NULL,
    [ValueInteger] BIGINT NULL,
    [ValueDecimal] DECIMAL(18,6) NULL,
    [ValueBoolean] BIT NULL,
    [ValueDate] DATE NULL,
    [ValueUrl] NVARCHAR(2000) NULL,
    [ValueMinDecimal] DECIMAL(18,6) NULL,
    [ValueMaxDecimal] DECIMAL(18,6) NULL,
    [UnitOverride] NVARCHAR(50) NULL,
    [SpecificationOptionId] BIGINT NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductVariantSpecificationValues_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductVariantSpecificationValues_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductVariantSpecificationValues_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductVariantSpecificationValues PRIMARY KEY CLUSTERED ([ProductVariantSpecificationValueId]),
    CONSTRAINT UQ_product_ProductVariantSpecificationValues_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductVariantSpecificationValues_Variant_Definition UNIQUE ([ProductVariantId], [SpecificationDefinitionId]),
    CONSTRAINT CK_product_ProductVariantSpecificationValues_Range CHECK (
        [ValueMinDecimal] IS NULL OR [ValueMaxDecimal] IS NULL OR [ValueMinDecimal] <= [ValueMaxDecimal]
    ),
    CONSTRAINT FK_product_ProductVariantSpecificationValues_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_ProductVariantSpecificationValues_Definition FOREIGN KEY ([SpecificationDefinitionId]) REFERENCES [product].[SpecificationDefinitions] ([SpecificationDefinitionId]),
    CONSTRAINT FK_product_ProductVariantSpecificationValues_Option FOREIGN KEY ([SpecificationOptionId]) REFERENCES [product].[SpecificationOptions] ([SpecificationOptionId]),
    CONSTRAINT FK_product_ProductVariantSpecificationValues_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductVariantSpecificationValues_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductVariantSpecificationValues_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductVariantSpecificationValues]') AND name = N'IX_product_ProductVariantSpecificationValues_Definition')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductVariantSpecificationValues_Definition ON [product].[ProductVariantSpecificationValues] ([SpecificationDefinitionId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductSpecificationOptionValues]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductSpecificationOptionValues]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductSpecificationOptionValues] (
    [ProductSpecificationOptionValueId] BIGINT IDENTITY(1,1) NOT NULL,
    [ProductSpecificationValueId] BIGINT NOT NULL,
    [SpecificationOptionId] BIGINT NOT NULL,
    [StatusCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductSpecificationOptionValues_StatusCode DEFAULT (N'approved'),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductSpecificationOptionValues_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT PK_product_ProductSpecificationOptionValues PRIMARY KEY CLUSTERED ([ProductSpecificationOptionValueId]),
    CONSTRAINT UQ_product_ProductSpecificationOptionValues_Value_Option UNIQUE ([ProductSpecificationValueId], [SpecificationOptionId]),
    CONSTRAINT CK_product_ProductSpecificationOptionValues_StatusCode CHECK ([StatusCode] IN (N'approved', N'pending')),
    CONSTRAINT FK_product_ProductSpecificationOptionValues_Value FOREIGN KEY ([ProductSpecificationValueId]) REFERENCES [product].[ProductSpecificationValues] ([ProductSpecificationValueId]),
    CONSTRAINT FK_product_ProductSpecificationOptionValues_Option FOREIGN KEY ([SpecificationOptionId]) REFERENCES [product].[SpecificationOptions] ([SpecificationOptionId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductVariantSpecificationOptionValues]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductVariantSpecificationOptionValues]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductVariantSpecificationOptionValues] (
    [ProductVariantSpecificationOptionValueId] BIGINT IDENTITY(1,1) NOT NULL,
    [ProductVariantSpecificationValueId] BIGINT NOT NULL,
    [SpecificationOptionId] BIGINT NOT NULL,
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductVariantSpecificationOptionValues_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT PK_product_ProductVariantSpecificationOptionValues PRIMARY KEY CLUSTERED ([ProductVariantSpecificationOptionValueId]),
    CONSTRAINT UQ_product_ProductVariantSpecificationOptionValues_Value_Option UNIQUE ([ProductVariantSpecificationValueId], [SpecificationOptionId]),
    CONSTRAINT FK_product_ProductVariantSpecificationOptionValues_Value FOREIGN KEY ([ProductVariantSpecificationValueId]) REFERENCES [product].[ProductVariantSpecificationValues] ([ProductVariantSpecificationValueId]),
    CONSTRAINT FK_product_ProductVariantSpecificationOptionValues_Option FOREIGN KEY ([SpecificationOptionId]) REFERENCES [product].[SpecificationOptions] ([SpecificationOptionId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductImages]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductImages]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductImages] (
    [ProductImagesId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductImages_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [ProductVariantId] BIGINT NULL,
    [MediaTypeId] BIGINT NOT NULL,
    [Title] NVARCHAR(200) NOT NULL,
    [AltText] NVARCHAR(500) NULL,
    [Caption] NVARCHAR(1000) NULL,
    [StorageProviderCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductImages_StorageProvider DEFAULT (N'azure-blob'),
    [StorageContainer] NVARCHAR(200) NULL,
    [StoragePath] NVARCHAR(1000) NOT NULL,
    [PublicUrl] NVARCHAR(2000) NULL,
    [ContentType] NVARCHAR(150) NULL,
    [FileExtension] NVARCHAR(20) NULL,
    [ByteSize] BIGINT NULL,
    [ChecksumSha256] NVARCHAR(64) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductImages_DisplayOrder DEFAULT (0),
    [IsPrimary] BIT NOT NULL CONSTRAINT DF_product_ProductImages_IsPrimary DEFAULT (0),
    [RegionId] BIGINT NULL,
    [LanguageCode] NVARCHAR(10) NULL,
    
    [ImageRoleCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductImages_ImageRole DEFAULT (N'gallery'),
    [WidthPx] INT NULL,
    [HeightPx] INT NULL,

    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductImages_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductImages_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductImages_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductImages PRIMARY KEY CLUSTERED ([ProductImagesId]),
    CONSTRAINT UQ_product_ProductImages_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductImages_Title_NotEmpty CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
    CONSTRAINT CK_product_ProductImages_Path_NotEmpty CHECK (LEN(LTRIM(RTRIM([StoragePath]))) > 0),
    CONSTRAINT CK_product_ProductImages_Role CHECK (
        [ImageRoleCode] IN (N'primary', N'gallery', N'thumbnail', N'product-thumbnail', N'hero', N'diagram', N'cross-section', N'installation')
    ),
    CONSTRAINT FK_product_ProductImages_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductImages_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_ProductImages_MediaType FOREIGN KEY ([MediaTypeId]) REFERENCES [product].[MediaTypes] ([MediaTypeId]),
    CONSTRAINT FK_product_ProductImages_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductImages_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductImages_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductImages]') AND name = N'IX_product_ProductImages_Product_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductImages_Product_DisplayOrder ON [product].[ProductImages] ([ProductId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductImages]') AND name = N'IX_product_ProductImages_Variant')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductImages_Variant ON [product].[ProductImages] ([ProductVariantId]) WHERE [ProductVariantId] IS NOT NULL AND [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductVideos]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductVideos]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductVideos] (
    [ProductVideosId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductVideos_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [ProductVariantId] BIGINT NULL,
    [MediaTypeId] BIGINT NOT NULL,
    [Title] NVARCHAR(200) NOT NULL,
    [AltText] NVARCHAR(500) NULL,
    [Caption] NVARCHAR(1000) NULL,
    [StorageProviderCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductVideos_StorageProvider DEFAULT (N'azure-blob'),
    [StorageContainer] NVARCHAR(200) NULL,
    [StoragePath] NVARCHAR(1000) NOT NULL,
    [PublicUrl] NVARCHAR(2000) NULL,
    [ContentType] NVARCHAR(150) NULL,
    [FileExtension] NVARCHAR(20) NULL,
    [ByteSize] BIGINT NULL,
    [ChecksumSha256] NVARCHAR(64) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductVideos_DisplayOrder DEFAULT (0),
    [IsPrimary] BIT NOT NULL CONSTRAINT DF_product_ProductVideos_IsPrimary DEFAULT (0),
    [RegionId] BIGINT NULL,
    [LanguageCode] NVARCHAR(10) NULL,
    
    [DurationSeconds] INT NULL,
    [PosterStoragePath] NVARCHAR(1000) NULL,

    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductVideos_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductVideos_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductVideos_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductVideos PRIMARY KEY CLUSTERED ([ProductVideosId]),
    CONSTRAINT UQ_product_ProductVideos_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductVideos_Title_NotEmpty CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
    CONSTRAINT CK_product_ProductVideos_Path_NotEmpty CHECK (LEN(LTRIM(RTRIM([StoragePath]))) > 0),
    
    CONSTRAINT FK_product_ProductVideos_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductVideos_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_ProductVideos_MediaType FOREIGN KEY ([MediaTypeId]) REFERENCES [product].[MediaTypes] ([MediaTypeId]),
    CONSTRAINT FK_product_ProductVideos_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductVideos_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductVideos_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductVideos]') AND name = N'IX_product_ProductVideos_Product_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductVideos_Product_DisplayOrder ON [product].[ProductVideos] ([ProductId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductVideos]') AND name = N'IX_product_ProductVideos_Variant')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductVideos_Variant ON [product].[ProductVideos] ([ProductVariantId]) WHERE [ProductVariantId] IS NOT NULL AND [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductARModels]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductARModels]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductARModels] (
    [ProductARModelsId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductARModels_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [ProductVariantId] BIGINT NULL,
    [MediaTypeId] BIGINT NOT NULL,
    [Title] NVARCHAR(200) NOT NULL,
    [AltText] NVARCHAR(500) NULL,
    [Caption] NVARCHAR(1000) NULL,
    [StorageProviderCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductARModels_StorageProvider DEFAULT (N'azure-blob'),
    [StorageContainer] NVARCHAR(200) NULL,
    [StoragePath] NVARCHAR(1000) NOT NULL,
    [PublicUrl] NVARCHAR(2000) NULL,
    [ContentType] NVARCHAR(150) NULL,
    [FileExtension] NVARCHAR(20) NULL,
    [ByteSize] BIGINT NULL,
    [ChecksumSha256] NVARCHAR(64) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductARModels_DisplayOrder DEFAULT (0),
    [IsPrimary] BIT NOT NULL CONSTRAINT DF_product_ProductARModels_IsPrimary DEFAULT (0),
    [RegionId] BIGINT NULL,
    [LanguageCode] NVARCHAR(10) NULL,
    
    [ModelFormatCode] NVARCHAR(20) NOT NULL CONSTRAINT DF_product_ProductARModels_Format DEFAULT (N'glb'),

    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductARModels_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductARModels_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductARModels_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductARModels PRIMARY KEY CLUSTERED ([ProductARModelsId]),
    CONSTRAINT UQ_product_ProductARModels_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductARModels_Title_NotEmpty CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
    CONSTRAINT CK_product_ProductARModels_Path_NotEmpty CHECK (LEN(LTRIM(RTRIM([StoragePath]))) > 0),
    CONSTRAINT CK_product_ProductARModels_Format CHECK (
        [ModelFormatCode] IN (N'glb', N'gltf', N'usdz')
    ),
    CONSTRAINT FK_product_ProductARModels_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductARModels_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_ProductARModels_MediaType FOREIGN KEY ([MediaTypeId]) REFERENCES [product].[MediaTypes] ([MediaTypeId]),
    CONSTRAINT FK_product_ProductARModels_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductARModels_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductARModels_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductARModels]') AND name = N'IX_product_ProductARModels_Product_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductARModels_Product_DisplayOrder ON [product].[ProductARModels] ([ProductId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductARModels]') AND name = N'IX_product_ProductARModels_Variant')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductARModels_Variant ON [product].[ProductARModels] ([ProductVariantId]) WHERE [ProductVariantId] IS NOT NULL AND [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductCADFiles]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductCADFiles]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductCADFiles] (
    [ProductCADFilesId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductCADFiles_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [ProductVariantId] BIGINT NULL,
    [MediaTypeId] BIGINT NOT NULL,
    [Title] NVARCHAR(200) NOT NULL,
    [AltText] NVARCHAR(500) NULL,
    [Caption] NVARCHAR(1000) NULL,
    [StorageProviderCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductCADFiles_StorageProvider DEFAULT (N'azure-blob'),
    [StorageContainer] NVARCHAR(200) NULL,
    [StoragePath] NVARCHAR(1000) NOT NULL,
    [PublicUrl] NVARCHAR(2000) NULL,
    [ContentType] NVARCHAR(150) NULL,
    [FileExtension] NVARCHAR(20) NULL,
    [ByteSize] BIGINT NULL,
    [ChecksumSha256] NVARCHAR(64) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductCADFiles_DisplayOrder DEFAULT (0),
    [IsPrimary] BIT NOT NULL CONSTRAINT DF_product_ProductCADFiles_IsPrimary DEFAULT (0),
    [RegionId] BIGINT NULL,
    [LanguageCode] NVARCHAR(10) NULL,
    
    [CadFormatCode] NVARCHAR(30) NULL,

    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductCADFiles_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCADFiles_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCADFiles_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductCADFiles PRIMARY KEY CLUSTERED ([ProductCADFilesId]),
    CONSTRAINT UQ_product_ProductCADFiles_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductCADFiles_Title_NotEmpty CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
    CONSTRAINT CK_product_ProductCADFiles_Path_NotEmpty CHECK (LEN(LTRIM(RTRIM([StoragePath]))) > 0),
    
    CONSTRAINT FK_product_ProductCADFiles_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductCADFiles_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_ProductCADFiles_MediaType FOREIGN KEY ([MediaTypeId]) REFERENCES [product].[MediaTypes] ([MediaTypeId]),
    CONSTRAINT FK_product_ProductCADFiles_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCADFiles_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCADFiles_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductCADFiles]') AND name = N'IX_product_ProductCADFiles_Product_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductCADFiles_Product_DisplayOrder ON [product].[ProductCADFiles] ([ProductId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductCADFiles]') AND name = N'IX_product_ProductCADFiles_Variant')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductCADFiles_Variant ON [product].[ProductCADFiles] ([ProductVariantId]) WHERE [ProductVariantId] IS NOT NULL AND [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductDownloads]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductDownloads]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductDownloads] (
    [ProductDownloadsId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductDownloads_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [ProductVariantId] BIGINT NULL,
    [MediaTypeId] BIGINT NOT NULL,
    [Title] NVARCHAR(200) NOT NULL,
    [AltText] NVARCHAR(500) NULL,
    [Caption] NVARCHAR(1000) NULL,
    [StorageProviderCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductDownloads_StorageProvider DEFAULT (N'azure-blob'),
    [StorageContainer] NVARCHAR(200) NULL,
    [StoragePath] NVARCHAR(1000) NOT NULL,
    [PublicUrl] NVARCHAR(2000) NULL,
    [ContentType] NVARCHAR(150) NULL,
    [FileExtension] NVARCHAR(20) NULL,
    [ByteSize] BIGINT NULL,
    [ChecksumSha256] NVARCHAR(64) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductDownloads_DisplayOrder DEFAULT (0),
    [IsPrimary] BIT NOT NULL CONSTRAINT DF_product_ProductDownloads_IsPrimary DEFAULT (0),
    [RegionId] BIGINT NULL,
    [LanguageCode] NVARCHAR(10) NULL,
    
    [DownloadAccessCode] NVARCHAR(20) NOT NULL CONSTRAINT DF_product_ProductDownloads_Access DEFAULT (N'public'),

    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductDownloads_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductDownloads_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductDownloads_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductDownloads PRIMARY KEY CLUSTERED ([ProductDownloadsId]),
    CONSTRAINT UQ_product_ProductDownloads_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductDownloads_Title_NotEmpty CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
    CONSTRAINT CK_product_ProductDownloads_Path_NotEmpty CHECK (LEN(LTRIM(RTRIM([StoragePath]))) > 0),
    CONSTRAINT CK_product_ProductDownloads_Access CHECK (
        [DownloadAccessCode] IN (N'public', N'restricted', N'authenticated')
    ),
    CONSTRAINT FK_product_ProductDownloads_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductDownloads_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_ProductDownloads_MediaType FOREIGN KEY ([MediaTypeId]) REFERENCES [product].[MediaTypes] ([MediaTypeId]),
    CONSTRAINT FK_product_ProductDownloads_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductDownloads_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductDownloads_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductDownloads]') AND name = N'IX_product_ProductDownloads_Product_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductDownloads_Product_DisplayOrder ON [product].[ProductDownloads] ([ProductId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductDownloads]') AND name = N'IX_product_ProductDownloads_Variant')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductDownloads_Variant ON [product].[ProductDownloads] ([ProductVariantId]) WHERE [ProductVariantId] IS NOT NULL AND [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[Product360Sets]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[Product360Sets]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[Product360Sets] (
    [Product360SetId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Product360Sets_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [ProductVariantId] BIGINT NULL,
    [MediaTypeId] BIGINT NOT NULL,
    [Title] NVARCHAR(200) NOT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_Product360Sets_DisplayOrder DEFAULT (0),
    [FrameCount] INT NOT NULL CONSTRAINT DF_product_Product360Sets_FrameCount DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_Product360Sets_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product360Sets_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product360Sets_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_Product360Sets PRIMARY KEY CLUSTERED ([Product360SetId]),
    CONSTRAINT UQ_product_Product360Sets_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_Product360Sets_Title_NotEmpty CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
    CONSTRAINT FK_product_Product360Sets_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_Product360Sets_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_Product360Sets_MediaType FOREIGN KEY ([MediaTypeId]) REFERENCES [product].[MediaTypes] ([MediaTypeId]),
    CONSTRAINT FK_product_Product360Sets_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Product360Sets_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Product360Sets_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[Product360Sets]') AND name = N'IX_product_Product360Sets_ProductId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_Product360Sets_ProductId ON [product].[Product360Sets] ([ProductId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[Product360Frames]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[Product360Frames]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[Product360Frames] (
    [Product360FrameId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Product360Frames_PublicId DEFAULT (NEWSEQUENTIALID()),
    [Product360SetId] BIGINT NOT NULL,
    [FrameIndex] INT NOT NULL,
    [StorageProviderCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_Product360Frames_StorageProvider DEFAULT (N'azure-blob'),
    [StorageContainer] NVARCHAR(200) NULL,
    [StoragePath] NVARCHAR(1000) NOT NULL,
    [PublicUrl] NVARCHAR(2000) NULL,
    [ContentType] NVARCHAR(150) NULL,
    [ByteSize] BIGINT NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_Product360Frames_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product360Frames_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Product360Frames_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_Product360Frames PRIMARY KEY CLUSTERED ([Product360FrameId]),
    CONSTRAINT UQ_product_Product360Frames_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_Product360Frames_Set_Index UNIQUE ([Product360SetId], [FrameIndex]),
    CONSTRAINT CK_product_Product360Frames_FrameIndex CHECK ([FrameIndex] >= 0),
    CONSTRAINT FK_product_Product360Frames_Set FOREIGN KEY ([Product360SetId]) REFERENCES [product].[Product360Sets] ([Product360SetId]),
    CONSTRAINT FK_product_Product360Frames_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Product360Frames_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Product360Frames_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductDocuments]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductDocuments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductDocuments] (
    [ProductDocumentId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductDocuments_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [ProductVariantId] BIGINT NULL,
    [DocumentTypeId] BIGINT NOT NULL,
    [Title] NVARCHAR(200) NOT NULL,
    [VersionLabel] NVARCHAR(50) NULL,
    [LanguageCode] NVARCHAR(10) NULL,
    [RegionId] BIGINT NULL,
    [MarketId] BIGINT NULL,
    [StorageProviderCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductDocuments_StorageProvider DEFAULT (N'azure-blob'),
    [StorageContainer] NVARCHAR(200) NULL,
    [StoragePath] NVARCHAR(1000) NOT NULL,
    [PublicUrl] NVARCHAR(2000) NULL,
    [ContentType] NVARCHAR(150) NULL,
    [FileExtension] NVARCHAR(20) NULL,
    [ByteSize] BIGINT NULL,
    [ChecksumSha256] NVARCHAR(64) NULL,
    [AccessCode] NVARCHAR(20) NOT NULL CONSTRAINT DF_product_ProductDocuments_Access DEFAULT (N'public'),
    [EffectiveFromUtc] DATETIME2(7) NULL,
    [EffectiveToUtc] DATETIME2(7) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductDocuments_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductDocuments_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductDocuments_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductDocuments_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductDocuments PRIMARY KEY CLUSTERED ([ProductDocumentId]),
    CONSTRAINT UQ_product_ProductDocuments_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductDocuments_Title_NotEmpty CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
    CONSTRAINT CK_product_ProductDocuments_Access CHECK ([AccessCode] IN (N'public', N'restricted', N'authenticated')),
    CONSTRAINT CK_product_ProductDocuments_EffectiveWindow CHECK (
        [EffectiveToUtc] IS NULL OR [EffectiveFromUtc] IS NULL OR [EffectiveToUtc] >= [EffectiveFromUtc]
    ),
    CONSTRAINT FK_product_ProductDocuments_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductDocuments_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_ProductDocuments_DocumentType FOREIGN KEY ([DocumentTypeId]) REFERENCES [product].[DocumentTypes] ([DocumentTypeId]),
    CONSTRAINT FK_product_ProductDocuments_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductDocuments_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductDocuments_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductDocuments]') AND name = N'IX_product_ProductDocuments_Product_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductDocuments_Product_DisplayOrder ON [product].[ProductDocuments] ([ProductId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductDocuments]') AND name = N'IX_product_ProductDocuments_DocumentType')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductDocuments_DocumentType ON [product].[ProductDocuments] ([DocumentTypeId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductMedia]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductMedia]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductMedia] (
    [ProductMediaId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductMedia_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [ProductVariantId] BIGINT NULL,
    [MediaCategoryCode] NVARCHAR(30) NOT NULL,
    [MediaTypeId] BIGINT NOT NULL,
    [SourceTableCode] NVARCHAR(50) NOT NULL,
    [SourceRecordId] BIGINT NOT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductMedia_DisplayOrder DEFAULT (0),
    [IsPrimary] BIT NOT NULL CONSTRAINT DF_product_ProductMedia_IsPrimary DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductMedia_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductMedia_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductMedia_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductMedia PRIMARY KEY CLUSTERED ([ProductMediaId]),
    CONSTRAINT UQ_product_ProductMedia_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductMedia_Source UNIQUE ([SourceTableCode], [SourceRecordId]),
    CONSTRAINT CK_product_ProductMedia_Category CHECK (
        [MediaCategoryCode] IN (N'image', N'video', N'360', N'ar', N'document', N'cad', N'download')
    ),
    CONSTRAINT FK_product_ProductMedia_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductMedia_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_ProductMedia_MediaType FOREIGN KEY ([MediaTypeId]) REFERENCES [product].[MediaTypes] ([MediaTypeId]),
    CONSTRAINT FK_product_ProductMedia_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductMedia_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductMedia_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductMedia]') AND name = N'IX_product_ProductMedia_Product_Category')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductMedia_Product_Category ON [product].[ProductMedia] ([ProductId], [MediaCategoryCode], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[Regions]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[Regions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[Regions] (
    [RegionId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Regions_PublicId DEFAULT (NEWSEQUENTIALID()),
    [RegionCode] NVARCHAR(20) NOT NULL,
    [RegionName] NVARCHAR(100) NOT NULL,
    [CountryCodes] NVARCHAR(200) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_Regions_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_Regions_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Regions_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Regions_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_Regions PRIMARY KEY CLUSTERED ([RegionId]),
    CONSTRAINT UQ_product_Regions_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_Regions_RegionCode UNIQUE ([RegionCode]),
    CONSTRAINT CK_product_Regions_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([RegionName]))) > 0),
    CONSTRAINT FK_product_Regions_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Regions_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Regions_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[Markets]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[Markets]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[Markets] (
    [MarketId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Markets_PublicId DEFAULT (NEWSEQUENTIALID()),
    [RegionId] BIGINT NULL,
    [MarketCode] NVARCHAR(50) NOT NULL,
    [MarketName] NVARCHAR(100) NOT NULL,
    [Description] NVARCHAR(500) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_Markets_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_Markets_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Markets_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Markets_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_Markets PRIMARY KEY CLUSTERED ([MarketId]),
    CONSTRAINT UQ_product_Markets_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_Markets_MarketCode UNIQUE ([MarketCode]),
    CONSTRAINT CK_product_Markets_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([MarketName]))) > 0),
    CONSTRAINT FK_product_Markets_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
    CONSTRAINT FK_product_Markets_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Markets_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Markets_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[Markets]') AND name = N'IX_product_Markets_RegionId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_Markets_RegionId ON [product].[Markets] ([RegionId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[Industries]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[Industries]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[Industries] (
    [IndustryId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Industries_PublicId DEFAULT (NEWSEQUENTIALID()),
    [IndustryCode] NVARCHAR(50) NOT NULL,
    [IndustryName] NVARCHAR(200) NOT NULL,
    [Description] NVARCHAR(1000) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_Industries_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_Industries_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Industries_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Industries_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_Industries PRIMARY KEY CLUSTERED ([IndustryId]),
    CONSTRAINT UQ_product_Industries_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_Industries_IndustryCode UNIQUE ([IndustryCode]),
    CONSTRAINT FK_product_Industries_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Industries_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Industries_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[Applications]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[Applications]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[Applications] (
    [ApplicationId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_Applications_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ApplicationCode] NVARCHAR(50) NOT NULL,
    [ApplicationName] NVARCHAR(200) NOT NULL,
    [Description] NVARCHAR(1000) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_Applications_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_Applications_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Applications_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_Applications_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_Applications PRIMARY KEY CLUSTERED ([ApplicationId]),
    CONSTRAINT UQ_product_Applications_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_Applications_ApplicationCode UNIQUE ([ApplicationCode]),
    CONSTRAINT FK_product_Applications_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Applications_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_Applications_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductRegions]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductRegions]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductRegions] (
    [ProductRegionId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductRegions_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [RegionId] BIGINT NOT NULL,
    [ProductStatusId] BIGINT NULL,
    [RegionalName] NVARCHAR(200) NULL,
    [RegionalDescription] NVARCHAR(MAX) NULL,
    [ComplianceNotes] NVARCHAR(2000) NULL,
    [AvailableFromUtc] DATETIME2(7) NULL,
    [AvailableToUtc] DATETIME2(7) NULL,
    [IsVisible] BIT NOT NULL CONSTRAINT DF_product_ProductRegions_IsVisible DEFAULT (1),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductRegions_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductRegions_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductRegions_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductRegions PRIMARY KEY CLUSTERED ([ProductRegionId]),
    CONSTRAINT UQ_product_ProductRegions_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductRegions_Product_Region UNIQUE ([ProductId], [RegionId]),
    CONSTRAINT CK_product_ProductRegions_Availability CHECK (
        [AvailableToUtc] IS NULL OR [AvailableFromUtc] IS NULL OR [AvailableToUtc] >= [AvailableFromUtc]
    ),
    CONSTRAINT FK_product_ProductRegions_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductRegions_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
    CONSTRAINT FK_product_ProductRegions_Status FOREIGN KEY ([ProductStatusId]) REFERENCES [product].[ProductStatuses] ([ProductStatusId]),
    CONSTRAINT FK_product_ProductRegions_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductRegions_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductRegions_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductRegions]') AND name = N'IX_product_ProductRegions_Region_Visible')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductRegions_Region_Visible ON [product].[ProductRegions] ([RegionId], [IsVisible]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductMarkets]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductMarkets]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductMarkets] (
    [ProductMarketId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductMarkets_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [MarketId] BIGINT NOT NULL,
    [ProductStatusId] BIGINT NULL,
    [AvailableFromUtc] DATETIME2(7) NULL,
    [AvailableToUtc] DATETIME2(7) NULL,
    [IsAvailable] BIT NOT NULL CONSTRAINT DF_product_ProductMarkets_IsAvailable DEFAULT (1),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductMarkets_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductMarkets_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductMarkets_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductMarkets PRIMARY KEY CLUSTERED ([ProductMarketId]),
    CONSTRAINT UQ_product_ProductMarkets_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductMarkets_Product_Market UNIQUE ([ProductId], [MarketId]),
    CONSTRAINT CK_product_ProductMarkets_Availability CHECK (
        [AvailableToUtc] IS NULL OR [AvailableFromUtc] IS NULL OR [AvailableToUtc] >= [AvailableFromUtc]
    ),
    CONSTRAINT FK_product_ProductMarkets_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductMarkets_Market FOREIGN KEY ([MarketId]) REFERENCES [product].[Markets] ([MarketId]),
    CONSTRAINT FK_product_ProductMarkets_Status FOREIGN KEY ([ProductStatusId]) REFERENCES [product].[ProductStatuses] ([ProductStatusId]),
    CONSTRAINT FK_product_ProductMarkets_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductMarkets_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductMarkets_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductMarkets]') AND name = N'IX_product_ProductMarkets_Market_Available')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductMarkets_Market_Available ON [product].[ProductMarkets] ([MarketId], [IsAvailable]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductIndustries]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductIndustries]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductIndustries] (
    [ProductIndustryId] BIGINT IDENTITY(1,1) NOT NULL,
    [ProductId] BIGINT NOT NULL,
    [IndustryId] BIGINT NOT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductIndustries_DisplayOrder DEFAULT (0),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductIndustries_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    CONSTRAINT PK_product_ProductIndustries PRIMARY KEY CLUSTERED ([ProductIndustryId]),
    CONSTRAINT UQ_product_ProductIndustries_Product_Industry UNIQUE ([ProductId], [IndustryId]),
    CONSTRAINT FK_product_ProductIndustries_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductIndustries_Industry FOREIGN KEY ([IndustryId]) REFERENCES [product].[Industries] ([IndustryId]),
    CONSTRAINT FK_product_ProductIndustries_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductApplications]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductApplications]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductApplications] (
    [ProductApplicationId] BIGINT IDENTITY(1,1) NOT NULL,
    [ProductId] BIGINT NOT NULL,
    [ApplicationId] BIGINT NOT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductApplications_DisplayOrder DEFAULT (0),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductApplications_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    CONSTRAINT PK_product_ProductApplications PRIMARY KEY CLUSTERED ([ProductApplicationId]),
    CONSTRAINT UQ_product_ProductApplications_Product_Application UNIQUE ([ProductId], [ApplicationId]),
    CONSTRAINT FK_product_ProductApplications_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductApplications_Application FOREIGN KEY ([ApplicationId]) REFERENCES [product].[Applications] ([ApplicationId]),
    CONSTRAINT FK_product_ProductApplications_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductAccessories]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductAccessories]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductAccessories] (
    [ProductAccessoriesId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductAccessories_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [RelatedProductId] BIGINT NOT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductAccessories_DisplayOrder DEFAULT (0),
    [Notes] NVARCHAR(1000) NULL,
    
    [AccessoryRequirementCode] NVARCHAR(20) NOT NULL CONSTRAINT DF_product_ProductAccessories_Requirement DEFAULT (N'optional'),
    CONSTRAINT CK_product_ProductAccessories_Requirement CHECK (
        [AccessoryRequirementCode] IN (N'required', N'optional', N'compatible')
    ),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductAccessories_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductAccessories_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductAccessories_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductAccessories PRIMARY KEY CLUSTERED ([ProductAccessoriesId]),
    CONSTRAINT UQ_product_ProductAccessories_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductAccessories_Pair UNIQUE ([ProductId], [RelatedProductId]),
    CONSTRAINT CK_product_ProductAccessories_NoSelfRef CHECK ([ProductId] <> [RelatedProductId]),
    CONSTRAINT FK_product_ProductAccessories_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductAccessories_Related FOREIGN KEY ([RelatedProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductAccessories_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductAccessories_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductAccessories_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductAccessories]') AND name = N'IX_product_ProductAccessories_RelatedProductId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductAccessories_RelatedProductId ON [product].[ProductAccessories] ([RelatedProductId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[RelatedProducts]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[RelatedProducts]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[RelatedProducts] (
    [RelatedProductsId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_RelatedProducts_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [RelatedProductId] BIGINT NOT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_RelatedProducts_DisplayOrder DEFAULT (0),
    [Notes] NVARCHAR(1000) NULL,
    
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_RelatedProducts_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_RelatedProducts_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_RelatedProducts_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_RelatedProducts PRIMARY KEY CLUSTERED ([RelatedProductsId]),
    CONSTRAINT UQ_product_RelatedProducts_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_RelatedProducts_Pair UNIQUE ([ProductId], [RelatedProductId]),
    CONSTRAINT CK_product_RelatedProducts_NoSelfRef CHECK ([ProductId] <> [RelatedProductId]),
    CONSTRAINT FK_product_RelatedProducts_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_RelatedProducts_Related FOREIGN KEY ([RelatedProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_RelatedProducts_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_RelatedProducts_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_RelatedProducts_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[RelatedProducts]') AND name = N'IX_product_RelatedProducts_RelatedProductId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_RelatedProducts_RelatedProductId ON [product].[RelatedProducts] ([RelatedProductId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ReplacementProducts]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ReplacementProducts]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ReplacementProducts] (
    [ReplacementProductsId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ReplacementProducts_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [RelatedProductId] BIGINT NOT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ReplacementProducts_DisplayOrder DEFAULT (0),
    [Notes] NVARCHAR(1000) NULL,
    
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ReplacementProducts_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ReplacementProducts_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ReplacementProducts_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ReplacementProducts PRIMARY KEY CLUSTERED ([ReplacementProductsId]),
    CONSTRAINT UQ_product_ReplacementProducts_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ReplacementProducts_Pair UNIQUE ([ProductId], [RelatedProductId]),
    CONSTRAINT CK_product_ReplacementProducts_NoSelfRef CHECK ([ProductId] <> [RelatedProductId]),
    CONSTRAINT FK_product_ReplacementProducts_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ReplacementProducts_Related FOREIGN KEY ([RelatedProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ReplacementProducts_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ReplacementProducts_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ReplacementProducts_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ReplacementProducts]') AND name = N'IX_product_ReplacementProducts_RelatedProductId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ReplacementProducts_RelatedProductId ON [product].[ReplacementProducts] ([RelatedProductId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductCrossSells]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductCrossSells]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductCrossSells] (
    [ProductCrossSellsId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductCrossSells_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [RelatedProductId] BIGINT NOT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductCrossSells_DisplayOrder DEFAULT (0),
    [Notes] NVARCHAR(1000) NULL,
    
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductCrossSells_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCrossSells_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCrossSells_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductCrossSells PRIMARY KEY CLUSTERED ([ProductCrossSellsId]),
    CONSTRAINT UQ_product_ProductCrossSells_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductCrossSells_Pair UNIQUE ([ProductId], [RelatedProductId]),
    CONSTRAINT CK_product_ProductCrossSells_NoSelfRef CHECK ([ProductId] <> [RelatedProductId]),
    CONSTRAINT FK_product_ProductCrossSells_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductCrossSells_Related FOREIGN KEY ([RelatedProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductCrossSells_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCrossSells_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductCrossSells_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductCrossSells]') AND name = N'IX_product_ProductCrossSells_RelatedProductId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductCrossSells_RelatedProductId ON [product].[ProductCrossSells] ([RelatedProductId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductUpSells]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductUpSells]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductUpSells] (
    [ProductUpSellsId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductUpSells_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [RelatedProductId] BIGINT NOT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductUpSells_DisplayOrder DEFAULT (0),
    [Notes] NVARCHAR(1000) NULL,
    
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductUpSells_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductUpSells_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductUpSells_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductUpSells PRIMARY KEY CLUSTERED ([ProductUpSellsId]),
    CONSTRAINT UQ_product_ProductUpSells_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductUpSells_Pair UNIQUE ([ProductId], [RelatedProductId]),
    CONSTRAINT CK_product_ProductUpSells_NoSelfRef CHECK ([ProductId] <> [RelatedProductId]),
    CONSTRAINT FK_product_ProductUpSells_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductUpSells_Related FOREIGN KEY ([RelatedProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductUpSells_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductUpSells_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductUpSells_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductUpSells]') AND name = N'IX_product_ProductUpSells_RelatedProductId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductUpSells_RelatedProductId ON [product].[ProductUpSells] ([RelatedProductId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[PriceLists]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[PriceLists]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[PriceLists] (
    [PriceListId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_PriceLists_PublicId DEFAULT (NEWSEQUENTIALID()),
    [PriceListCode] NVARCHAR(50) NOT NULL,
    [PriceListName] NVARCHAR(200) NOT NULL,
    [CurrencyId] BIGINT NOT NULL,
    [RegionId] BIGINT NULL,
    [MarketId] BIGINT NULL,
    [EffectiveFromUtc] DATETIME2(7) NULL,
    [EffectiveToUtc] DATETIME2(7) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_PriceLists_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_PriceLists_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_PriceLists_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_PriceLists PRIMARY KEY CLUSTERED ([PriceListId]),
    CONSTRAINT UQ_product_PriceLists_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_PriceLists_PriceListCode UNIQUE ([PriceListCode]),
    CONSTRAINT CK_product_PriceLists_EffectiveWindow CHECK (
        [EffectiveToUtc] IS NULL OR [EffectiveFromUtc] IS NULL OR [EffectiveToUtc] >= [EffectiveFromUtc]
    ),
    CONSTRAINT FK_product_PriceLists_Currency FOREIGN KEY ([CurrencyId]) REFERENCES [product].[Currencies] ([CurrencyId]),
    CONSTRAINT FK_product_PriceLists_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
    CONSTRAINT FK_product_PriceLists_Market FOREIGN KEY ([MarketId]) REFERENCES [product].[Markets] ([MarketId]),
    CONSTRAINT FK_product_PriceLists_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_PriceLists_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_PriceLists_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[PricingTiers]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[PricingTiers]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[PricingTiers] (
    [PricingTierId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_PricingTiers_PublicId DEFAULT (NEWSEQUENTIALID()),
    [TierCode] NVARCHAR(50) NOT NULL,
    [TierName] NVARCHAR(100) NOT NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_PricingTiers_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_PricingTiers_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_PricingTiers_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_PricingTiers_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_PricingTiers PRIMARY KEY CLUSTERED ([PricingTierId]),
    CONSTRAINT UQ_product_PricingTiers_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_PricingTiers_TierCode UNIQUE ([TierCode]),
    CONSTRAINT FK_product_PricingTiers_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_PricingTiers_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_PricingTiers_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[PriceListCustomers]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[PriceListCustomers]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[PriceListCustomers] (
    [PriceListCustomerId] BIGINT IDENTITY(1,1) NOT NULL,
    [PriceListId] BIGINT NOT NULL,
    [CustomerId] BIGINT NOT NULL,
    [PricingTierId] BIGINT NULL,
    [EffectiveFromUtc] DATETIME2(7) NULL,
    [EffectiveToUtc] DATETIME2(7) NULL,
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_PriceListCustomers_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_PriceListCustomers_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    CONSTRAINT PK_product_PriceListCustomers PRIMARY KEY CLUSTERED ([PriceListCustomerId]),
    CONSTRAINT UQ_product_PriceListCustomers_List_Customer UNIQUE ([PriceListId], [CustomerId]),
    CONSTRAINT FK_product_PriceListCustomers_List FOREIGN KEY ([PriceListId]) REFERENCES [product].[PriceLists] ([PriceListId]),
    CONSTRAINT FK_product_PriceListCustomers_Customer FOREIGN KEY ([CustomerId]) REFERENCES [customer].[Customers] ([CustomerId]),
    CONSTRAINT FK_product_PriceListCustomers_Tier FOREIGN KEY ([PricingTierId]) REFERENCES [product].[PricingTiers] ([PricingTierId]),
    CONSTRAINT FK_product_PriceListCustomers_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductPrices]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductPrices]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductPrices] (
    [ProductPriceId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductPrices_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [PriceListId] BIGINT NULL,
    [CurrencyId] BIGINT NOT NULL,
    [RegionId] BIGINT NULL,
    [MarketId] BIGINT NULL,
    [PricingTierId] BIGINT NULL,
    [MinimumQuantity] DECIMAL(18,4) NOT NULL CONSTRAINT DF_product_ProductPrices_MinQty DEFAULT (1),
    [UnitPrice] DECIMAL(18,6) NOT NULL,
    [DiscountPercent] DECIMAL(9,4) NULL,
    [TaxInclusive] BIT NOT NULL CONSTRAINT DF_product_ProductPrices_TaxInclusive DEFAULT (0),
    [EffectiveFromUtc] DATETIME2(7) NULL,
    [EffectiveToUtc] DATETIME2(7) NULL,
    [ErpSourceReference] NVARCHAR(100) NULL,
    [LastErpSyncedAtUtc] DATETIME2(7) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductPrices_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductPrices_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductPrices_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductPrices PRIMARY KEY CLUSTERED ([ProductPriceId]),
    CONSTRAINT UQ_product_ProductPrices_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductPrices_MinQty CHECK ([MinimumQuantity] > 0),
    CONSTRAINT CK_product_ProductPrices_UnitPrice CHECK ([UnitPrice] >= 0),
    CONSTRAINT CK_product_ProductPrices_Discount CHECK ([DiscountPercent] IS NULL OR ([DiscountPercent] >= 0 AND [DiscountPercent] <= 100)),
    CONSTRAINT CK_product_ProductPrices_EffectiveWindow CHECK (
        [EffectiveToUtc] IS NULL OR [EffectiveFromUtc] IS NULL OR [EffectiveToUtc] >= [EffectiveFromUtc]
    ),
    CONSTRAINT FK_product_ProductPrices_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductPrices_PriceList FOREIGN KEY ([PriceListId]) REFERENCES [product].[PriceLists] ([PriceListId]),
    CONSTRAINT FK_product_ProductPrices_Currency FOREIGN KEY ([CurrencyId]) REFERENCES [product].[Currencies] ([CurrencyId]),
    CONSTRAINT FK_product_ProductPrices_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
    CONSTRAINT FK_product_ProductPrices_Market FOREIGN KEY ([MarketId]) REFERENCES [product].[Markets] ([MarketId]),
    CONSTRAINT FK_product_ProductPrices_Tier FOREIGN KEY ([PricingTierId]) REFERENCES [product].[PricingTiers] ([PricingTierId]),
    CONSTRAINT FK_product_ProductPrices_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductPrices_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductPrices_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductPrices]') AND name = N'IX_product_ProductPrices_Product_Currency')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductPrices_Product_Currency ON [product].[ProductPrices] ([ProductId], [CurrencyId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductVariantPrices]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductVariantPrices]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductVariantPrices] (
    [ProductVariantPriceId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductVariantPrices_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductVariantId] BIGINT NOT NULL,
    [PriceListId] BIGINT NULL,
    [CurrencyId] BIGINT NOT NULL,
    [RegionId] BIGINT NULL,
    [MarketId] BIGINT NULL,
    [PricingTierId] BIGINT NULL,
    [MinimumQuantity] DECIMAL(18,4) NOT NULL CONSTRAINT DF_product_ProductVariantPrices_MinQty DEFAULT (1),
    [UnitPrice] DECIMAL(18,6) NOT NULL,
    [DiscountPercent] DECIMAL(9,4) NULL,
    [TaxInclusive] BIT NOT NULL CONSTRAINT DF_product_ProductVariantPrices_TaxInclusive DEFAULT (0),
    [EffectiveFromUtc] DATETIME2(7) NULL,
    [EffectiveToUtc] DATETIME2(7) NULL,
    [ErpSourceReference] NVARCHAR(100) NULL,
    [LastErpSyncedAtUtc] DATETIME2(7) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductVariantPrices_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductVariantPrices_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductVariantPrices_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductVariantPrices PRIMARY KEY CLUSTERED ([ProductVariantPriceId]),
    CONSTRAINT UQ_product_ProductVariantPrices_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductVariantPrices_MinQty CHECK ([MinimumQuantity] > 0),
    CONSTRAINT CK_product_ProductVariantPrices_UnitPrice CHECK ([UnitPrice] >= 0),
    CONSTRAINT CK_product_ProductVariantPrices_Discount CHECK ([DiscountPercent] IS NULL OR ([DiscountPercent] >= 0 AND [DiscountPercent] <= 100)),
    CONSTRAINT CK_product_ProductVariantPrices_EffectiveWindow CHECK (
        [EffectiveToUtc] IS NULL OR [EffectiveFromUtc] IS NULL OR [EffectiveToUtc] >= [EffectiveFromUtc]
    ),
    CONSTRAINT FK_product_ProductVariantPrices_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_ProductVariantPrices_PriceList FOREIGN KEY ([PriceListId]) REFERENCES [product].[PriceLists] ([PriceListId]),
    CONSTRAINT FK_product_ProductVariantPrices_Currency FOREIGN KEY ([CurrencyId]) REFERENCES [product].[Currencies] ([CurrencyId]),
    CONSTRAINT FK_product_ProductVariantPrices_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
    CONSTRAINT FK_product_ProductVariantPrices_Market FOREIGN KEY ([MarketId]) REFERENCES [product].[Markets] ([MarketId]),
    CONSTRAINT FK_product_ProductVariantPrices_Tier FOREIGN KEY ([PricingTierId]) REFERENCES [product].[PricingTiers] ([PricingTierId]),
    CONSTRAINT FK_product_ProductVariantPrices_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductVariantPrices_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductVariantPrices_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductVariantPrices]') AND name = N'IX_product_ProductVariantPrices_Variant_Currency')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductVariantPrices_Variant_Currency ON [product].[ProductVariantPrices] ([ProductVariantId], [CurrencyId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[CustomerProductPrices]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[CustomerProductPrices]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[CustomerProductPrices] (
    [CustomerProductPriceId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_CustomerProductPrices_PublicId DEFAULT (NEWSEQUENTIALID()),
    [CustomerId] BIGINT NOT NULL,
    [ProductId] BIGINT NULL,
    [ProductVariantId] BIGINT NULL,
    [CurrencyId] BIGINT NOT NULL,
    [MinimumQuantity] DECIMAL(18,4) NOT NULL CONSTRAINT DF_product_CustomerProductPrices_MinQty DEFAULT (1),
    [UnitPrice] DECIMAL(18,6) NOT NULL,
    [DiscountPercent] DECIMAL(9,4) NULL,
    [EffectiveFromUtc] DATETIME2(7) NULL,
    [EffectiveToUtc] DATETIME2(7) NULL,
    [ErpSourceReference] NVARCHAR(100) NULL,
    [LastErpSyncedAtUtc] DATETIME2(7) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_CustomerProductPrices_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CustomerProductPrices_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_CustomerProductPrices_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_CustomerProductPrices PRIMARY KEY CLUSTERED ([CustomerProductPriceId]),
    CONSTRAINT UQ_product_CustomerProductPrices_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_CustomerProductPrices_Owner CHECK (
        [ProductId] IS NOT NULL OR [ProductVariantId] IS NOT NULL
    ),
    CONSTRAINT CK_product_CustomerProductPrices_MinQty CHECK ([MinimumQuantity] > 0),
    CONSTRAINT CK_product_CustomerProductPrices_UnitPrice CHECK ([UnitPrice] >= 0),
    CONSTRAINT FK_product_CustomerProductPrices_Customer FOREIGN KEY ([CustomerId]) REFERENCES [customer].[Customers] ([CustomerId]),
    CONSTRAINT FK_product_CustomerProductPrices_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_CustomerProductPrices_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId]),
    CONSTRAINT FK_product_CustomerProductPrices_Currency FOREIGN KEY ([CurrencyId]) REFERENCES [product].[Currencies] ([CurrencyId]),
    CONSTRAINT FK_product_CustomerProductPrices_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_CustomerProductPrices_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_CustomerProductPrices_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[CustomerProductPrices]') AND name = N'IX_product_CustomerProductPrices_Customer')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_CustomerProductPrices_Customer ON [product].[CustomerProductPrices] ([CustomerId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductSlugs]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductSlugs]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductSlugs] (
    [ProductSlugId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductSlugs_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [RegionId] BIGINT NULL,
    [Slug] NVARCHAR(250) NOT NULL,
    [IsPrimary] BIT NOT NULL CONSTRAINT DF_product_ProductSlugs_IsPrimary DEFAULT (1),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductSlugs_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductSlugs_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductSlugs_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductSlugs PRIMARY KEY CLUSTERED ([ProductSlugId]),
    CONSTRAINT UQ_product_ProductSlugs_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductSlugs_Slug_NotEmpty CHECK (LEN(LTRIM(RTRIM([Slug]))) > 0),
    CONSTRAINT FK_product_ProductSlugs_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductSlugs_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
    CONSTRAINT FK_product_ProductSlugs_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductSlugs_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductSlugs_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductSlugs]') AND name = N'UX_product_ProductSlugs_Slug_Region_NotDeleted')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_product_ProductSlugs_Slug_Region_NotDeleted ON [product].[ProductSlugs] ([Slug], [RegionId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductSlugs]') AND name = N'IX_product_ProductSlugs_ProductId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductSlugs_ProductId ON [product].[ProductSlugs] ([ProductId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductSEO]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductSEO]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductSEO] (
    [ProductSEOId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductSEO_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [RegionId] BIGINT NULL,
    [PageTitle] NVARCHAR(200) NULL,
    [MetaDescription] NVARCHAR(500) NULL,
    [CanonicalUrl] NVARCHAR(2000) NULL,
    [OpenGraphTitle] NVARCHAR(200) NULL,
    [OpenGraphDescription] NVARCHAR(500) NULL,
    [OpenGraphImageUrl] NVARCHAR(2000) NULL,
    [SearchKeywords] NVARCHAR(1000) NULL,
    [StructuredDataJson] NVARCHAR(MAX) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductSEO_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductSEO_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductSEO_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductSEO PRIMARY KEY CLUSTERED ([ProductSEOId]),
    CONSTRAINT UQ_product_ProductSEO_PublicId UNIQUE ([PublicId]),
    CONSTRAINT FK_product_ProductSEO_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductSEO_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
    CONSTRAINT FK_product_ProductSEO_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductSEO_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductSEO_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductSEO]') AND name = N'UX_product_ProductSEO_Product_Region_NotDeleted')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_product_ProductSEO_Product_Region_NotDeleted ON [product].[ProductSEO] ([ProductId], [RegionId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductRegionalContent]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductRegionalContent]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductRegionalContent] (
    [ProductRegionalContentId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductRegionalContent_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [RegionId] BIGINT NOT NULL,
    [LanguageCode] NVARCHAR(10) NULL,
    [Headline] NVARCHAR(300) NULL,
    [Summary] NVARCHAR(1000) NULL,
    [BodyHtml] NVARCHAR(MAX) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductRegionalContent_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductRegionalContent_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductRegionalContent_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductRegionalContent PRIMARY KEY CLUSTERED ([ProductRegionalContentId]),
    CONSTRAINT UQ_product_ProductRegionalContent_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductRegionalContent_Product_Region_Lang UNIQUE ([ProductId], [RegionId], [LanguageCode]),
    CONSTRAINT FK_product_ProductRegionalContent_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductRegionalContent_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
    CONSTRAINT FK_product_ProductRegionalContent_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductRegionalContent_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductRegionalContent_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductContentSections]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductContentSections]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductContentSections] (
    [ProductContentSectionId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductContentSections_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [RegionId] BIGINT NULL,
    [SectionCode] NVARCHAR(50) NOT NULL,
    [SectionTitle] NVARCHAR(200) NOT NULL,
    [BodyHtml] NVARCHAR(MAX) NULL,
    [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductContentSections_DisplayOrder DEFAULT (0),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductContentSections_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductContentSections_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductContentSections_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductContentSections PRIMARY KEY CLUSTERED ([ProductContentSectionId]),
    CONSTRAINT UQ_product_ProductContentSections_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductContentSections_Product_Code_Region UNIQUE ([ProductId], [SectionCode], [RegionId]),
    CONSTRAINT FK_product_ProductContentSections_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductContentSections_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
    CONSTRAINT FK_product_ProductContentSections_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductContentSections_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductContentSections_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductContentSections]') AND name = N'IX_product_ProductContentSections_Product_DisplayOrder')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductContentSections_Product_DisplayOrder ON [product].[ProductContentSections] ([ProductId], [DisplayOrder]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductRedirects]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductRedirects]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductRedirects] (
    [ProductRedirectId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductRedirects_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NULL,
    [FromSlug] NVARCHAR(250) NOT NULL,
    [ToSlug] NVARCHAR(250) NOT NULL,
    [RegionId] BIGINT NULL,
    [HttpStatusCode] SMALLINT NOT NULL CONSTRAINT DF_product_ProductRedirects_HttpStatus DEFAULT (301),
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductRedirects_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductRedirects_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductRedirects_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductRedirects PRIMARY KEY CLUSTERED ([ProductRedirectId]),
    CONSTRAINT UQ_product_ProductRedirects_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductRedirects_From_NotEmpty CHECK (LEN(LTRIM(RTRIM([FromSlug]))) > 0),
    CONSTRAINT CK_product_ProductRedirects_To_NotEmpty CHECK (LEN(LTRIM(RTRIM([ToSlug]))) > 0),
    CONSTRAINT CK_product_ProductRedirects_HttpStatus CHECK ([HttpStatusCode] IN (301, 302, 307, 308)),
    CONSTRAINT FK_product_ProductRedirects_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductRedirects_Region FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]),
    CONSTRAINT FK_product_ProductRedirects_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductRedirects_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductRedirects_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductRedirects]') AND name = N'UX_product_ProductRedirects_FromSlug_Region_NotDeleted')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_product_ProductRedirects_FromSlug_Region_NotDeleted ON [product].[ProductRedirects] ([FromSlug], [RegionId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ExternalSystems]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ExternalSystems]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ExternalSystems] (
    [ExternalSystemId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ExternalSystems_PublicId DEFAULT (NEWSEQUENTIALID()),
    [SystemCode] NVARCHAR(50) NOT NULL,
    [SystemName] NVARCHAR(200) NOT NULL,
    [Description] NVARCHAR(1000) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ExternalSystems_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ExternalSystems_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ExternalSystems_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ExternalSystems PRIMARY KEY CLUSTERED ([ExternalSystemId]),
    CONSTRAINT UQ_product_ExternalSystems_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ExternalSystems_SystemCode UNIQUE ([SystemCode]),
    CONSTRAINT FK_product_ExternalSystems_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ExternalSystems_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ExternalSystems_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ExternalEntityMappings]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ExternalEntityMappings]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ExternalEntityMappings] (
    [ExternalEntityMappingId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ExternalEntityMappings_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ExternalSystemId] BIGINT NOT NULL,
    [EntityTypeCode] NVARCHAR(50) NOT NULL,
    [InternalEntityId] BIGINT NOT NULL,
    [ExternalEntityKey] NVARCHAR(200) NOT NULL,
    [ExternalPayloadJson] NVARCHAR(MAX) NULL,
    [LastSyncedAtUtc] DATETIME2(7) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ExternalEntityMappings_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ExternalEntityMappings_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ExternalEntityMappings_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ExternalEntityMappings PRIMARY KEY CLUSTERED ([ExternalEntityMappingId]),
    CONSTRAINT UQ_product_ExternalEntityMappings_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ExternalEntityMappings_System_Type_External UNIQUE ([ExternalSystemId], [EntityTypeCode], [ExternalEntityKey]),
    CONSTRAINT UQ_product_ExternalEntityMappings_System_Type_Internal UNIQUE ([ExternalSystemId], [EntityTypeCode], [InternalEntityId]),
    CONSTRAINT FK_product_ExternalEntityMappings_System FOREIGN KEY ([ExternalSystemId]) REFERENCES [product].[ExternalSystems] ([ExternalSystemId]),
    CONSTRAINT FK_product_ExternalEntityMappings_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ExternalEntityMappings_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ExternalEntityMappings_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ExternalEntityMappings]') AND name = N'IX_product_ExternalEntityMappings_Internal')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ExternalEntityMappings_Internal ON [product].[ExternalEntityMappings] ([EntityTypeCode], [InternalEntityId]) WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductIntegrationStatuses]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductIntegrationStatuses]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductIntegrationStatuses] (
    [ProductIntegrationStatusId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductIntegrationStatuses_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NOT NULL,
    [ExternalSystemId] BIGINT NOT NULL,
    [SyncStatusCode] NVARCHAR(30) NOT NULL,
    [LastSyncedAtUtc] DATETIME2(7) NULL,
    [LastErrorMessage] NVARCHAR(2000) NULL,
    
    [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductIntegrationStatuses_IsActive DEFAULT (1),
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductIntegrationStatuses_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [CreatedByUserId] BIGINT NULL,
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductIntegrationStatuses_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedByUserId] BIGINT NULL,
    [DeletedAtUtc] DATETIME2(7) NULL,
    [DeletedByUserId] BIGINT NULL,
    [RowVersion] ROWVERSION NOT NULL,
    CONSTRAINT PK_product_ProductIntegrationStatuses PRIMARY KEY CLUSTERED ([ProductIntegrationStatusId]),
    CONSTRAINT UQ_product_ProductIntegrationStatuses_PublicId UNIQUE ([PublicId]),
    CONSTRAINT UQ_product_ProductIntegrationStatuses_Product_System UNIQUE ([ProductId], [ExternalSystemId]),
    CONSTRAINT CK_product_ProductIntegrationStatuses_Status CHECK (
        [SyncStatusCode] IN (N'pending', N'synced', N'error', N'disabled')
    ),
    CONSTRAINT FK_product_ProductIntegrationStatuses_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductIntegrationStatuses_System FOREIGN KEY ([ExternalSystemId]) REFERENCES [product].[ExternalSystems] ([ExternalSystemId]),
    CONSTRAINT FK_product_ProductIntegrationStatuses_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductIntegrationStatuses_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
    CONSTRAINT FK_product_ProductIntegrationStatuses_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    /* ------------------------------------------------------------------
       [product].[ProductSyncLogs]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductSyncLogs]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductSyncLogs] (
    [ProductSyncLogId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductSyncLogs_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ExternalSystemId] BIGINT NOT NULL,
    [ProductId] BIGINT NULL,
    [CorrelationId] NVARCHAR(100) NULL,
    [DirectionCode] NVARCHAR(20) NOT NULL,
    [OperationCode] NVARCHAR(50) NOT NULL,
    [StatusCode] NVARCHAR(30) NOT NULL,
    [Message] NVARCHAR(2000) NULL,
    [PayloadJson] NVARCHAR(MAX) NULL,
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductSyncLogs_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT PK_product_ProductSyncLogs PRIMARY KEY CLUSTERED ([ProductSyncLogId]),
    CONSTRAINT UQ_product_ProductSyncLogs_PublicId UNIQUE ([PublicId]),
    CONSTRAINT CK_product_ProductSyncLogs_Direction CHECK ([DirectionCode] IN (N'inbound', N'outbound')),
    CONSTRAINT CK_product_ProductSyncLogs_Status CHECK ([StatusCode] IN (N'success', N'warning', N'error')),
    CONSTRAINT FK_product_ProductSyncLogs_System FOREIGN KEY ([ExternalSystemId]) REFERENCES [product].[ExternalSystems] ([ExternalSystemId]),
    CONSTRAINT FK_product_ProductSyncLogs_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductSyncLogs]') AND name = N'IX_product_ProductSyncLogs_CreatedAtUtc')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductSyncLogs_CreatedAtUtc ON [product].[ProductSyncLogs] ([CreatedAtUtc] DESC);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductSyncLogs]') AND name = N'IX_product_ProductSyncLogs_ProductId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductSyncLogs_ProductId ON [product].[ProductSyncLogs] ([ProductId]);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductSyncLogs]') AND name = N'IX_product_ProductSyncLogs_CorrelationId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductSyncLogs_CorrelationId ON [product].[ProductSyncLogs] ([CorrelationId]) WHERE [CorrelationId] IS NOT NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductAuditEvents]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductAuditEvents]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductAuditEvents] (
    [ProductAuditEventId] BIGINT IDENTITY(1,1) NOT NULL,
    [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductAuditEvents_PublicId DEFAULT (NEWSEQUENTIALID()),
    [ProductId] BIGINT NULL,
    [EntityTypeCode] NVARCHAR(50) NOT NULL,
    [EntityId] BIGINT NULL,
    [ActionCode] NVARCHAR(50) NOT NULL,
    [ActorUserId] BIGINT NULL,
    [CorrelationId] NVARCHAR(100) NULL,
    [SourceCode] NVARCHAR(50) NULL,
    [PreviousValuesJson] NVARCHAR(MAX) NULL,
    [NewValuesJson] NVARCHAR(MAX) NULL,
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductAuditEvents_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT PK_product_ProductAuditEvents PRIMARY KEY CLUSTERED ([ProductAuditEventId]),
    CONSTRAINT UQ_product_ProductAuditEvents_PublicId UNIQUE ([PublicId]),
    CONSTRAINT FK_product_ProductAuditEvents_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_product_ProductAuditEvents_Actor FOREIGN KEY ([ActorUserId]) REFERENCES [identity].[Users] ([UserId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductAuditEvents]') AND name = N'IX_product_ProductAuditEvents_Product_CreatedAt')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductAuditEvents_Product_CreatedAt ON [product].[ProductAuditEvents] ([ProductId], [CreatedAtUtc] DESC);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductAuditEvents]') AND name = N'IX_product_ProductAuditEvents_Entity')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductAuditEvents_Entity ON [product].[ProductAuditEvents] ([EntityTypeCode], [EntityId], [CreatedAtUtc] DESC);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[product].[ProductAuditEvents]') AND name = N'IX_product_ProductAuditEvents_CorrelationId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductAuditEvents_CorrelationId ON [product].[ProductAuditEvents] ([CorrelationId]) WHERE [CorrelationId] IS NOT NULL;
    END;


    /* ------------------------------------------------------------------
       [migration].[LegacyProductMappings]
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[migration].[LegacyProductMappings]', N'U') IS NULL
    BEGIN
        CREATE TABLE [migration].[LegacyProductMappings] (
    [LegacyProductMappingId] BIGINT IDENTITY(1,1) NOT NULL,
    [SourceSystemCode] NVARCHAR(50) NOT NULL,
    [SourceProductKey] NVARCHAR(200) NOT NULL,
    [ProductId] BIGINT NULL,
    [ProductVariantId] BIGINT NULL,
    [MigrationStatusCode] NVARCHAR(30) NOT NULL,
    [MigrationNotes] NVARCHAR(1000) NULL,
    [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyProductMappings_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_migration_LegacyProductMappings_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
    [MigratedAtUtc] DATETIME2(7) NULL,
    CONSTRAINT PK_migration_LegacyProductMappings PRIMARY KEY CLUSTERED ([LegacyProductMappingId]),
    CONSTRAINT UQ_migration_LegacyProductMappings_Source UNIQUE ([SourceSystemCode], [SourceProductKey]),
    CONSTRAINT CK_migration_LegacyProductMappings_Status CHECK (
        [MigrationStatusCode] IN (N'pending', N'imported', N'updated', N'skipped', N'failed', N'manual-review')
    ),
    CONSTRAINT FK_migration_LegacyProductMappings_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
    CONSTRAINT FK_migration_LegacyProductMappings_Variant FOREIGN KEY ([ProductVariantId]) REFERENCES [product].[ProductVariants] ([ProductVariantId])
);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[migration].[LegacyProductMappings]') AND name = N'IX_migration_LegacyProductMappings_Status')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_migration_LegacyProductMappings_Status ON [migration].[LegacyProductMappings] ([MigrationStatusCode]);
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'[migration].[LegacyProductMappings]') AND name = N'IX_migration_LegacyProductMappings_ProductId')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_migration_LegacyProductMappings_ProductId ON [migration].[LegacyProductMappings] ([ProductId]);
    END;


    /* Deferred foreign keys that depend on later tables */

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_product_ProductCodes_RuleSet')
BEGIN
    ALTER TABLE [product].[ProductCodes] WITH CHECK
    ADD CONSTRAINT FK_product_ProductCodes_RuleSet
        FOREIGN KEY ([SourceRuleSetId]) REFERENCES [product].[ProductCodeRuleSets] ([ProductCodeRuleSetId]);
END

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_product_ProductCodeRuleSegments_SpecificationDefinition')
BEGIN
    ALTER TABLE [product].[ProductCodeRuleSegments] WITH CHECK
    ADD CONSTRAINT FK_product_ProductCodeRuleSegments_SpecificationDefinition
        FOREIGN KEY ([SpecificationDefinitionId]) REFERENCES [product].[SpecificationDefinitions] ([SpecificationDefinitionId]);
END

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_product_ProductImages_Region')
BEGIN
    ALTER TABLE [product].[ProductImages] WITH CHECK
    ADD CONSTRAINT FK_product_ProductImages_Region
        FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]);
END

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_product_ProductVideos_Region')
BEGIN
    ALTER TABLE [product].[ProductVideos] WITH CHECK
    ADD CONSTRAINT FK_product_ProductVideos_Region
        FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]);
END

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_product_ProductARModels_Region')
BEGIN
    ALTER TABLE [product].[ProductARModels] WITH CHECK
    ADD CONSTRAINT FK_product_ProductARModels_Region
        FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]);
END

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_product_ProductCADFiles_Region')
BEGIN
    ALTER TABLE [product].[ProductCADFiles] WITH CHECK
    ADD CONSTRAINT FK_product_ProductCADFiles_Region
        FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]);
END

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_product_ProductDownloads_Region')
BEGIN
    ALTER TABLE [product].[ProductDownloads] WITH CHECK
    ADD CONSTRAINT FK_product_ProductDownloads_Region
        FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]);
END

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_product_ProductDocuments_Region')
BEGIN
    ALTER TABLE [product].[ProductDocuments] WITH CHECK
    ADD CONSTRAINT FK_product_ProductDocuments_Region
        FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId]);
END

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = N'FK_product_ProductDocuments_Market')
BEGIN
    ALTER TABLE [product].[ProductDocuments] WITH CHECK
    ADD CONSTRAINT FK_product_ProductDocuments_Market
        FOREIGN KEY ([MarketId]) REFERENCES [product].[Markets] ([MarketId]);
END


    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;

    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();

    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
