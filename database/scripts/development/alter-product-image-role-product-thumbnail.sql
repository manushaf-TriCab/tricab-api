-- Adds product-thumbnail image role for dedicated small-card thumbnails.
IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = N'CK_product_ProductImages_Role'
      AND parent_object_id = OBJECT_ID(N'[product].[ProductImages]')
)
BEGIN
    ALTER TABLE [product].[ProductImages] DROP CONSTRAINT [CK_product_ProductImages_Role];
END
GO

ALTER TABLE [product].[ProductImages] WITH CHECK ADD CONSTRAINT [CK_product_ProductImages_Role] CHECK (
    [ImageRoleCode] IN (
        N'primary',
        N'gallery',
        N'thumbnail',
        N'product-thumbnail',
        N'hero',
        N'diagram',
        N'cross-section',
        N'installation'
    )
);
GO
