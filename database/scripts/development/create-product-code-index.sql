/*
  [product].[ProductCodeIndex] — a code-level searchable universe.

  The catalogue is product- and variant-shaped, so nothing today can answer a
  question at the level of a single nine-segment product code. This table is that
  projection: one row per code in the legacy tpro_ext universe, with every segment
  split into its own typed column.

  Two things it exists to fix:

  1. CSA is stored as `text` in the EAV specification tables, so "between 4 and 25
     mm²" is not expressible against them. [CrossSectionMm2] is a DECIMAL
     projection that makes numeric range queries possible.
  2. Only ~4,600 of the ~19,000 codes correspond to a loaded ProductVariant.
     [ProductVariantId] links the ones that do; the rest stay code-only, so search
     and validation can still see them.

  Populated by ../../../../catalog-load/build_azure_code_index_load.py, which
  generates a MERGE script. This file only creates the shape.

  Safe to re-run (IF OBJECT_ID / IF NOT EXISTS guards).
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF OBJECT_ID(N'[product].[ProductCodeIndex]', N'U') IS NULL
BEGIN
    CREATE TABLE [product].[ProductCodeIndex] (
        [CodeValue] NVARCHAR(200) NOT NULL,

        /* ---- the nine segments, as parsed from [CodeValue] ---- */
        /* <style><jacket>-<coreColour><braidArmourScreen><conductor>/<count><coreType><csa><colour> */
        [StyleCode] NVARCHAR(4) NOT NULL,
        [StyleSuffix] NVARCHAR(16) NULL,
        [JacketTypeCode] NVARCHAR(4) NULL,
        [CoreColourConfig] NVARCHAR(4) NULL,
        [BraidArmourScreen] NVARCHAR(4) NULL,
        [ConductorCode] NVARCHAR(4) NULL,
        [CoreCount] INT NULL,
        [CoreTypeCode] NVARCHAR(4) NULL,
        [CrossSectionRaw] NVARCHAR(16) NULL,
        [JacketColour] NVARCHAR(8) NULL,
        /* Characters past the conductor in zone 2, and past the colour in zone 3.
           The grammar allows both (FZ-DEGDA, CX-BVA-PVXD, codes ending 'X'), and
           parse_code drops them. Kept so [CodeValue] rebuilds exactly from its own
           segments — the round-trip assertion in bin/apply-product-code-index.php. */
        [CoreConfigTail] NVARCHAR(16) NULL,
        [CodeTail] NVARCHAR(16) NULL,

        /* ---- derived, and the reason this table exists ---- */
        /* Segment 8 cannot be read without segment 7. Under CoreTypeCode 'C' the
           value is mm²; under 'A' it is an AWG gauge, an AWG aught (1/0..4/0) or an
           MCM/kcmil value; under 'P'/'T'/'Q' the unit is not stated at all and the
           4Js rule applies — a value over 6 is imperial. So `16` is 16 mm² under
           'C' and 1.309 mm² (AWG 16) under 'A'. Ten values collide across 'C' and
           'A': 1, 4, 6, 8, 10, 16, 300, 400, 500, 1000. */
        [CrossSectionUnit] NVARCHAR(8) NULL,
        [CrossSectionMm2] DECIMAL(10,3) NULL,
        /* TriCab's own nominal metric equivalent, where the 4Js csamap declares one
           (AWG 16 is sold as the 1.5 mm² equivalent even though its true area is
           1.309). Search should prefer this and fall back to CrossSectionMm2;
           derating maths must use CrossSectionMm2. NULL where no equivalence is
           declared. */
        [CrossSectionNominalMm2] DECIMAL(10,3) NULL,
        /* Conductors per set: 1 for C/A, 2 for a pair, 3 for a triad, 4 for a quad.
           CoreCount counts sets, not conductors, so a 4P code has 8 conductors. */
        [SetCores] INT NULL,

        /* ---- provenance and commercial status ---- */
        [StockStatus] NVARCHAR(16) NULL,
        [IsCurrent] BIT NOT NULL CONSTRAINT DF_product_ProductCodeIndex_IsCurrent DEFAULT (0),
        [HasParseAnomaly] BIT NOT NULL CONSTRAINT DF_product_ProductCodeIndex_HasParseAnomaly DEFAULT (0),
        [WeightKgPerKm] DECIMAL(18,3) NULL,
        [Desc2] NVARCHAR(400) NULL,
        [LegacyProductId] NVARCHAR(64) NULL,

        /* ---- link to the loaded catalogue, where one exists ---- */
        [ProductVariantId] BIGINT NULL,

        [SourceFile] NVARCHAR(128) NULL,
        [LoadedAtUtc] DATETIME2(7) NOT NULL CONSTRAINT DF_product_ProductCodeIndex_LoadedAtUtc DEFAULT (SYSUTCDATETIME()),

        CONSTRAINT PK_product_ProductCodeIndex PRIMARY KEY CLUSTERED ([CodeValue]),
        CONSTRAINT CK_product_ProductCodeIndex_Code_NotEmpty CHECK (LEN(LTRIM(RTRIM([CodeValue]))) > 0),
        CONSTRAINT CK_product_ProductCodeIndex_CoreCount CHECK ([CoreCount] IS NULL OR [CoreCount] > 0),
        CONSTRAINT CK_product_ProductCodeIndex_Csa CHECK ([CrossSectionMm2] IS NULL OR [CrossSectionMm2] > 0),
        CONSTRAINT CK_product_ProductCodeIndex_CsaUnit CHECK (
            [CrossSectionUnit] IS NULL OR [CrossSectionUnit] IN (N'mm2', N'awg', N'mcm')
        ),
        CONSTRAINT CK_product_ProductCodeIndex_StockStatus CHECK (
            [StockStatus] IS NULL OR [StockStatus] IN (N'STOCK', N'MAKE', N'NOT FOUND')
        ),
        CONSTRAINT FK_product_ProductCodeIndex_Variant FOREIGN KEY ([ProductVariantId])
            REFERENCES [product].[ProductVariants] ([ProductVariantId])
    );
END;

/* ------------------------------------------------------------------------
   Column top-up.

   The CREATE above is guarded by IF OBJECT_ID IS NULL, which makes it
   idempotent for the table but not for its shape: on an existing table a newly
   added column would silently never appear, and the load would then fail with
   "Invalid column name". While this table is still being shaped, adding columns
   here keeps one script authoritative instead of accreting alter-*.sql files.
   ------------------------------------------------------------------------ */
IF COL_LENGTH(N'[product].[ProductCodeIndex]', N'CoreConfigTail') IS NULL
    ALTER TABLE [product].[ProductCodeIndex] ADD [CoreConfigTail] NVARCHAR(16) NULL;

IF COL_LENGTH(N'[product].[ProductCodeIndex]', N'CrossSectionNominalMm2') IS NULL
    ALTER TABLE [product].[ProductCodeIndex] ADD [CrossSectionNominalMm2] DECIMAL(10,3) NULL;

IF COL_LENGTH(N'[product].[ProductCodeIndex]', N'SourceFile') IS NULL
    ALTER TABLE [product].[ProductCodeIndex] ADD [SourceFile] NVARCHAR(128) NULL;

/* Range queries on size, the whole point of the decimal projection. */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[ProductCodeIndex]')
      AND name = N'IX_product_ProductCodeIndex_Csa'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_ProductCodeIndex_Csa
        ON [product].[ProductCodeIndex] ([CrossSectionMm2], [CoreCount])
        INCLUDE ([StyleCode], [CoreTypeCode], [StockStatus]);
END;

/* Style-first filtering — the shape of nearly every faceted search. */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[ProductCodeIndex]')
      AND name = N'IX_product_ProductCodeIndex_Style_Cores_Csa'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_ProductCodeIndex_Style_Cores_Csa
        ON [product].[ProductCodeIndex] ([StyleCode], [CoreCount], [CrossSectionMm2])
        INCLUDE ([JacketColour], [BraidArmourScreen], [ConductorCode], [StockStatus]);
END;

/* Stocked-first ordering, and the STOCK/MAKE-only filter. */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[ProductCodeIndex]')
      AND name = N'IX_product_ProductCodeIndex_StockStatus'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_ProductCodeIndex_StockStatus
        ON [product].[ProductCodeIndex] ([StockStatus])
        INCLUDE ([StyleCode], [CrossSectionMm2], [CoreCount]);
END;

/* Reverse lookup: given a loaded variant, which code is it. */
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[ProductCodeIndex]')
      AND name = N'IX_product_ProductCodeIndex_Variant'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_ProductCodeIndex_Variant
        ON [product].[ProductCodeIndex] ([ProductVariantId])
        WHERE [ProductVariantId] IS NOT NULL;
END;

COMMIT TRANSACTION;
