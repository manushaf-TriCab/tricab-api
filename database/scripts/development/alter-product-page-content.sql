/*
  Additive per-product Typical Applications panel settings (Product Marking visibility, Additional Core Colours Note).

  Creates [product].[ProductPageContentSettings]. No row means: Product Marking OFF, no note, note shown (only when it has text) -
  i.e. every existing product (published or not) renders exactly as before until an administrator enables a section.
  The migration inserts NO rows, so it cannot expose anything. ShowProductMarking defaults to 0 (off) even if a row is written
  without it. UpdatedAtUtc doubles as the optimistic-concurrency token the API returns as `version`.
  The Product Marking TEXT stays in the existing PRODUCT-MARKING product specification; the note is NOT part of CORE-COLOURS.

  Does not touch any existing table, specification value, variant, media, publication or hierarchy row.
  Apply before deploying API code that SAVES these settings. Reads tolerate a missing table.
  NOT executed by the feature work: production application needs explicit approval.
*/

SET XACT_ABORT ON;
SET NOCOUNT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[product].[ProductPageContentSettings]', N'U') IS NULL
    BEGIN
        CREATE TABLE [product].[ProductPageContentSettings]
        (
            [ProductId] BIGINT NOT NULL,
            [ShowProductMarking] BIT NOT NULL
                CONSTRAINT [DF_product_ProductPageContentSettings_ShowMarking] DEFAULT (0),
            [CoreColoursNote] NVARCHAR(500) NULL,
            [ShowCoreColoursNote] BIT NOT NULL
                CONSTRAINT [DF_product_ProductPageContentSettings_ShowNote] DEFAULT (1),
            [CreatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_product_ProductPageContentSettings_CreatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [CreatedByUserId] BIGINT NULL,
            [UpdatedAtUtc] DATETIME2(7) NOT NULL
                CONSTRAINT [DF_product_ProductPageContentSettings_UpdatedAtUtc] DEFAULT (SYSUTCDATETIME()),
            [UpdatedByUserId] BIGINT NULL,
            CONSTRAINT [PK_product_ProductPageContentSettings]
                PRIMARY KEY CLUSTERED ([ProductId]),
            CONSTRAINT [FK_product_ProductPageContentSettings_Product]
                FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId]),
            CONSTRAINT [FK_product_ProductPageContentSettings_CreatedBy]
                FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId]),
            CONSTRAINT [FK_product_ProductPageContentSettings_UpdatedBy]
                FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId])
        );
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
