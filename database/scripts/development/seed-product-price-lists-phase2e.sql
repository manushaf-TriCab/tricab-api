/*
================================================================================
  Seed: Product Price Lists — Phase 2E legacy dbco contexts (DRY-RUN REVIEW ONLY)

  Idempotent MERGE by PriceListCode + ExternalEntityMappings.
  Does NOT import tc.price rows.
  Does NOT execute automatically — review and run manually in non-production.

  Confirmed live dbco values: 2507, 2517, 2519

  Currency / region assignments below must be validated against reference data
  before execution. Friendly names are presentation only; dbco is authoritative.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    /* External system for legacy tc integration */
    MERGE [product].[ExternalSystems] AS t
    USING (VALUES
        (N'legacy-tc', N'Legacy TC MySQL', N'Read-only legacy tc product/pricing database.')
    ) AS s ([SystemCode], [SystemName], [Description])
    ON t.[SystemCode] = s.[SystemCode]
    WHEN NOT MATCHED THEN
        INSERT ([SystemCode], [SystemName], [Description], [IsActive])
        VALUES (s.[SystemCode], s.[SystemName], s.[Description], 1);

    DECLARE @LegacyTcSystemId BIGINT = (
        SELECT TOP (1) [ExternalSystemId] FROM [product].[ExternalSystems] WHERE [SystemCode] = N'legacy-tc'
    );

    DECLARE @AudCurrencyId BIGINT = (SELECT TOP (1) [CurrencyId] FROM [product].[Currencies] WHERE [CurrencyCode] = N'AUD');
    DECLARE @UsdCurrencyId BIGINT = (SELECT TOP (1) [CurrencyId] FROM [product].[Currencies] WHERE [CurrencyCode] = N'USD');
    DECLARE @AuRegionId BIGINT = (SELECT TOP (1) [RegionId] FROM [product].[Regions] WHERE [RegionCode] = N'AU');
    DECLARE @ApacRegionId BIGINT = (SELECT TOP (1) [RegionId] FROM [product].[Regions] WHERE [RegionCode] = N'APAC');
    DECLARE @AmerRegionId BIGINT = (SELECT TOP (1) [RegionId] FROM [product].[Regions] WHERE [RegionCode] = N'AMER');

    IF @LegacyTcSystemId IS NULL OR @AudCurrencyId IS NULL OR @UsdCurrencyId IS NULL
        THROW 50001, 'Missing legacy-tc system or currency reference data.', 1;

    /*
      Dry-run preview (review only — uncomment SELECT before MERGE if desired):

      SELECT N'2507' AS dbco, @AudCurrencyId AS CurrencyId, @AuRegionId AS RegionId;
      SELECT N'2517' AS dbco, @UsdCurrencyId AS CurrencyId, @AmerRegionId AS RegionId;
      SELECT N'2519' AS dbco, @UsdCurrencyId AS CurrencyId, @ApacRegionId AS RegionId;
    */

    MERGE [product].[PriceLists] AS t
    USING (VALUES
        (N'TCA-BASE', N'Australia Base Cost', @AudCurrencyId, @AuRegionId, N'2507'),
        (N'TCUS-BASE', N'United States Base Cost', @UsdCurrencyId, @AmerRegionId, N'2517'),
        (N'TCASIA-BASE', N'Asia Base Cost', @UsdCurrencyId, @ApacRegionId, N'2519')
    ) AS s ([PriceListCode], [PriceListName], [CurrencyId], [RegionId], [Dbco])
    ON t.[PriceListCode] = s.[PriceListCode]
    WHEN NOT MATCHED THEN
        INSERT ([PriceListCode], [PriceListName], [CurrencyId], [RegionId], [IsActive])
        VALUES (s.[PriceListCode], s.[PriceListName], s.[CurrencyId], s.[RegionId], 1);

    MERGE [product].[ExternalEntityMappings] AS t
    USING (
        SELECT
            @LegacyTcSystemId AS [ExternalSystemId],
            N'dbco' AS [EntityTypeCode],
            pl.[PriceListId] AS [InternalEntityId],
            s.[Dbco] AS [ExternalEntityKey]
        FROM (VALUES
            (N'TCA-BASE', N'2507'),
            (N'TCUS-BASE', N'2517'),
            (N'TCASIA-BASE', N'2519')
        ) AS s ([PriceListCode], [Dbco])
        INNER JOIN [product].[PriceLists] pl ON pl.[PriceListCode] = s.[PriceListCode]
    ) AS s
    ON t.[ExternalSystemId] = s.[ExternalSystemId]
       AND t.[EntityTypeCode] = s.[EntityTypeCode]
       AND t.[ExternalEntityKey] = s.[ExternalEntityKey]
    WHEN NOT MATCHED THEN
        INSERT ([ExternalSystemId], [EntityTypeCode], [InternalEntityId], [ExternalEntityKey], [IsActive])
        VALUES (s.[ExternalSystemId], s.[EntityTypeCode], s.[InternalEntityId], s.[ExternalEntityKey], 1);

    COMMIT TRANSACTION;

    SELECT
        (SELECT COUNT(*) FROM [product].[PriceLists]) AS PriceListCount,
        (SELECT COUNT(*) FROM [product].[ExternalEntityMappings] eem
            INNER JOIN [product].[ExternalSystems] es ON es.[ExternalSystemId] = eem.[ExternalSystemId]
            WHERE es.[SystemCode] = N'legacy-tc' AND eem.[EntityTypeCode] = N'dbco') AS LegacyDbcoMappingCount;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
