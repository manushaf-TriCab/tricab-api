/*
================================================================================
  Additive development migration: Product Style Hierarchy + Inheritance.

  Creates style-code hierarchy independent of Family/Category/Series and of
  Catalogue Navigation. Inheritance is resolved dynamically — this schema
  stores explicit parent links and style-owned overrides only.

  DEVELOPMENT / additive only — does not modify WordPress or tc MySQL.
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

    IF OBJECT_ID(N'[product].[ProductStyles]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductStyles] (
            [ProductStyleId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductStyles_PublicId DEFAULT (NEWSEQUENTIALID()),
            [StyleCode] NVARCHAR(50) NOT NULL,
            [DisplayName] NVARCHAR(200) NOT NULL,
            [ParentStyleId] BIGINT NULL,
            [SortOrder] INT NOT NULL CONSTRAINT DF_product_ProductStyles_SortOrder DEFAULT (0),
            [SpecificationsInheritanceMode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductStyles_SpecMode DEFAULT (N'LOCAL'),
            [ImagesInheritanceMode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductStyles_ImagesMode DEFAULT (N'LOCAL'),
            [DocumentsInheritanceMode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductStyles_DocsMode DEFAULT (N'LOCAL'),
            [Spin360InheritanceMode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductStyles_360Mode DEFAULT (N'LOCAL'),
            [Models3DInheritanceMode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductStyles_3DMode DEFAULT (N'LOCAL'),
            [ArAssetsInheritanceMode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductStyles_ArMode DEFAULT (N'LOCAL'),
            [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductStyles_IsActive DEFAULT (1),
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyles_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyles_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            [DeletedAtUtc] DATETIME2(7) NULL,
            [DeletedByUserId] BIGINT NULL,
            [RowVersion] ROWVERSION NOT NULL,
            CONSTRAINT PK_product_ProductStyles PRIMARY KEY CLUSTERED ([ProductStyleId]),
            CONSTRAINT UQ_product_ProductStyles_PublicId UNIQUE ([PublicId]),
            CONSTRAINT UQ_product_ProductStyles_StyleCode UNIQUE ([StyleCode]),
            CONSTRAINT CK_product_ProductStyles_Code_NotEmpty CHECK (LEN(LTRIM(RTRIM([StyleCode]))) > 0),
            CONSTRAINT CK_product_ProductStyles_Name_NotEmpty CHECK (LEN(LTRIM(RTRIM([DisplayName]))) > 0),
            CONSTRAINT CK_product_ProductStyles_NotSelfParent CHECK ([ParentStyleId] IS NULL OR [ParentStyleId] <> [ProductStyleId]),
            CONSTRAINT CK_product_ProductStyles_SpecMode CHECK ([SpecificationsInheritanceMode] IN (N'INHERIT', N'INHERIT_AND_EXTEND', N'LOCAL')),
            CONSTRAINT CK_product_ProductStyles_ImagesMode CHECK ([ImagesInheritanceMode] IN (N'INHERIT', N'INHERIT_AND_EXTEND', N'LOCAL')),
            CONSTRAINT CK_product_ProductStyles_DocsMode CHECK ([DocumentsInheritanceMode] IN (N'INHERIT', N'INHERIT_AND_EXTEND', N'LOCAL')),
            CONSTRAINT CK_product_ProductStyles_360Mode CHECK ([Spin360InheritanceMode] IN (N'INHERIT', N'INHERIT_AND_EXTEND', N'LOCAL')),
            CONSTRAINT CK_product_ProductStyles_3DMode CHECK ([Models3DInheritanceMode] IN (N'INHERIT', N'INHERIT_AND_EXTEND', N'LOCAL')),
            CONSTRAINT CK_product_ProductStyles_ArMode CHECK ([ArAssetsInheritanceMode] IN (N'INHERIT', N'INHERIT_AND_EXTEND', N'LOCAL')),
            CONSTRAINT FK_product_ProductStyles_Parent FOREIGN KEY ([ParentStyleId]) REFERENCES [product].[ProductStyles] ([ProductStyleId]),
            CONSTRAINT FK_product_ProductStyles_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ProductStyles_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ProductStyles_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_product_ProductStyles_Parent' AND object_id = OBJECT_ID(N'[product].[ProductStyles]'))
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductStyles_Parent
            ON [product].[ProductStyles] ([ParentStyleId], [SortOrder])
            WHERE [DeletedAtUtc] IS NULL;
    END;

    IF OBJECT_ID(N'[product].[TR_ProductStyles_PreventCycle]', N'TR') IS NULL
    BEGIN
        EXEC(N'
CREATE TRIGGER [product].[TR_ProductStyles_PreventCycle]
ON [product].[ProductStyles]
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    IF NOT UPDATE([ParentStyleId]) RETURN;

    IF EXISTS (
        SELECT 1 FROM inserted
        WHERE [ParentStyleId] IS NOT NULL AND [ParentStyleId] = [ProductStyleId]
    )
    BEGIN
        THROW 50001, ''A style cannot be its own parent.'', 1;
    END;

    DECLARE @cycle INT = 0;
    ;WITH walk AS (
        SELECT i.[ProductStyleId] AS [OriginId], i.[ParentStyleId] AS [WalkId], 0 AS [Depth]
        FROM inserted i
        WHERE i.[ParentStyleId] IS NOT NULL
        UNION ALL
        SELECT w.[OriginId], s.[ParentStyleId], w.[Depth] + 1
        FROM walk w
        INNER JOIN [product].[ProductStyles] s ON s.[ProductStyleId] = w.[WalkId]
        WHERE w.[WalkId] IS NOT NULL AND w.[Depth] < 64
    )
    SELECT @cycle = 1 FROM walk WHERE [WalkId] = [OriginId];

    IF @cycle = 1
    BEGIN
        THROW 50002, ''Circular style hierarchy is not allowed.'', 1;
    END;
END;
');
    END;

    IF OBJECT_ID(N'[product].[ProductStyleAssignments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductStyleAssignments] (
            [ProductId] BIGINT NOT NULL,
            [ProductStyleId] BIGINT NOT NULL,
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyleAssignments_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyleAssignments_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            CONSTRAINT PK_product_ProductStyleAssignments PRIMARY KEY CLUSTERED ([ProductId]),
            CONSTRAINT FK_product_ProductStyleAssignments_Product FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
            CONSTRAINT FK_product_ProductStyleAssignments_Style FOREIGN KEY ([ProductStyleId]) REFERENCES [product].[ProductStyles] ([ProductStyleId]),
            CONSTRAINT FK_product_ProductStyleAssignments_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ProductStyleAssignments_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId])
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_product_ProductStyleAssignments_Style' AND object_id = OBJECT_ID(N'[product].[ProductStyleAssignments]'))
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductStyleAssignments_Style
            ON [product].[ProductStyleAssignments] ([ProductStyleId]);
    END;

    IF OBJECT_ID(N'[product].[ProductStyleSpecificationValues]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductStyleSpecificationValues] (
            [ProductStyleSpecificationValueId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductStyleSpecificationValues_PublicId DEFAULT (NEWSEQUENTIALID()),
            [ProductStyleId] BIGINT NOT NULL,
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
            [SpecificationOptionId] BIGINT NULL,
            [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductStyleSpecificationValues_IsActive DEFAULT (1),
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyleSpecificationValues_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyleSpecificationValues_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            [DeletedAtUtc] DATETIME2(7) NULL,
            [DeletedByUserId] BIGINT NULL,
            [RowVersion] ROWVERSION NOT NULL,
            CONSTRAINT PK_product_ProductStyleSpecificationValues PRIMARY KEY CLUSTERED ([ProductStyleSpecificationValueId]),
            CONSTRAINT UQ_product_ProductStyleSpecificationValues_PublicId UNIQUE ([PublicId]),
            CONSTRAINT FK_product_ProductStyleSpecificationValues_Style FOREIGN KEY ([ProductStyleId]) REFERENCES [product].[ProductStyles] ([ProductStyleId]),
            CONSTRAINT FK_product_ProductStyleSpecificationValues_Definition FOREIGN KEY ([SpecificationDefinitionId]) REFERENCES [product].[SpecificationDefinitions] ([SpecificationDefinitionId]),
            CONSTRAINT FK_product_ProductStyleSpecificationValues_Option FOREIGN KEY ([SpecificationOptionId]) REFERENCES [product].[SpecificationOptions] ([SpecificationOptionId]),
            CONSTRAINT FK_product_ProductStyleSpecificationValues_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ProductStyleSpecificationValues_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ProductStyleSpecificationValues_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
        );
    END;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = N'UQ_product_ProductStyleSpecificationValues_Style_Definition'
          AND object_id = OBJECT_ID(N'[product].[ProductStyleSpecificationValues]')
    )
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UQ_product_ProductStyleSpecificationValues_Style_Definition
            ON [product].[ProductStyleSpecificationValues] ([ProductStyleId], [SpecificationDefinitionId])
            WHERE [DeletedAtUtc] IS NULL;
    END;

    IF OBJECT_ID(N'[product].[ProductStyleMediaAssets]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductStyleMediaAssets] (
            [ProductStyleMediaAssetId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_product_ProductStyleMediaAssets_PublicId DEFAULT (NEWSEQUENTIALID()),
            [ProductStyleId] BIGINT NOT NULL,
            [ResourceTypeCode] NVARCHAR(30) NOT NULL,
            [Title] NVARCHAR(200) NOT NULL,
            [RoleCode] NVARCHAR(50) NULL,
            [AltText] NVARCHAR(500) NULL,
            [StorageProviderCode] NVARCHAR(30) NOT NULL CONSTRAINT DF_product_ProductStyleMediaAssets_StorageProvider DEFAULT (N'azure-blob'),
            [StorageContainer] NVARCHAR(200) NULL,
            [StoragePath] NVARCHAR(1000) NULL,
            [PublicUrl] NVARCHAR(2000) NULL,
            [ContentType] NVARCHAR(150) NULL,
            [ChecksumSha256] NVARCHAR(64) NULL,
            [DisplayOrder] INT NOT NULL CONSTRAINT DF_product_ProductStyleMediaAssets_DisplayOrder DEFAULT (0),
            [IsPrimary] BIT NOT NULL CONSTRAINT DF_product_ProductStyleMediaAssets_IsPrimary DEFAULT (0),
            [IsActive] BIT NOT NULL CONSTRAINT DF_product_ProductStyleMediaAssets_IsActive DEFAULT (1),
            [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyleMediaAssets_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyleMediaAssets_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            [DeletedAtUtc] DATETIME2(7) NULL,
            [DeletedByUserId] BIGINT NULL,
            [RowVersion] ROWVERSION NOT NULL,
            CONSTRAINT PK_product_ProductStyleMediaAssets PRIMARY KEY CLUSTERED ([ProductStyleMediaAssetId]),
            CONSTRAINT UQ_product_ProductStyleMediaAssets_PublicId UNIQUE ([PublicId]),
            CONSTRAINT CK_product_ProductStyleMediaAssets_Title_NotEmpty CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
            CONSTRAINT CK_product_ProductStyleMediaAssets_Type CHECK (
                [ResourceTypeCode] IN (N'IMAGES', N'DOCUMENTS', N'SPIN_360', N'MODELS_3D', N'AR_ASSETS')
            ),
            CONSTRAINT FK_product_ProductStyleMediaAssets_Style FOREIGN KEY ([ProductStyleId]) REFERENCES [product].[ProductStyles] ([ProductStyleId]),
            CONSTRAINT FK_product_ProductStyleMediaAssets_CreatedBy FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ProductStyleMediaAssets_UpdatedBy FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ProductStyleMediaAssets_DeletedBy FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = N'IX_product_ProductStyleMediaAssets_Style_Type' AND object_id = OBJECT_ID(N'[product].[ProductStyleMediaAssets]'))
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductStyleMediaAssets_Style_Type
            ON [product].[ProductStyleMediaAssets] ([ProductStyleId], [ResourceTypeCode], [DisplayOrder])
            WHERE [DeletedAtUtc] IS NULL;
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
