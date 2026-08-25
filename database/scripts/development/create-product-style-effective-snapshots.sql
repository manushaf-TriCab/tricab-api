-- DESIGN ONLY — Effective ProductStyle read model (NOT applied in shared-data generalisation dry-run).
-- Safe additive schema proposal for development review.

IF OBJECT_ID(N'[product].[ProductStyleEffectiveSnapshots]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[ProductStyleEffectiveSnapshots] (
        [ProductStyleEffectiveSnapshotId] BIGINT IDENTITY(1,1) NOT NULL,
        [ProductStyleId] INT NOT NULL,
        [SnapshotVersion] BIGINT NOT NULL,
        [BuiltAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyleEffectiveSnapshots_BuiltAtUtc DEFAULT (SYSUTCDATETIME()),
        [IsCurrent] BIT NOT NULL CONSTRAINT DF_product_ProductStyleEffectiveSnapshots_IsCurrent DEFAULT (0),
        [BuildStatusCode] NVARCHAR(32) NOT NULL,
        [SourceHash] VARBINARY(32) NULL,
        CONSTRAINT PK_product_ProductStyleEffectiveSnapshots PRIMARY KEY CLUSTERED ([ProductStyleEffectiveSnapshotId]),
        CONSTRAINT FK_product_ProductStyleEffectiveSnapshots_ProductStyle
            FOREIGN KEY ([ProductStyleId]) REFERENCES [product].[ProductStyles] ([ProductStyleId])
    );
    CREATE UNIQUE INDEX UX_product_ProductStyleEffectiveSnapshots_Current
        ON [product].[ProductStyleEffectiveSnapshots] ([ProductStyleId])
        WHERE [IsCurrent] = 1;
END;

IF OBJECT_ID(N'[product].[ProductStyleEffectiveSpecificationValues]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[ProductStyleEffectiveSpecificationValues] (
        [ProductStyleEffectiveSpecificationValueId] BIGINT IDENTITY(1,1) NOT NULL,
        [ProductStyleEffectiveSnapshotId] BIGINT NOT NULL,
        [SpecificationDefinitionId] INT NOT NULL,
        [EffectiveValueText] NVARCHAR(2000) NULL,
        [SourceProductStyleId] INT NULL,
        [SourceStyleCode] NVARCHAR(32) NULL,
        [SourceLevel] INT NOT NULL,
        [Inherited] BIT NOT NULL,
        [SnapshotVersion] BIGINT NOT NULL,
        CONSTRAINT PK_product_ProductStyleEffectiveSpecificationValues PRIMARY KEY CLUSTERED ([ProductStyleEffectiveSpecificationValueId]),
        CONSTRAINT FK_product_ProductStyleEffectiveSpecificationValues_Snapshot
            FOREIGN KEY ([ProductStyleEffectiveSnapshotId]) REFERENCES [product].[ProductStyleEffectiveSnapshots] ([ProductStyleEffectiveSnapshotId])
    );
END;

IF OBJECT_ID(N'[product].[ProductStyleEffectiveMediaAssets]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[ProductStyleEffectiveMediaAssets] (
        [ProductStyleEffectiveMediaAssetId] BIGINT IDENTITY(1,1) NOT NULL,
        [ProductStyleEffectiveSnapshotId] BIGINT NOT NULL,
        [StoragePath] NVARCHAR(1024) NOT NULL,
        [ResourceTypeCode] NVARCHAR(32) NOT NULL,
        [RoleCode] NVARCHAR(64) NULL,
        [SourceProductStyleId] INT NULL,
        [SourceStyleCode] NVARCHAR(32) NULL,
        [Inherited] BIT NOT NULL,
        [SnapshotVersion] BIGINT NOT NULL,
        CONSTRAINT PK_product_ProductStyleEffectiveMediaAssets PRIMARY KEY CLUSTERED ([ProductStyleEffectiveMediaAssetId]),
        CONSTRAINT FK_product_ProductStyleEffectiveMediaAssets_Snapshot
            FOREIGN KEY ([ProductStyleEffectiveSnapshotId]) REFERENCES [product].[ProductStyleEffectiveSnapshots] ([ProductStyleEffectiveSnapshotId])
    );
END;

-- Proposed additive Style-level market ownership (source table, not effective):
IF OBJECT_ID(N'[product].[ProductStyleRegions]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[ProductStyleRegions] (
        [ProductStyleRegionId] INT IDENTITY(1,1) NOT NULL,
        [ProductStyleId] INT NOT NULL,
        [RegionId] INT NOT NULL,
        [IsVisible] BIT NOT NULL CONSTRAINT DF_product_ProductStyleRegions_IsVisible DEFAULT (1),
        [CreatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyleRegions_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductStyleRegions_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
        [DeletedAtUtc] DATETIME2(7) NULL,
        CONSTRAINT PK_product_ProductStyleRegions PRIMARY KEY CLUSTERED ([ProductStyleRegionId]),
        CONSTRAINT FK_product_ProductStyleRegions_ProductStyle
            FOREIGN KEY ([ProductStyleId]) REFERENCES [product].[ProductStyles] ([ProductStyleId]),
        CONSTRAINT FK_product_ProductStyleRegions_Region
            FOREIGN KEY ([RegionId]) REFERENCES [product].[Regions] ([RegionId])
    );
END;

IF OBJECT_ID(N'[product].[ProductStyleEffectiveRegions]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[ProductStyleEffectiveRegions] (
        [ProductStyleEffectiveRegionId] BIGINT IDENTITY(1,1) NOT NULL,
        [ProductStyleEffectiveSnapshotId] BIGINT NOT NULL,
        [RegionId] INT NOT NULL,
        [SourceProductStyleId] INT NULL,
        [SourceStyleCode] NVARCHAR(32) NULL,
        [Inherited] BIT NOT NULL,
        [SnapshotVersion] BIGINT NOT NULL,
        CONSTRAINT PK_product_ProductStyleEffectiveRegions PRIMARY KEY CLUSTERED ([ProductStyleEffectiveRegionId]),
        CONSTRAINT FK_product_ProductStyleEffectiveRegions_Snapshot
            FOREIGN KEY ([ProductStyleEffectiveSnapshotId]) REFERENCES [product].[ProductStyleEffectiveSnapshots] ([ProductStyleEffectiveSnapshotId])
    );
END;
