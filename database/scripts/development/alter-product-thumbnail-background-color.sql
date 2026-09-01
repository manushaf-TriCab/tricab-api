-- Per-product thumbnail card background colour (presentation metadata only).
IF COL_LENGTH(N'[product].[Products]', N'ThumbnailBackgroundColor') IS NULL
BEGIN
    ALTER TABLE [product].[Products]
        ADD [ThumbnailBackgroundColor] NVARCHAR(7) NULL;
END
GO
