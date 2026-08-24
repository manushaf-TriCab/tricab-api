/*
================================================================================
  Seed: Product Pricing Units — Phase 2C (DRY-RUN REVIEW ONLY)

  Idempotent MERGE for common pricing units in [product].[SpecificationUnits].
  Does NOT import legacy tc.price.uwt data.
  Does NOT execute automatically — review and run manually in non-production.

  Units: M (metre), EA (each), FT (foot), PK (pack)
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    MERGE [product].[SpecificationUnits] AS t
    USING (VALUES
        (N'M',  N'Metre', N'm',  N'Pricing unit — metre (AU/NZ cable default)', 10),
        (N'EA', N'Each',  N'ea', N'Pricing unit — each (accessories)',         20),
        (N'FT', N'Foot',  N'ft', N'Pricing unit — foot (US display)',          30),
        (N'PK', N'Pack',  N'pk', N'Pricing unit — pack (accessories)',         40)
    ) AS s ([UnitCode], [UnitName], [Symbol], [Description], [DisplayOrder])
        ON t.[UnitCode] = s.[UnitCode]
    WHEN NOT MATCHED BY TARGET THEN
        INSERT ([UnitCode], [UnitName], [Symbol], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[UnitCode], s.[UnitName], s.[Symbol], s.[Description], s.[DisplayOrder], 1)
    WHEN MATCHED AND t.[DeletedAtUtc] IS NULL THEN
        UPDATE SET
            [UnitName] = s.[UnitName],
            [Symbol] = s.[Symbol],
            [Description] = s.[Description],
            [DisplayOrder] = s.[DisplayOrder],
            [IsActive] = 1,
            [UpdatedAtUtc] = SYSUTCDATETIME();

    COMMIT TRANSACTION;
    PRINT N'Phase 2C pricing units seed complete.';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;

    THROW;
END CATCH;
