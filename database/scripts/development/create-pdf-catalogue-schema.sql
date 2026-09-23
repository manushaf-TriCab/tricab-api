/*
================================================================================
  PDF Catalogues schema — staff document library (NOT product catalogue navigation).

  DEVELOPMENT / non-production first.
  Idempotent create.

  Rollback:
    DROP TABLE [catalogue].[CatalogueRegions];
    DROP TABLE [catalogue].[Catalogues];
    DROP SCHEMA [catalogue];
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'catalogue')
BEGIN
    EXEC(N'CREATE SCHEMA [catalogue]');
    PRINT N'Created schema [catalogue].';
END
GO

BEGIN TRANSACTION;

IF OBJECT_ID(N'[catalogue].[Catalogues]', N'U') IS NULL
BEGIN
    CREATE TABLE [catalogue].[Catalogues] (
        [CatalogueId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_catalogue_Catalogues_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [SeedKey] NVARCHAR(100) NOT NULL,
        [Title] NVARCHAR(200) NOT NULL,
        [Issue] NVARCHAR(50) NOT NULL,
        [DocumentBlobPath] NVARCHAR(500) NOT NULL
            CONSTRAINT [DF_catalogue_Catalogues_DocumentBlobPath] DEFAULT (N''),
        [ThumbnailBlobPath] NVARCHAR(500) NULL,
        [FileName] NVARCHAR(255) NOT NULL
            CONSTRAINT [DF_catalogue_Catalogues_FileName] DEFAULT (N'catalogue.pdf'),
        [ContentType] NVARCHAR(100) NOT NULL
            CONSTRAINT [DF_catalogue_Catalogues_ContentType] DEFAULT (N'application/pdf'),
        [SortOrder] INT NOT NULL
            CONSTRAINT [DF_catalogue_Catalogues_SortOrder] DEFAULT (0),
        [IsActive] BIT NOT NULL
            CONSTRAINT [DF_catalogue_Catalogues_IsActive] DEFAULT (1),
        [DocumentAvailable] BIT NOT NULL
            CONSTRAINT [DF_catalogue_Catalogues_DocumentAvailable] DEFAULT (0),
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_catalogue_Catalogues_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_catalogue_Catalogues_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [DeletedAtUtc] DATETIME2(7) NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_catalogue_Catalogues] PRIMARY KEY CLUSTERED ([CatalogueId]),
        CONSTRAINT [UQ_catalogue_Catalogues_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_catalogue_Catalogues_SeedKey] UNIQUE ([SeedKey]),
        CONSTRAINT [CK_catalogue_Catalogues_SeedKey_NotEmpty]
            CHECK (LEN(LTRIM(RTRIM([SeedKey]))) > 0),
        CONSTRAINT [CK_catalogue_Catalogues_Title_NotEmpty]
            CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
        CONSTRAINT [CK_catalogue_Catalogues_Issue_NotEmpty]
            CHECK (LEN(LTRIM(RTRIM([Issue]))) > 0)
    );
    PRINT N'Created [catalogue].[Catalogues].';
END

IF OBJECT_ID(N'[catalogue].[CatalogueRegions]', N'U') IS NULL
BEGIN
    CREATE TABLE [catalogue].[CatalogueRegions] (
        [CatalogueRegionId] BIGINT IDENTITY(1,1) NOT NULL,
        [CatalogueId] BIGINT NOT NULL,
        [RegionCode] NVARCHAR(20) NOT NULL,
        [SortOrder] INT NOT NULL
            CONSTRAINT [DF_catalogue_CatalogueRegions_SortOrder] DEFAULT (0),
        CONSTRAINT [PK_catalogue_CatalogueRegions] PRIMARY KEY CLUSTERED ([CatalogueRegionId]),
        CONSTRAINT [UQ_catalogue_CatalogueRegions_Catalogue_Region]
            UNIQUE ([CatalogueId], [RegionCode]),
        CONSTRAINT [CK_catalogue_CatalogueRegions_RegionCode]
            CHECK ([RegionCode] IN (N'AU_NZ', N'ASIA', N'USA')),
        CONSTRAINT [FK_catalogue_CatalogueRegions_Catalogue]
            FOREIGN KEY ([CatalogueId]) REFERENCES [catalogue].[Catalogues] ([CatalogueId])
    );
    PRINT N'Created [catalogue].[CatalogueRegions].';
END

COMMIT TRANSACTION;
GO
