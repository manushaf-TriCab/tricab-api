/*
================================================================================
  Seed: Phase 8.5 representative Product catalogue sample (non-production).

  Idempotent by Code columns. Creates hierarchy + lookups only — products are
  created by the live Azure SQL stabilisation HTTP workflow (or re-seed later).

  Contents:
    - 2 families, 4 categories, 2 series
    - Industries / applications
    - Spec definitions (electrical + construction)
    - Simple product-code rule set (literal + free-text)

  DEVELOPMENT / disposable Azure SQL only. Do not run against production.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    /* Families */
    MERGE [product].[ProductFamilies] AS t
    USING (VALUES
        (N'CABLE', N'Cables', N'Power and control cable families.', 10),
        (N'PLUG', N'Plugs & Sockets', N'Industrial plugs and sockets.', 20)
    ) AS s ([FamilyCode], [FamilyName], [Description], [DisplayOrder])
    ON t.[FamilyCode] = s.[FamilyCode]
    WHEN NOT MATCHED THEN
        INSERT ([FamilyCode], [FamilyName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[FamilyCode], s.[FamilyName], s.[Description], s.[DisplayOrder], 1);

    DECLARE @CableFamilyId BIGINT = (SELECT TOP (1) [ProductFamilyId] FROM [product].[ProductFamilies] WHERE [FamilyCode] = N'CABLE');
    DECLARE @PlugFamilyId BIGINT = (SELECT TOP (1) [ProductFamilyId] FROM [product].[ProductFamilies] WHERE [FamilyCode] = N'PLUG');

    /* Categories */
    MERGE [product].[ProductCategories] AS t
    USING (VALUES
        (N'FLEX-POWER', N'Flexible Power', @CableFamilyId, N'Flexible power cables.', 10),
        (N'CTRL-MULTI', N'Control Multicore', @CableFamilyId, N'Multicore control cables.', 20),
        (N'IND-PLUG', N'Industrial Plugs', @PlugFamilyId, N'Industrial straight and angle plugs.', 30),
        (N'IND-SOCKET', N'Industrial Sockets', @PlugFamilyId, N'Industrial sockets and outlets.', 40)
    ) AS s ([CategoryCode], [CategoryName], [ProductFamilyId], [Description], [DisplayOrder])
    ON t.[CategoryCode] = s.[CategoryCode]
    WHEN NOT MATCHED THEN
        INSERT ([ProductFamilyId], [CategoryCode], [CategoryName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[ProductFamilyId], s.[CategoryCode], s.[CategoryName], s.[Description], s.[DisplayOrder], 1)
    WHEN MATCHED AND t.[ProductFamilyId] IS NULL THEN
        UPDATE SET t.[ProductFamilyId] = s.[ProductFamilyId], t.[UpdatedAtUtc] = SYSUTCDATETIME();

    DECLARE @FlexCatId BIGINT = (SELECT TOP (1) [ProductCategoryId] FROM [product].[ProductCategories] WHERE [CategoryCode] = N'FLEX-POWER');
    DECLARE @PlugCatId BIGINT = (SELECT TOP (1) [ProductCategoryId] FROM [product].[ProductCategories] WHERE [CategoryCode] = N'IND-PLUG');

    /* Series */
    MERGE [product].[ProductSeries] AS t
    USING (VALUES
        (N'TC-FLEX', N'TriCab Flex', @FlexCatId, N'Representative flexible cable series.', 10),
        (N'J-SERIES', N'J Series', @PlugCatId, N'Industrial J-series plugs.', 20)
    ) AS s ([SeriesCode], [SeriesName], [ProductCategoryId], [Description], [DisplayOrder])
    ON t.[SeriesCode] = s.[SeriesCode]
    WHEN NOT MATCHED THEN
        INSERT ([ProductCategoryId], [SeriesCode], [SeriesName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[ProductCategoryId], s.[SeriesCode], s.[SeriesName], s.[Description], s.[DisplayOrder], 1);

    /* Industries */
    MERGE [product].[Industries] AS t
    USING (VALUES
        (N'MINING', N'Mining', N'Mining and resources.', 10),
        (N'MANUFACTURING', N'Manufacturing', N'Manufacturing plants.', 20),
        (N'INFRA', N'Infrastructure', N'Infrastructure and utilities.', 30)
    ) AS s ([IndustryCode], [IndustryName], [Description], [DisplayOrder])
    ON t.[IndustryCode] = s.[IndustryCode]
    WHEN NOT MATCHED THEN
        INSERT ([IndustryCode], [IndustryName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[IndustryCode], s.[IndustryName], s.[Description], s.[DisplayOrder], 1);

    /* Applications */
    MERGE [product].[Applications] AS t
    USING (VALUES
        (N'TEMP-POWER', N'Temporary power', N'Temporary / event power.', 10),
        (N'MOBILE-EQ', N'Mobile equipment', N'Mobile machinery and equipment.', 20),
        (N'FIXED-INSTALL', N'Fixed installation', N'Fixed plant installation.', 30)
    ) AS s ([ApplicationCode], [ApplicationName], [Description], [DisplayOrder])
    ON t.[ApplicationCode] = s.[ApplicationCode]
    WHEN NOT MATCHED THEN
        INSERT ([ApplicationCode], [ApplicationName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[ApplicationCode], s.[ApplicationName], s.[Description], s.[DisplayOrder], 1);

    /* Spec definitions */
    DECLARE @ElectricalGroupId BIGINT = (SELECT TOP (1) [SpecificationGroupId] FROM [product].[SpecificationGroups] WHERE [GroupCode] = N'electrical');
    DECLARE @ConstructionGroupId BIGINT = (SELECT TOP (1) [SpecificationGroupId] FROM [product].[SpecificationGroups] WHERE [GroupCode] = N'construction');
    DECLARE @ComplianceGroupId BIGINT = (SELECT TOP (1) [SpecificationGroupId] FROM [product].[SpecificationGroups] WHERE [GroupCode] = N'compliance');

    MERGE [product].[SpecificationDefinitions] AS t
    USING (VALUES
        (N'VOLTAGE', N'Voltage', @ElectricalGroupId, N'text', N'product', N'V', 10),
        (N'CURRENT', N'Current rating', @ElectricalGroupId, N'text', N'both', N'A', 20),
        (N'CORES', N'Number of cores', @ConstructionGroupId, N'integer', N'both', NULL, 30),
        (N'CSA', N'Conductor CSA', @ConstructionGroupId, N'text', N'both', N'mm²', 40),
        (N'SHEATH', N'Sheath material', @ConstructionGroupId, N'text', N'product', NULL, 50),
        (N'STANDARD', N'Standard', @ComplianceGroupId, N'text', N'product', NULL, 60),
        (N'IP-RATING', N'IP rating', @ComplianceGroupId, N'text', N'product', NULL, 70)
    ) AS s ([DefinitionCode], [DefinitionName], [SpecificationGroupId], [DataTypeCode], [ScopeCode], [Unit], [DisplayOrder])
    ON t.[DefinitionCode] = s.[DefinitionCode]
    WHEN NOT MATCHED THEN
        INSERT (
            [SpecificationGroupId], [DefinitionCode], [DefinitionName], [DataTypeCode], [ScopeCode],
            [Unit], [DisplayOrder], [IsRequired], [IsActive]
        )
        VALUES (
            s.[SpecificationGroupId], s.[DefinitionCode], s.[DefinitionName], s.[DataTypeCode], s.[ScopeCode],
            s.[Unit], s.[DisplayOrder], 0, 1
        );

    /* Simple code rule set: TC- + free-text suffix */
    IF NOT EXISTS (SELECT 1 FROM [product].[ProductCodeRuleSets] WHERE [RuleSetCode] = N'PHASE85-SIMPLE')
    BEGIN
        INSERT INTO [product].[ProductCodeRuleSets] ([RuleSetCode], [RuleSetName], [Description], [IsDefault], [IsActive])
        VALUES (N'PHASE85-SIMPLE', N'Phase 8.5 simple code', N'Literal TC- plus free-text suffix.', 1, 1);
    END;

    DECLARE @RuleSetId BIGINT = (SELECT TOP (1) [ProductCodeRuleSetId] FROM [product].[ProductCodeRuleSets] WHERE [RuleSetCode] = N'PHASE85-SIMPLE');

    IF NOT EXISTS (
        SELECT 1 FROM [product].[ProductCodeRuleSegments]
        WHERE [ProductCodeRuleSetId] = @RuleSetId AND [SegmentCode] = N'prefix'
    )
    BEGIN
        INSERT INTO [product].[ProductCodeRuleSegments] (
            [ProductCodeRuleSetId], [SegmentCode], [SegmentName], [SegmentOrder], [SegmentTypeCode],
            [LiteralValue], [IsRequired], [SeparatorAfter], [IsActive]
        )
        VALUES (@RuleSetId, N'prefix', N'Prefix', 1, N'literal', N'TC', 1, N'-', 1);
    END;

    IF NOT EXISTS (
        SELECT 1 FROM [product].[ProductCodeRuleSegments]
        WHERE [ProductCodeRuleSetId] = @RuleSetId AND [SegmentCode] = N'suffix'
    )
    BEGIN
        INSERT INTO [product].[ProductCodeRuleSegments] (
            [ProductCodeRuleSetId], [SegmentCode], [SegmentName], [SegmentOrder], [SegmentTypeCode],
            [IsRequired], [IsActive]
        )
        VALUES (@RuleSetId, N'suffix', N'Suffix', 2, N'free-text', 1, 1);
    END;

    COMMIT TRANSACTION;

    SELECT
        (SELECT COUNT(*) FROM [product].[ProductFamilies] WHERE [DeletedAtUtc] IS NULL) AS FamilyCount,
        (SELECT COUNT(*) FROM [product].[ProductCategories] WHERE [DeletedAtUtc] IS NULL) AS CategoryCount,
        (SELECT COUNT(*) FROM [product].[ProductSeries] WHERE [DeletedAtUtc] IS NULL) AS SeriesCount,
        (SELECT COUNT(*) FROM [product].[Industries] WHERE [DeletedAtUtc] IS NULL) AS IndustryCount,
        (SELECT COUNT(*) FROM [product].[Applications] WHERE [DeletedAtUtc] IS NULL) AS ApplicationCount,
        (SELECT COUNT(*) FROM [product].[SpecificationDefinitions] WHERE [DeletedAtUtc] IS NULL) AS SpecDefinitionCount,
        (SELECT COUNT(*) FROM [product].[ProductCodeRuleSets] WHERE [DeletedAtUtc] IS NULL) AS RuleSetCount;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();
    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
