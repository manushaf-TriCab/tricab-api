/*
================================================================================
  Technical Document Library — Phase 4F foundation (ADDITIVE ONLY)

  Target: NON-PRODUCTION (tricab-mel / tc / development)
  DO NOT APPLY TO PRODUCTION in Phase 4F.
  DO NOT migrate document bytes or insert legacy rows in this phase.

  Architecture (Option C refined):
    - product.ProductDocuments remains product-owned (ProductId NOT NULL).
    - technical.Documents is the authoritative Technical-browse library.
    - Optional Product / Style links are associations, not ownership.
    - Blob bytes reuse ProductBlobStore under products/technical/documents/...
    - product.DocumentTypes reused by FK (no new DocumentTypes).

  Rollback:
    DROP TABLE technical.DocumentStyleLinks;
    DROP TABLE technical.DocumentProductLinks;
    DROP TABLE technical.DocumentCategoryLinks;
    DROP TABLE technical.Documents;
    DROP TABLE technical.DocumentCategories;
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'technical')
BEGIN
    EXEC(N'CREATE SCHEMA [technical]');
    PRINT N'Created schema [technical].';
END
GO

BEGIN TRANSACTION;

IF OBJECT_ID(N'[technical].[DocumentCategories]', N'U') IS NULL
BEGIN
    CREATE TABLE [technical].[DocumentCategories] (
        [DocumentCategoryId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_technical_DocumentCategories_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [CategoryCode] NVARCHAR(50) NOT NULL,
        [Title] NVARCHAR(200) NOT NULL,
        [SectionCode] NVARCHAR(50) NOT NULL,
        [DisplayOrder] INT NOT NULL
            CONSTRAINT [DF_technical_DocumentCategories_DisplayOrder] DEFAULT (0),
        [IsActive] BIT NOT NULL
            CONSTRAINT [DF_technical_DocumentCategories_IsActive] DEFAULT (1),
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_technical_DocumentCategories_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_technical_DocumentCategories_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedByUserId] BIGINT NULL,
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_technical_DocumentCategories] PRIMARY KEY CLUSTERED ([DocumentCategoryId]),
        CONSTRAINT [UQ_technical_DocumentCategories_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [UQ_technical_DocumentCategories_CategoryCode] UNIQUE ([CategoryCode]),
        CONSTRAINT [CK_technical_DocumentCategories_CategoryCode_NotEmpty]
            CHECK (LEN(LTRIM(RTRIM([CategoryCode]))) > 0),
        CONSTRAINT [CK_technical_DocumentCategories_Title_NotEmpty]
            CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
        CONSTRAINT [CK_technical_DocumentCategories_SectionCode]
            CHECK ([SectionCode] IN (N'general', N'industrial', N'sbo', N'accessories')),
        CONSTRAINT [FK_technical_DocumentCategories_CreatedBy]
            FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT [FK_technical_DocumentCategories_UpdatedBy]
            FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT [FK_technical_DocumentCategories_DeletedBy]
            FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );
    PRINT N'Created [technical].[DocumentCategories].';
END

IF OBJECT_ID(N'[technical].[Documents]', N'U') IS NULL
BEGIN
    CREATE TABLE [technical].[Documents] (
        [DocumentId] BIGINT IDENTITY(1,1) NOT NULL,
        [PublicId] UNIQUEIDENTIFIER NOT NULL
            CONSTRAINT [DF_technical_Documents_PublicId] DEFAULT (NEWSEQUENTIALID()),
        [DocumentTypeId] BIGINT NOT NULL,
        [Title] NVARCHAR(200) NOT NULL,
        [Issuer] NVARCHAR(200) NULL,
        [OriginalFileName] NVARCHAR(255) NOT NULL,
        [StorageProviderCode] NVARCHAR(30) NOT NULL
            CONSTRAINT [DF_technical_Documents_StorageProvider] DEFAULT (N'azure-blob'),
        [StorageContainer] NVARCHAR(200) NULL,
        [StoragePath] NVARCHAR(1000) NOT NULL,
        [PublicUrl] NVARCHAR(2000) NULL,
        [ContentType] NVARCHAR(150) NULL,
        [FileExtension] NVARCHAR(20) NULL,
        [ByteSize] BIGINT NULL,
        [ChecksumSha256] NVARCHAR(64) NULL,
        [AccessCode] NVARCHAR(20) NOT NULL
            CONSTRAINT [DF_technical_Documents_AccessCode] DEFAULT (N'authenticated'),
        [SourceSystemCode] NVARCHAR(50) NULL,
        [SourceLegacyId] NVARCHAR(100) NULL,
        [DisplayOrder] INT NOT NULL
            CONSTRAINT [DF_technical_Documents_DisplayOrder] DEFAULT (0),
        [IsActive] BIT NOT NULL
            CONSTRAINT [DF_technical_Documents_IsActive] DEFAULT (1),
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_technical_Documents_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [UpdatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_technical_Documents_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [UpdatedByUserId] BIGINT NULL,
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_technical_Documents] PRIMARY KEY CLUSTERED ([DocumentId]),
        CONSTRAINT [UQ_technical_Documents_PublicId] UNIQUE ([PublicId]),
        CONSTRAINT [CK_technical_Documents_Title_NotEmpty]
            CHECK (LEN(LTRIM(RTRIM([Title]))) > 0),
        CONSTRAINT [CK_technical_Documents_OriginalFileName_NotEmpty]
            CHECK (LEN(LTRIM(RTRIM([OriginalFileName]))) > 0),
        CONSTRAINT [CK_technical_Documents_StoragePath_NotEmpty]
            CHECK (LEN(LTRIM(RTRIM([StoragePath]))) > 0),
        CONSTRAINT [CK_technical_Documents_AccessCode]
            CHECK ([AccessCode] IN (N'public', N'restricted', N'authenticated')),
        CONSTRAINT [FK_technical_Documents_DocumentType]
            FOREIGN KEY ([DocumentTypeId]) REFERENCES [product].[DocumentTypes] ([DocumentTypeId]),
        CONSTRAINT [FK_technical_Documents_CreatedBy]
            FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT [FK_technical_Documents_UpdatedBy]
            FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT [FK_technical_Documents_DeletedBy]
            FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );

    CREATE NONCLUSTERED INDEX [IX_technical_Documents_Checksum]
        ON [technical].[Documents] ([ChecksumSha256])
        WHERE [DeletedAtUtc] IS NULL AND [ChecksumSha256] IS NOT NULL;

    CREATE NONCLUSTERED INDEX [IX_technical_Documents_SourceLegacy]
        ON [technical].[Documents] ([SourceSystemCode], [SourceLegacyId])
        WHERE [DeletedAtUtc] IS NULL;

    PRINT N'Created [technical].[Documents].';
END

IF OBJECT_ID(N'[technical].[DocumentCategoryLinks]', N'U') IS NULL
BEGIN
    CREATE TABLE [technical].[DocumentCategoryLinks] (
        [DocumentCategoryLinkId] BIGINT IDENTITY(1,1) NOT NULL,
        [DocumentId] BIGINT NOT NULL,
        [DocumentCategoryId] BIGINT NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_technical_DocumentCategoryLinks_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_technical_DocumentCategoryLinks] PRIMARY KEY CLUSTERED ([DocumentCategoryLinkId]),
        CONSTRAINT [FK_technical_DocumentCategoryLinks_Document]
            FOREIGN KEY ([DocumentId]) REFERENCES [technical].[Documents] ([DocumentId]),
        CONSTRAINT [FK_technical_DocumentCategoryLinks_Category]
            FOREIGN KEY ([DocumentCategoryId]) REFERENCES [technical].[DocumentCategories] ([DocumentCategoryId]),
        CONSTRAINT [FK_technical_DocumentCategoryLinks_CreatedBy]
            FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT [FK_technical_DocumentCategoryLinks_DeletedBy]
            FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );

    CREATE UNIQUE NONCLUSTERED INDEX [UQ_technical_DocumentCategoryLinks_Active]
        ON [technical].[DocumentCategoryLinks] ([DocumentId], [DocumentCategoryId])
        WHERE [DeletedAtUtc] IS NULL;

    PRINT N'Created [technical].[DocumentCategoryLinks].';
END

IF OBJECT_ID(N'[technical].[DocumentProductLinks]', N'U') IS NULL
BEGIN
    CREATE TABLE [technical].[DocumentProductLinks] (
        [DocumentProductLinkId] BIGINT IDENTITY(1,1) NOT NULL,
        [DocumentId] BIGINT NOT NULL,
        [ProductId] BIGINT NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_technical_DocumentProductLinks_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_technical_DocumentProductLinks] PRIMARY KEY CLUSTERED ([DocumentProductLinkId]),
        CONSTRAINT [FK_technical_DocumentProductLinks_Document]
            FOREIGN KEY ([DocumentId]) REFERENCES [technical].[Documents] ([DocumentId]),
        CONSTRAINT [FK_technical_DocumentProductLinks_Product]
            FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
        CONSTRAINT [FK_technical_DocumentProductLinks_CreatedBy]
            FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT [FK_technical_DocumentProductLinks_DeletedBy]
            FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );

    CREATE UNIQUE NONCLUSTERED INDEX [UQ_technical_DocumentProductLinks_Active]
        ON [technical].[DocumentProductLinks] ([DocumentId], [ProductId])
        WHERE [DeletedAtUtc] IS NULL;

    CREATE NONCLUSTERED INDEX [IX_technical_DocumentProductLinks_Product]
        ON [technical].[DocumentProductLinks] ([ProductId])
        WHERE [DeletedAtUtc] IS NULL;

    PRINT N'Created [technical].[DocumentProductLinks].';
END

IF OBJECT_ID(N'[technical].[DocumentStyleLinks]', N'U') IS NULL
BEGIN
    CREATE TABLE [technical].[DocumentStyleLinks] (
        [DocumentStyleLinkId] BIGINT IDENTITY(1,1) NOT NULL,
        [DocumentId] BIGINT NOT NULL,
        [ProductStyleId] BIGINT NOT NULL,
        [CreatedAtUtc] DATETIME2(7) NOT NULL
            CONSTRAINT [DF_technical_DocumentStyleLinks_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
        [CreatedByUserId] BIGINT NULL,
        [DeletedAtUtc] DATETIME2(7) NULL,
        [DeletedByUserId] BIGINT NULL,
        [RowVersion] ROWVERSION NOT NULL,
        CONSTRAINT [PK_technical_DocumentStyleLinks] PRIMARY KEY CLUSTERED ([DocumentStyleLinkId]),
        CONSTRAINT [FK_technical_DocumentStyleLinks_Document]
            FOREIGN KEY ([DocumentId]) REFERENCES [technical].[Documents] ([DocumentId]),
        CONSTRAINT [FK_technical_DocumentStyleLinks_Style]
            FOREIGN KEY ([ProductStyleId]) REFERENCES [product].[ProductStyles] ([ProductStyleId]),
        CONSTRAINT [FK_technical_DocumentStyleLinks_CreatedBy]
            FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
        CONSTRAINT [FK_technical_DocumentStyleLinks_DeletedBy]
            FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
    );

    CREATE UNIQUE NONCLUSTERED INDEX [UQ_technical_DocumentStyleLinks_Active]
        ON [technical].[DocumentStyleLinks] ([DocumentId], [ProductStyleId])
        WHERE [DeletedAtUtc] IS NULL;

    CREATE NONCLUSTERED INDEX [IX_technical_DocumentStyleLinks_Style]
        ON [technical].[DocumentStyleLinks] ([ProductStyleId])
        WHERE [DeletedAtUtc] IS NULL;

    PRINT N'Created [technical].[DocumentStyleLinks].';
END

COMMIT TRANSACTION;
GO

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF OBJECT_ID(N'[technical].[DocumentCategories]', N'U') IS NOT NULL
BEGIN
    ;WITH seed AS (
        SELECT *
        FROM (VALUES
            (N'warranty', N'Warranty Certificates', N'general', 10),
            (N'bep-pvc-disposal', N'BEP PVC Disposal', N'general', 20),
            (N'bep-pvc-certificate', N'BEP PVC Certificate', N'general', 30),
            (N'industrial-certs', N'Industrial Certificates', N'industrial', 40),
            (N'sbo-certs', N'Shipboard / Offshore / Onshore Certificates', N'sbo', 50),
            (N'accessory-certs', N'Accessory Certificates', N'accessories', 60),
            (N'accessory-warranty', N'Accessory Warranty Certificates', N'accessories', 70),
            (N'installation-guides', N'Installation Guides', N'accessories', 80)
        ) AS v([CategoryCode], [Title], [SectionCode], [DisplayOrder])
    )
    INSERT INTO [technical].[DocumentCategories] (
        [CategoryCode], [Title], [SectionCode], [DisplayOrder]
    )
    SELECT s.[CategoryCode], s.[Title], s.[SectionCode], s.[DisplayOrder]
    FROM seed s
    WHERE NOT EXISTS (
        SELECT 1
        FROM [technical].[DocumentCategories] c
        WHERE c.[CategoryCode] = s.[CategoryCode]
          AND c.[DeletedAtUtc] IS NULL
    );

    PRINT N'Seeded [technical].[DocumentCategories] (idempotent).';
END

COMMIT TRANSACTION;
GO
