/*
================================================================================
  Approval Certificates — additive columns on existing specification masters

  Reuses:
    [product].[SpecificationOptions]           certificate master
    [product].[ProductSpecificationOptionValues] product assignment

  Adds:
    SpecificationOptions.ImageUrl              shared certificate logo
    ProductSpecificationOptionValues.StatusCode  approved | pending

  No new tables. No DROP. No legacy MySQL. DEVELOPMENT / non-production only.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF COL_LENGTH(N'product.SpecificationOptions', N'ImageUrl') IS NULL
BEGIN
    ALTER TABLE [product].[SpecificationOptions]
        ADD [ImageUrl] NVARCHAR(2000) NULL;
    PRINT N'Added [product].[SpecificationOptions].[ImageUrl].';
END
ELSE
BEGIN
    PRINT N'[product].[SpecificationOptions].[ImageUrl] already present.';
END

IF COL_LENGTH(N'product.ProductSpecificationOptionValues', N'StatusCode') IS NULL
BEGIN
    ALTER TABLE [product].[ProductSpecificationOptionValues]
        ADD [StatusCode] NVARCHAR(30) NOT NULL
            CONSTRAINT DF_product_ProductSpecificationOptionValues_StatusCode DEFAULT (N'approved');
    PRINT N'Added [product].[ProductSpecificationOptionValues].[StatusCode].';
END
ELSE
BEGIN
    PRINT N'[product].[ProductSpecificationOptionValues].[StatusCode] already present.';
END

COMMIT TRANSACTION;
GO

/* A new batch is required so Azure SQL compiles references to added columns. */
SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

IF COL_LENGTH(N'product.ProductSpecificationOptionValues', N'StatusCode') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.check_constraints
        WHERE name = N'CK_product_ProductSpecificationOptionValues_StatusCode'
          AND parent_object_id = OBJECT_ID(N'[product].[ProductSpecificationOptionValues]')
   )
BEGIN
    ALTER TABLE [product].[ProductSpecificationOptionValues]
        ADD CONSTRAINT CK_product_ProductSpecificationOptionValues_StatusCode
        CHECK ([StatusCode] IN (N'approved', N'pending'));
    PRINT N'Added StatusCode check constraint.';
END

COMMIT TRANSACTION;
GO
