/*
================================================================================
  Seed: PDF catalogue inventory (legacy template-catalogue-list.php parity).

  PUR Marine is ONE Catalogue with AU_NZ + ASIA regions.
  DocumentAvailable starts at 0 until blob migration marks files present.

  DEVELOPMENT seed — review before production.
  Idempotent by SeedKey.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Seed TABLE (
        [SeedKey] NVARCHAR(100) NOT NULL PRIMARY KEY,
        [Title] NVARCHAR(200) NOT NULL,
        [Issue] NVARCHAR(50) NOT NULL,
        [DocumentBlobPath] NVARCHAR(500) NOT NULL,
        [FileName] NVARCHAR(255) NOT NULL,
        [SortOrder] INT NOT NULL
    );

    INSERT INTO @Seed ([SeedKey], [Title], [Issue], [DocumentBlobPath], [FileName], [SortOrder])
    VALUES
        (N'aliflex-marine', N'ALIFLEX Marine', N'13.0', N'catalogues/aliflex-marine/13.0/catalogue.pdf', N'ALIFLEX Marine.pdf', 10),
        (N'dali-lighting-automation', N'DALI - Lighting Automation', N'15.0', N'catalogues/dali-lighting-automation/15.0/catalogue.pdf', N'DALI-Lighting Automation.pdf', 20),
        (N'drag-chain-festoon-elevator', N'Drag Chain, Festoon & Elevator', N'15.0', N'catalogues/drag-chain-festoon-elevator/15.0/catalogue.pdf', N'DRAG CHAIN, FESTOON and ELEVATOR.pdf', 30),
        (N'food-beverage', N'Food & Beverage', N'1.0', N'catalogues/food-beverage/1.0/catalogue.pdf', N'Food & Beverage.pdf', 40),
        (N'pur-marine', N'PUR Marine', N'12.0', N'catalogues/pur-marine/12.0/catalogue.pdf', N'PUR Marine.pdf', 50),
        (N'ship-loaders-cranes', N'Ship Loaders & Cranes', N'1.0', N'catalogues/ship-loaders-cranes/1.0/catalogue.pdf', N'Ship Loaders & Cranes.pdf', 60),
        (N'shore-power', N'Shore Power', N'1.0', N'catalogues/shore-power/1.0/catalogue.pdf', N'Shore Power.pdf', 70),
        (N'temporary-power', N'Temporary Power', N'1.0', N'catalogues/temporary-power/1.0/catalogue.pdf', N'Temporary Power.pdf', 80),
        (N'water-treatment', N'Water Treatment', N'1.0', N'catalogues/water-treatment/1.0/catalogue.pdf', N'Water Treatment.pdf', 90),
        (N'marine-lszh', N'Marine LSZH', N'14.0', N'catalogues/marine-lszh/14.0/catalogue.pdf', N'TriCabUSA_LSZH_MARINE.pdf', 100);

    DECLARE @Regions TABLE (
        [SeedKey] NVARCHAR(100) NOT NULL,
        [RegionCode] NVARCHAR(20) NOT NULL,
        [SortOrder] INT NOT NULL,
        PRIMARY KEY ([SeedKey], [RegionCode])
    );

    INSERT INTO @Regions ([SeedKey], [RegionCode], [SortOrder])
    VALUES
        (N'aliflex-marine', N'AU_NZ', 10),
        (N'dali-lighting-automation', N'AU_NZ', 10),
        (N'drag-chain-festoon-elevator', N'AU_NZ', 10),
        (N'food-beverage', N'AU_NZ', 10),
        (N'pur-marine', N'AU_NZ', 10),
        (N'pur-marine', N'ASIA', 20),
        (N'ship-loaders-cranes', N'AU_NZ', 10),
        (N'shore-power', N'AU_NZ', 10),
        (N'temporary-power', N'AU_NZ', 10),
        (N'water-treatment', N'AU_NZ', 10),
        (N'marine-lszh', N'USA', 10);

    MERGE [catalogue].[Catalogues] AS target
    USING @Seed AS source
        ON target.[SeedKey] = source.[SeedKey]
    WHEN MATCHED AND target.[DeletedAtUtc] IS NULL THEN
        UPDATE SET
            [Title] = source.[Title],
            [Issue] = source.[Issue],
            [DocumentBlobPath] = CASE
                WHEN target.[DocumentAvailable] = 1 THEN target.[DocumentBlobPath]
                ELSE source.[DocumentBlobPath]
            END,
            [FileName] = source.[FileName],
            [SortOrder] = source.[SortOrder],
            [UpdatedAtUtc] = SYSUTCDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (
            [SeedKey], [Title], [Issue], [DocumentBlobPath], [FileName],
            [ContentType], [SortOrder], [IsActive], [DocumentAvailable]
        )
        VALUES (
            source.[SeedKey], source.[Title], source.[Issue], source.[DocumentBlobPath], source.[FileName],
            N'application/pdf', source.[SortOrder], 1, 0
        );

    ;WITH Wanted AS (
        SELECT
            c.[CatalogueId],
            r.[RegionCode],
            r.[SortOrder]
        FROM @Regions r
        INNER JOIN [catalogue].[Catalogues] c ON c.[SeedKey] = r.[SeedKey]
        WHERE c.[DeletedAtUtc] IS NULL
    )
    MERGE [catalogue].[CatalogueRegions] AS target
    USING Wanted AS source
        ON target.[CatalogueId] = source.[CatalogueId]
       AND target.[RegionCode] = source.[RegionCode]
    WHEN MATCHED THEN
        UPDATE SET [SortOrder] = source.[SortOrder]
    WHEN NOT MATCHED THEN
        INSERT ([CatalogueId], [RegionCode], [SortOrder])
        VALUES (source.[CatalogueId], source.[RegionCode], source.[SortOrder]);

    -- Remove stale region rows for seeded catalogues only (keep unknown catalogues untouched).
    DELETE cr
    FROM [catalogue].[CatalogueRegions] cr
    INNER JOIN [catalogue].[Catalogues] c ON c.[CatalogueId] = cr.[CatalogueId]
    INNER JOIN @Seed s ON s.[SeedKey] = c.[SeedKey]
    WHERE NOT EXISTS (
        SELECT 1
        FROM @Regions r
        WHERE r.[SeedKey] = c.[SeedKey]
          AND r.[RegionCode] = cr.[RegionCode]
    );

    COMMIT TRANSACTION;

    SELECT
        (SELECT COUNT(*) FROM [catalogue].[Catalogues] c
         INNER JOIN @Seed s ON s.[SeedKey] = c.[SeedKey]
         WHERE c.[DeletedAtUtc] IS NULL) AS CatalogueCount,
        (SELECT COUNT(*) FROM [catalogue].[CatalogueRegions] cr
         INNER JOIN [catalogue].[Catalogues] c ON c.[CatalogueId] = cr.[CatalogueId]
         INNER JOIN @Seed s ON s.[SeedKey] = c.[SeedKey]) AS RegionAssignmentCount,
        (SELECT COUNT(*) FROM [catalogue].[Catalogues] c
         WHERE c.[SeedKey] = N'pur-marine' AND c.[DeletedAtUtc] IS NULL) AS PurMarineCatalogueCount,
        (SELECT COUNT(*) FROM [catalogue].[CatalogueRegions] cr
         INNER JOIN [catalogue].[Catalogues] c ON c.[CatalogueId] = cr.[CatalogueId]
         WHERE c.[SeedKey] = N'pur-marine') AS PurMarineRegionCount;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
