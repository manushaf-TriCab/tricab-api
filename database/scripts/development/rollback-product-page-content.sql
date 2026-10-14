/*
  Rollback of alter-product-page-content.sql: drops [product].[ProductPageContentSettings].

  SAFE BY DEFAULT: refuses to drop while the table holds rows (administrator-entered settings would be lost).
  To drop anyway, set @AllowDropWithRows = 1 below after exporting the rows:
      SELECT * FROM [product].[ProductPageContentSettings];
  Touches nothing else: not [product].[Products], not [product].[ProductPageMediaSettings], not any specification value.
  The API tolerates the table being absent (reads return the defaults), so a rollback needs no API deployment.
  NOT executed by the feature work.
*/

SET XACT_ABORT ON;
SET NOCOUNT ON;

DECLARE @AllowDropWithRows BIT = 0;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[product].[ProductPageContentSettings]', N'U') IS NOT NULL
    BEGIN
        DECLARE @rows INT;
        SELECT @rows = COUNT(*) FROM [product].[ProductPageContentSettings];

        IF @rows > 0 AND @AllowDropWithRows = 0
        BEGIN
            THROW 50001, N'ProductPageContentSettings holds rows. Export them and set @AllowDropWithRows = 1 to drop.', 1;
        END;

        DROP TABLE [product].[ProductPageContentSettings];
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
