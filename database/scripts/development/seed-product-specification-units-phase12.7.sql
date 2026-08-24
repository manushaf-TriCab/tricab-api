/*
================================================================================
  Phase 12.7 — Seed SpecificationUnits (spec + pricing UOM)

  Idempotent MERGE. Combines:
    - Phase 2C pricing units: M, EA, FT, PK
    - Phase 12.6 discovered specification units: MM, MM2, KG, KG_PER_KM, A, V, C

  Canonical metre pricing code remains M (symbol m) — do not duplicate as
  separate metre/meter rows.

  Does NOT import legacy prices. Safe to re-run.
  DEVELOPMENT / non-production only.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'[product].[SpecificationUnits]', N'U') IS NULL
BEGIN
    THROW 50001, N'[product].[SpecificationUnits] missing — apply alter-product-specification-units-phase12.7.sql first.', 1;
END;

BEGIN TRY
    BEGIN TRANSACTION;

    MERGE [product].[SpecificationUnits] AS t
    USING (VALUES
        (N'M',         N'Metre',              N'm',     N'Pricing/default length unit (AU/NZ cable). Also length for specs.', 10),
        (N'EA',        N'Each',               N'ea',    N'Pricing unit — each (accessories).', 20),
        (N'FT',        N'Foot',               N'ft',    N'Pricing unit — foot (US display).', 30),
        (N'PK',        N'Pack',               N'pk',    N'Pricing unit — pack (accessories).', 40),
        (N'MM',        N'Millimetre',         N'mm',    N'Specification length (diameter, length).', 50),
        (N'MM2',       N'Square millimetre',  N'mm²',   N'Conductor cross-section.', 60),
        (N'KG',        N'Kilogram',           N'kg',    N'Mass.', 70),
        (N'KG_PER_KM', N'Kilogram per km',    N'kg/km', N'Linear density / cable weight.', 80),
        (N'A',         N'Ampere',             N'A',     N'Current / ampacity.', 90),
        (N'V',         N'Volt',               N'V',     N'Voltage.', 100),
        (N'C',         N'Degree Celsius',     N'°C',    N'Temperature.', 110)
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
    PRINT N'Phase 12.7 SpecificationUnits seed complete.';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
