/*
  Additive product-page media layout.

  1. Allows one optional second left-panel image via presentation role
     primary-secondary. Existing role rows are unchanged.
  2. Adds ProductPageMediaSettings for the description-card image width and
     alignment. No row means the historical small, centred image.

  Does not update product image bytes or published copy.
  Apply before deploying API code that saves these settings.
  Reads tolerate a missing settings table and keep today's layout.
*/

SET XACT_ABORT ON;
SET NOCOUNT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[product].[ProductImageRoleAssignments]', N'U') IS NOT NULL
       AND EXISTS (
            SELECT 1
            FROM sys.check_constraints
            WHERE name = N'CK_product_ProductImageRoleAssignments_Role'
              AND parent_object_id = OBJECT_ID(N'[product].[ProductImageRoleAssignments]')
       )
       AND NOT EXISTS (
            SELECT 1
            FROM sys.check_constraints
            WHERE name = N'CK_product_ProductImageRoleAssignments_Role'
              AND parent_object_id = OBJECT_ID(N'[product].[ProductImageRoleAssignments]')
              AND definition LIKE N'%primary-secondary%'
       )
    BEGIN
        ALTER TABLE [product].[ProductImageRoleAssignments]
            DROP CONSTRAINT [CK_product_ProductImageRoleAssignments_Role];

        ALTER TABLE [product].[ProductImageRoleAssignments]
            ADD CONSTRAINT [CK_product_ProductImageRoleAssignments_Role]
                CHECK ([RoleCode] IN (
                    N'product-thumbnail',
                    N'primary',
                    N'primary-secondary',
                    N'cross-section'
                ));
    END;

    IF OBJECT_ID(N'[product].[ProductPageMediaSettings]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductPageMediaSettings]
        (
            [ProductId] BIGINT NOT NULL,
            [DescriptionImageWidth] NVARCHAR(16) NOT NULL
                CONSTRAINT [DF_product_ProductPageMediaSettings_Width] DEFAULT (N'small'),
            [DescriptionImageAlignment] NVARCHAR(16) NOT NULL
                CONSTRAINT [DF_product_ProductPageMediaSettings_Alignment] DEFAULT (N'centre'),
            [CreatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_product_ProductPageMediaSettings_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_product_ProductPageMediaSettings_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            CONSTRAINT [PK_product_ProductPageMediaSettings]
                PRIMARY KEY CLUSTERED ([ProductId]),
            CONSTRAINT [FK_product_ProductPageMediaSettings_Product]
                FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
            CONSTRAINT [FK_product_ProductPageMediaSettings_CreatedBy]
                FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT [FK_product_ProductPageMediaSettings_UpdatedBy]
                FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT [CK_product_ProductPageMediaSettings_Width]
                CHECK ([DescriptionImageWidth] IN (N'small', N'medium', N'full')),
            CONSTRAINT [CK_product_ProductPageMediaSettings_Alignment]
                CHECK ([DescriptionImageAlignment] IN (N'left', N'centre'))
        );
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
