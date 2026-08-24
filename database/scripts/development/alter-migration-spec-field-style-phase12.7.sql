/*
================================================================================
  Phase 12.7 — Style-specific SpecificationFieldMappings support

  Phase 12.6 proved d1–d7 / v1–v4 meanings vary by style. Existing unique key
  (SourceSystem, SourceTable, SourceField) cannot store style-specific rows.

  Additive: LegacyStyleCode NVARCHAR(50) NULL + replace unique constraint.
  Safe to re-run. No Product data changes.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'[migration].[SpecificationFieldMappings]', N'U') IS NULL
BEGIN
    THROW 50002, N'[migration].[SpecificationFieldMappings] missing — apply create-product-migration-schema.sql first.', 1;
END;

IF COL_LENGTH(N'migration.SpecificationFieldMappings', N'LegacyStyleCode') IS NULL
BEGIN
    ALTER TABLE [migration].[SpecificationFieldMappings]
        ADD [LegacyStyleCode] NVARCHAR(50) NULL;
    PRINT N'Added LegacyStyleCode column.';
END
ELSE
BEGIN
    PRINT N'LegacyStyleCode already present.';
END
GO

IF EXISTS (
    SELECT 1 FROM sys.key_constraints
    WHERE [name] = N'UQ_migration_SpecificationFieldMappings_Source'
      AND [parent_object_id] = OBJECT_ID(N'[migration].[SpecificationFieldMappings]')
)
BEGIN
    ALTER TABLE [migration].[SpecificationFieldMappings]
        DROP CONSTRAINT [UQ_migration_SpecificationFieldMappings_Source];
    PRINT N'Dropped old unique constraint.';
END
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.key_constraints
    WHERE [name] = N'UQ_migration_SpecificationFieldMappings_SourceStyle'
      AND [parent_object_id] = OBJECT_ID(N'[migration].[SpecificationFieldMappings]')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX [UQ_migration_SpecificationFieldMappings_SourceStyle]
        ON [migration].[SpecificationFieldMappings] (
            [SourceSystem],
            [SourceTable],
            [SourceField],
            [LegacyStyleCode]
        );
    PRINT N'Created style-aware unique index.';
END
ELSE
BEGIN
    PRINT N'Style-aware unique index already present.';
END
GO
