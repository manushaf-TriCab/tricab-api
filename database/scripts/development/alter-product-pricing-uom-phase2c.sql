/*
================================================================================
  Alter: Product Pricing UOM — Phase 2C (additive)

  Adds authoritative unit-of-measure support for base-cost pricing:
    - Products.PricingUnitId          (product default)
    - ProductVariants.PricingUnitId   (optional variant override)
    - ProductPrices.SpecificationUnitId
    - ProductVariantPrices.SpecificationUnitId

  Resolution order at read time:
    price row → variant → product → system default (M / m)

  Review and run manually in non-production after backup.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF COL_LENGTH(N'product.Products', N'PricingUnitId') IS NULL
    BEGIN
        ALTER TABLE [product].[Products]
            ADD [PricingUnitId] BIGINT NULL;

        ALTER TABLE [product].[Products]
            ADD CONSTRAINT FK_product_Products_PricingUnit
                FOREIGN KEY ([PricingUnitId])
                REFERENCES [product].[SpecificationUnits] ([SpecificationUnitId]);
    END;

    IF COL_LENGTH(N'product.ProductVariants', N'PricingUnitId') IS NULL
    BEGIN
        ALTER TABLE [product].[ProductVariants]
            ADD [PricingUnitId] BIGINT NULL;

        ALTER TABLE [product].[ProductVariants]
            ADD CONSTRAINT FK_product_ProductVariants_PricingUnit
                FOREIGN KEY ([PricingUnitId])
                REFERENCES [product].[SpecificationUnits] ([SpecificationUnitId]);
    END;

    IF COL_LENGTH(N'product.ProductPrices', N'SpecificationUnitId') IS NULL
    BEGIN
        ALTER TABLE [product].[ProductPrices]
            ADD [SpecificationUnitId] BIGINT NULL;

        ALTER TABLE [product].[ProductPrices]
            ADD CONSTRAINT FK_product_ProductPrices_SpecificationUnit
                FOREIGN KEY ([SpecificationUnitId])
                REFERENCES [product].[SpecificationUnits] ([SpecificationUnitId]);
    END;

    IF COL_LENGTH(N'product.ProductVariantPrices', N'SpecificationUnitId') IS NULL
    BEGIN
        ALTER TABLE [product].[ProductVariantPrices]
            ADD [SpecificationUnitId] BIGINT NULL;

        ALTER TABLE [product].[ProductVariantPrices]
            ADD CONSTRAINT FK_product_ProductVariantPrices_SpecificationUnit
                FOREIGN KEY ([SpecificationUnitId])
                REFERENCES [product].[SpecificationUnits] ([SpecificationUnitId]);
    END;

    COMMIT TRANSACTION;
    PRINT N'Phase 2C pricing UOM columns applied.';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;

    THROW;
END CATCH;
