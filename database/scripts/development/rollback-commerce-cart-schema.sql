/*
  Phase 13.7A — rollback commerce cart schema (NON-PRODUCTION)

  Drops commerce.CartLines then commerce.Carts only.
  Never touches pricing / product / customer tables.

  Refuses when OPEN or SUBMITTED carts exist unless @Force = 1.
*/

SET XACT_ABORT ON;
SET NOCOUNT ON;

DECLARE @Force BIT = 0; /* set 1 to force drop despite OPEN/SUBMITTED rows */

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'[commerce].[Carts]', N'U') IS NOT NULL
    BEGIN
        DECLARE @Blocking INT = 0;
        SELECT @Blocking = COUNT(*)
        FROM [commerce].[Carts]
        WHERE [Status] IN (N'OPEN', N'SUBMITTED')
          AND [DeletedAtUtc] IS NULL;

        IF @Blocking > 0 AND @Force = 0
        BEGIN
            DECLARE @Msg NVARCHAR(400) = N'Refuse cart schema rollback: '
                + CAST(@Blocking AS NVARCHAR(20))
                + N' OPEN/SUBMITTED cart(s) exist. Re-run with @Force=1 to override.';
            THROW 50001, @Msg, 1;
        END;
    END;

    IF OBJECT_ID(N'[commerce].[CartLines]', N'U') IS NOT NULL
    BEGIN
        DROP TABLE [commerce].[CartLines];
    END;

    IF OBJECT_ID(N'[commerce].[Carts]', N'U') IS NOT NULL
    BEGIN
        DROP TABLE [commerce].[Carts];
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
