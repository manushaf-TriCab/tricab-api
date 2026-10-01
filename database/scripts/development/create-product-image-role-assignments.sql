/*
  NON-PRODUCTION ONLY — tricab-mel / tc / APP_ENV=development

  Additive presentation-role assignment table so one ProductImage can hold
  multiple independent roles: product-thumbnail, primary, cross-section.

  Does NOT drop or alter ProductImages.ImageRoleCode / IsPrimary.

  Backfills unambiguous legacy ImageRoleCode (+ IsPrimary for primary).
*/

SET XACT_ABORT ON;
SET NOCOUNT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[product].[ProductImageRoleAssignments]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductImageRoleAssignments]
        (
            [ProductImageRoleAssignmentId] BIGINT IDENTITY(1,1) NOT NULL,
            [PublicId] UNIQUEIDENTIFIER NOT NULL
                CONSTRAINT [DF_product_ProductImageRoleAssignments_PublicId] DEFAULT (NEWSEQUENTIALID()),
            [ProductId] BIGINT NOT NULL,
            [ProductImagesId] BIGINT NOT NULL,
            [RoleCode] NVARCHAR(30) NOT NULL,
            [CreatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_product_ProductImageRoleAssignments_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_product_ProductImageRoleAssignments_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            [RowVersion] ROWVERSION NOT NULL,
            CONSTRAINT [PK_product_ProductImageRoleAssignments]
                PRIMARY KEY CLUSTERED ([ProductImageRoleAssignmentId]),
            CONSTRAINT [UQ_product_ProductImageRoleAssignments_PublicId]
                UNIQUE ([PublicId]),
            CONSTRAINT [UQ_product_ProductImageRoleAssignments_Product_Role]
                UNIQUE ([ProductId], [RoleCode]),
            CONSTRAINT [FK_product_ProductImageRoleAssignments_Product]
                FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
            CONSTRAINT [FK_product_ProductImageRoleAssignments_Image]
                FOREIGN KEY ([ProductImagesId]) REFERENCES [product].[ProductImages] ([ProductImagesId]),
            CONSTRAINT [FK_product_ProductImageRoleAssignments_CreatedBy]
                FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT [FK_product_ProductImageRoleAssignments_UpdatedBy]
                FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT [CK_product_ProductImageRoleAssignments_Role]
                CHECK ([RoleCode] IN (N'product-thumbnail', N'primary', N'primary-secondary', N'cross-section'))
        );
    END;

    IF NOT EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE name = N'IX_product_ProductImageRoleAssignments_Image'
          AND object_id = OBJECT_ID(N'[product].[ProductImageRoleAssignments]')
    )
    BEGIN
        CREATE NONCLUSTERED INDEX [IX_product_ProductImageRoleAssignments_Image]
            ON [product].[ProductImageRoleAssignments] ([ProductImagesId]);
    END;

    /* Ownership guard: ProductImages.ProductId must equal assignment.ProductId */
    IF OBJECT_ID(N'[product].[TR_ProductImageRoleAssignments_Ownership]', N'TR') IS NULL
    BEGIN
        EXEC(N'
CREATE TRIGGER [product].[TR_ProductImageRoleAssignments_Ownership]
ON [product].[ProductImageRoleAssignments]
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN [product].[ProductImages] img ON img.[ProductImagesId] = i.[ProductImagesId]
        WHERE img.[ProductId] <> i.[ProductId]
           OR img.[DeletedAtUtc] IS NOT NULL
    )
    BEGIN
        THROW 50010, ''ProductImageRoleAssignments: image must belong to the same product and not be deleted.'', 1;
    END;
END;
');
    END;

    /* Backfill product-thumbnail from ImageRoleCode */
    INSERT INTO [product].[ProductImageRoleAssignments] (
        [ProductId], [ProductImagesId], [RoleCode], [CreatedByUserId], [UpdatedByUserId]
    )
    SELECT
        img.[ProductId],
        img.[ProductImagesId],
        N'product-thumbnail',
        NULL,
        NULL
    FROM [product].[ProductImages] img
    WHERE img.[DeletedAtUtc] IS NULL
      AND img.[IsActive] = 1
      AND LOWER(img.[ImageRoleCode]) = N'product-thumbnail'
      AND NOT EXISTS (
          SELECT 1
          FROM [product].[ProductImageRoleAssignments] a
          WHERE a.[ProductId] = img.[ProductId]
            AND a.[RoleCode] = N'product-thumbnail'
      );

    /* Backfill primary from ImageRoleCode */
    INSERT INTO [product].[ProductImageRoleAssignments] (
        [ProductId], [ProductImagesId], [RoleCode], [CreatedByUserId], [UpdatedByUserId]
    )
    SELECT
        img.[ProductId],
        img.[ProductImagesId],
        N'primary',
        NULL,
        NULL
    FROM [product].[ProductImages] img
    WHERE img.[DeletedAtUtc] IS NULL
      AND img.[IsActive] = 1
      AND LOWER(img.[ImageRoleCode]) = N'primary'
      AND NOT EXISTS (
          SELECT 1
          FROM [product].[ProductImageRoleAssignments] a
          WHERE a.[ProductId] = img.[ProductId]
            AND a.[RoleCode] = N'primary'
      );

    /* Backfill cross-section from ImageRoleCode only (not diagram/construction aliases) */
    INSERT INTO [product].[ProductImageRoleAssignments] (
        [ProductId], [ProductImagesId], [RoleCode], [CreatedByUserId], [UpdatedByUserId]
    )
    SELECT
        img.[ProductId],
        img.[ProductImagesId],
        N'cross-section',
        NULL,
        NULL
    FROM [product].[ProductImages] img
    WHERE img.[DeletedAtUtc] IS NULL
      AND img.[IsActive] = 1
      AND LOWER(img.[ImageRoleCode]) = N'cross-section'
      AND NOT EXISTS (
          SELECT 1
          FROM [product].[ProductImageRoleAssignments] a
          WHERE a.[ProductId] = img.[ProductId]
            AND a.[RoleCode] = N'cross-section'
      );

    /* IsPrimary fallback for primary when no primary assignment yet */
    INSERT INTO [product].[ProductImageRoleAssignments] (
        [ProductId], [ProductImagesId], [RoleCode], [CreatedByUserId], [UpdatedByUserId]
    )
    SELECT
        img.[ProductId],
        img.[ProductImagesId],
        N'primary',
        NULL,
        NULL
    FROM [product].[ProductImages] img
    WHERE img.[DeletedAtUtc] IS NULL
      AND img.[IsActive] = 1
      AND img.[IsPrimary] = 1
      AND NOT EXISTS (
          SELECT 1
          FROM [product].[ProductImageRoleAssignments] a
          WHERE a.[ProductId] = img.[ProductId]
            AND a.[RoleCode] = N'primary'
      )
      AND NOT EXISTS (
          /* Prefer a single IsPrimary row per product when multiple exist */
          SELECT 1
          FROM [product].[ProductImages] other
          WHERE other.[ProductId] = img.[ProductId]
            AND other.[DeletedAtUtc] IS NULL
            AND other.[IsActive] = 1
            AND other.[IsPrimary] = 1
            AND other.[ProductImagesId] < img.[ProductImagesId]
      );

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

/* Report backfill counts */
SELECT
    a.[RoleCode],
    COUNT(*) AS [AssignmentCount]
FROM [product].[ProductImageRoleAssignments] a
GROUP BY a.[RoleCode]
ORDER BY a.[RoleCode];
GO
