/*
================================================================================
  Additive development migration: filtered unique index for style specifications.

  Soft-deleted rows must not block replacing the same style+definition pair.
  Replaces the table UNIQUE constraint with a filtered unique index.

  DEVELOPMENT / additive only — does not modify WordPress or tc MySQL.
================================================================================
*/

SET NOCOUNT ON;

IF OBJECT_ID(N'[product].[ProductStyleSpecificationValues]', N'U') IS NULL
BEGIN
    THROW 50011, 'ProductStyleSpecificationValues does not exist. Apply create-product-style-hierarchy-schema.sql first.', 1;
END;

IF EXISTS (
    SELECT 1
    FROM sys.key_constraints
    WHERE name = N'UQ_product_ProductStyleSpecificationValues_Style_Definition'
      AND parent_object_id = OBJECT_ID(N'[product].[ProductStyleSpecificationValues]')
)
BEGIN
    ALTER TABLE [product].[ProductStyleSpecificationValues]
        DROP CONSTRAINT [UQ_product_ProductStyleSpecificationValues_Style_Definition];
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'UQ_product_ProductStyleSpecificationValues_Style_Definition'
      AND object_id = OBJECT_ID(N'[product].[ProductStyleSpecificationValues]')
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_product_ProductStyleSpecificationValues_Style_Definition
        ON [product].[ProductStyleSpecificationValues] ([ProductStyleId], [SpecificationDefinitionId])
        WHERE [DeletedAtUtc] IS NULL;
END;
