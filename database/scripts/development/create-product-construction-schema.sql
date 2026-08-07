/*
================================================================================
  Additive development migration: cable construction layers.

  Creates:
    [product].[ConstructionLayerTypes]     closed vocabulary of layer roles
    [product].[ProductConstructionLayers]  the ordered layer stack per product

  Why this needs its own tables. style_construction in the MySQL catalog is an
  ordered list of typed layers with a material paragraph each -- the construction
  table printed on a datasheet, so it is catalog-page data and belongs here. But
  product.* has no ordered-child-rows-under-a-product concept, and neither
  workaround is acceptable:

    ProductContentSections   one HTML blob per product. Destroys the
                             queryability the source tables were built to give,
                             and re-creates the string-rot problem elsewhere.

    Positional definitions   CONSTRUCTION-L1..L5 as specification definitions.
    (CONSTRUCTION-L1..)      Caps stack depth, and layer 3 means something
                             different on every style.

  Conventions follow create-product-schema.sql: BIGINT IDENTITY clustered PK,
  PublicId UNIQUEIDENTIFIER, IsActive + soft delete, full audit columns,
  RowVersion, filtered indexes, idempotent guards.

  Companion doc: tricab-core/docs/product-catalog-load-source-mapping.md section 7.1

  DEVELOPMENT / additive only -- creates nothing outside [product] and does not
  modify legacy MySQL data.
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


    /* ------------------------------------------------------------------
       [product].[ConstructionLayerTypes]

       A closed list rather than a free-text column, for the same reason the
       catalog's `standard` table is a closed list: the source already carries
       a defect that a constraint would have caught. style_construction writes
       'BRAID/AMOUR' -- AMOUR for ARMOUR -- and an unconstrained column would
       carry that typo onto a customer-facing catalog page.

       LegacySourceCode records the token the source actually writes, so the
       rename is auditable and the loader can map on it rather than guessing.
       It is not the key; only ARMOUR-BRAID currently differs from its code.

       DisplayOrder is physical build order, centre outward -- conductor first,
       sheath last -- which is the order a datasheet prints the stack in.
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ConstructionLayerTypes]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ConstructionLayerTypes] (
            [ConstructionLayerTypeId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL
                CONSTRAINT DF_product_ConstructionLayerTypes_PublicId DEFAULT (NEWSEQUENTIALID()),
            [LayerTypeCode] NVARCHAR(30) NOT NULL,
            [LayerTypeName] NVARCHAR(200) NOT NULL,
            [Description] NVARCHAR(1000) NULL,
            [LegacySourceCode] NVARCHAR(30) NULL,
            [DisplayOrder] INT NOT NULL
                CONSTRAINT DF_product_ConstructionLayerTypes_DisplayOrder DEFAULT (0),

            [IsActive] BIT NOT NULL
                CONSTRAINT DF_product_ConstructionLayerTypes_IsActive DEFAULT (1),
            [CreatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT DF_product_ConstructionLayerTypes_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT DF_product_ConstructionLayerTypes_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            [DeletedAtUtc] DATETIME2(7) NULL,
            [DeletedByUserId] BIGINT NULL,
            [RowVersion] ROWVERSION NOT NULL,

            CONSTRAINT PK_product_ConstructionLayerTypes
                PRIMARY KEY CLUSTERED ([ConstructionLayerTypeId]),
            CONSTRAINT UQ_product_ConstructionLayerTypes_PublicId UNIQUE ([PublicId]),
            CONSTRAINT UQ_product_ConstructionLayerTypes_Code UNIQUE ([LayerTypeCode]),
            CONSTRAINT CK_product_ConstructionLayerTypes_Name_NotEmpty
                CHECK (LEN(LTRIM(RTRIM([LayerTypeName]))) > 0),
            CONSTRAINT FK_product_ConstructionLayerTypes_CreatedBy
                FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ConstructionLayerTypes_UpdatedBy
                FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ConstructionLayerTypes_DeletedBy
                FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
        );
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE object_id = OBJECT_ID(N'[product].[ConstructionLayerTypes]')
                     AND name = N'UX_product_ConstructionLayerTypes_LegacyCode')
    BEGIN
        CREATE UNIQUE NONCLUSTERED INDEX UX_product_ConstructionLayerTypes_LegacyCode
            ON [product].[ConstructionLayerTypes] ([LegacySourceCode])
            WHERE [LegacySourceCode] IS NOT NULL AND [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       [product].[ProductConstructionLayers]

       LayerNumber is the source's own numbering over populated layers only.
       It is deliberately NOT dense: catalog-load holds layers back when their
       standard fails validation and keeps the number, so BHA legitimately
       starts at layer 2. A gap is information, not corruption -- do not
       renumber on load, and do not use layer count as a completeness check.

       MaterialText is the full material paragraph, which runs long -- the
       marine sheath text is ~200 characters. NVARCHAR(1000) leaves headroom.
       ------------------------------------------------------------------ */
    IF OBJECT_ID(N'[product].[ProductConstructionLayers]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductConstructionLayers] (
            [ProductConstructionLayerId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL
                CONSTRAINT DF_product_ProductConstructionLayers_PublicId DEFAULT (NEWSEQUENTIALID()),
            [ProductId] BIGINT NOT NULL,
            [ConstructionLayerTypeId] BIGINT NOT NULL,
            [LayerNumber] SMALLINT NOT NULL,
            [MaterialText] NVARCHAR(1000) NULL,

            [IsActive] BIT NOT NULL
                CONSTRAINT DF_product_ProductConstructionLayers_IsActive DEFAULT (1),
            [CreatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT DF_product_ProductConstructionLayers_CreatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT DF_product_ProductConstructionLayers_UpdatedAtUtc DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            [DeletedAtUtc] DATETIME2(7) NULL,
            [DeletedByUserId] BIGINT NULL,
            [RowVersion] ROWVERSION NOT NULL,

            CONSTRAINT PK_product_ProductConstructionLayers
                PRIMARY KEY CLUSTERED ([ProductConstructionLayerId]),
            CONSTRAINT UQ_product_ProductConstructionLayers_PublicId UNIQUE ([PublicId]),
            CONSTRAINT UQ_product_ProductConstructionLayers_Product_Layer
                UNIQUE ([ProductId], [LayerNumber]),
            CONSTRAINT CK_product_ProductConstructionLayers_LayerNumber
                CHECK ([LayerNumber] > 0),
            CONSTRAINT FK_product_ProductConstructionLayers_Product
                FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
            CONSTRAINT FK_product_ProductConstructionLayers_LayerType
                FOREIGN KEY ([ConstructionLayerTypeId])
                REFERENCES [product].[ConstructionLayerTypes] ([ConstructionLayerTypeId]),
            CONSTRAINT FK_product_ProductConstructionLayers_CreatedBy
                FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ProductConstructionLayers_UpdatedBy
                FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT FK_product_ProductConstructionLayers_DeletedBy
                FOREIGN KEY ([DeletedByUserId]) REFERENCES [identity].[Users] ([UserId])
        );
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE object_id = OBJECT_ID(N'[product].[ProductConstructionLayers]')
                     AND name = N'IX_product_ProductConstructionLayers_Product_Layer')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductConstructionLayers_Product_Layer
            ON [product].[ProductConstructionLayers] ([ProductId], [LayerNumber])
            WHERE [DeletedAtUtc] IS NULL;
    END;


    IF NOT EXISTS (SELECT 1 FROM sys.indexes
                   WHERE object_id = OBJECT_ID(N'[product].[ProductConstructionLayers]')
                     AND name = N'IX_product_ProductConstructionLayers_LayerType')
    BEGIN
        CREATE NONCLUSTERED INDEX IX_product_ProductConstructionLayers_LayerType
            ON [product].[ProductConstructionLayers] ([ConstructionLayerTypeId])
            WHERE [DeletedAtUtc] IS NULL;
    END;


    /* ------------------------------------------------------------------
       Layer type seed

       Two sources, merged:

         in use    the six values style_construction actually writes across
                   the 25 marine styles (CONDUCTOR, INS, BED, HQSCR,
                   BRAID/AMOUR, SHE)

         declared  the six layer roles catalog-load/sql/01_schema.sql adds to
                   descript type 71 for the V2 datasheet, marine and LV
                   sources (CONSCR, INSSCR, METSCR, HQSCR, SEP, NYL)

       HQSCR appears in both, so eleven rows. ARMOUR-BRAID is the one
       canonicalised code -- LegacySourceCode carries 'BRAID/AMOUR' so the
       loader maps on it.

       INCOMPLETE BY KNOWN AMOUNT: descript type 71 already carried seven
       roles before catalog-load added to it, and those seven are not
       enumerated anywhere reachable from this repo. Some are certainly the
       codes below; any that are not will surface as a foreign key violation
       on load, which is the intended behaviour -- a failed insert rather
       than an unrecognised layer role on a datasheet.
       ------------------------------------------------------------------ */
    MERGE [product].[ConstructionLayerTypes] AS t
    USING (VALUES
        (N'CONDUCTOR',    N'Conductor',           N'Current-carrying conductor.',                         NULL,            10),
        (N'CONSCR',       N'Conductor Screen',    N'Semi-conductive screen over the conductor.',          NULL,            20),
        (N'INS',          N'Insulation',          N'Primary insulation.',                                 NULL,            30),
        (N'INSSCR',       N'Insulation Screen',   N'Semi-conductive screen over the insulation.',         NULL,            40),
        (N'SEP',          N'Separator',           N'Separator tape or barrier layer.',                    NULL,            50),
        (N'METSCR',       N'Metallic Screen',     N'Metallic screen or foil.',                            NULL,            60),
        (N'HQSCR',        N'HQ Screening',        N'Individual or collective screen with drain wire.',    NULL,            70),
        (N'BED',          N'Bedding',             N'Bedding layer beneath armour or braid.',              NULL,            80),
        (N'ARMOUR-BRAID', N'Armour / Braid',      N'Wire braid or armour for mechanical or EMC protection.', N'BRAID/AMOUR', 90),
        (N'NYL',          N'Nylon',               N'Nylon layer.',                                        NULL,           100),
        (N'SHE',          N'Sheath',              N'Outer sheath / jacket.',                              NULL,           110)
    ) AS s ([LayerTypeCode], [LayerTypeName], [Description], [LegacySourceCode], [DisplayOrder])
    ON t.[LayerTypeCode] = s.[LayerTypeCode]
    WHEN NOT MATCHED THEN
        INSERT ([LayerTypeCode], [LayerTypeName], [Description], [LegacySourceCode], [DisplayOrder], [IsActive])
        VALUES (s.[LayerTypeCode], s.[LayerTypeName], s.[Description], s.[LegacySourceCode], s.[DisplayOrder], 1);

    COMMIT TRANSACTION;

    /* ------------------------------------------------------------------
       Verification
       ------------------------------------------------------------------ */
    SELECT
        (SELECT COUNT(*) FROM [product].[ConstructionLayerTypes])                                        AS LayerTypeCount,
        (SELECT COUNT(*) FROM [product].[ConstructionLayerTypes] WHERE [LegacySourceCode] IS NOT NULL)   AS RenamedFromSource,
        (SELECT COUNT(*) FROM [product].[ProductConstructionLayers])                                     AS ConstructionLayerCount;

    SELECT [LayerTypeCode], [LayerTypeName], ISNULL([LegacySourceCode], N'-') AS [LegacySourceCode]
    FROM [product].[ConstructionLayerTypes]
    ORDER BY [DisplayOrder];
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
