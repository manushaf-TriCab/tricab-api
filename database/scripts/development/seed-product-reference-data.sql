/*
================================================================================
  Seed: Product Module reference / lookup data (Phase 1).

  Idempotent by Code columns. Safe to re-run.
  Does not create products, variants, media, or prices.
  DEVELOPMENT seed — review before production.
================================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;

    /* Product statuses */
    MERGE [product].[ProductStatuses] AS t
    USING (VALUES
        (N'draft', N'Draft', N'Editable draft product.', 10),
        (N'review', N'In review', N'Awaiting internal review.', 20),
        (N'published', N'Published', N'Visible on approved channels when publish window allows.', 30),
        (N'unpublished', N'Unpublished', N'Hidden from public channels.', 40),
        (N'discontinued', N'Discontinued', N'No longer sold; retained for history.', 50),
        (N'archived', N'Archived', N'Soft-archived from active catalog management.', 60)
    ) AS s ([StatusCode], [StatusName], [Description], [DisplayOrder])
    ON t.[StatusCode] = s.[StatusCode]
    WHEN NOT MATCHED THEN
        INSERT ([StatusCode], [StatusName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[StatusCode], s.[StatusName], s.[Description], s.[DisplayOrder], 1);

    /* Lifecycle statuses */
    MERGE [product].[ProductLifecycleStatuses] AS t
    USING (VALUES
        (N'concept', N'Concept', N'Early concept / R&D.', 10),
        (N'active', N'Active', N'Current commercial lifecycle.', 20),
        (N'phase-out', N'Phase out', N'Scheduled for discontinuation.', 30),
        (N'end-of-life', N'End of life', N'End of commercial life.', 40),
        (N'replaced', N'Replaced', N'Replaced by another product.', 50)
    ) AS s ([LifecycleStatusCode], [LifecycleStatusName], [Description], [DisplayOrder])
    ON t.[LifecycleStatusCode] = s.[LifecycleStatusCode]
    WHEN NOT MATCHED THEN
        INSERT ([LifecycleStatusCode], [LifecycleStatusName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[LifecycleStatusCode], s.[LifecycleStatusName], s.[Description], s.[DisplayOrder], 1);

    /* Media types */
    MERGE [product].[MediaTypes] AS t
    USING (VALUES
        (N'primary-image', N'Primary image', N'Main product image.', N'jpg,jpeg,png,webp', CAST(10485760 AS BIGINT), 10),
        (N'gallery-image', N'Gallery image', N'Additional gallery image.', N'jpg,jpeg,png,webp', CAST(10485760 AS BIGINT), 20),
        (N'thumbnail', N'Thumbnail', N'Thumbnail image.', N'jpg,jpeg,png,webp', CAST(2097152 AS BIGINT), 30),
        (N'hero-image', N'Hero image', N'Hero / banner image.', N'jpg,jpeg,png,webp', CAST(15728640 AS BIGINT), 40),
        (N'diagram', N'Diagram', N'Technical diagram.', N'jpg,jpeg,png,svg,webp', CAST(10485760 AS BIGINT), 50),
        (N'cross-section', N'Cross-section', N'Cross-section image.', N'jpg,jpeg,png,webp', CAST(10485760 AS BIGINT), 60),
        (N'installation-image', N'Installation image', N'Installation guidance image.', N'jpg,jpeg,png,webp', CAST(10485760 AS BIGINT), 70),
        (N'video', N'Video', N'Product video.', N'mp4,webm', CAST(209715200 AS BIGINT), 80),
        (N'360-set', N'360 image set', N'360-degree image sequence.', N'jpg,jpeg,png,webp', CAST(10485760 AS BIGINT), 90),
        (N'ar-model', N'AR model', N'AR / 3D model.', N'glb,gltf,usdz', CAST(104857600 AS BIGINT), 100),
        (N'cad', N'CAD file', N'CAD / technical drawing package.', N'dwg,dxf,step,stp,iges', CAST(104857600 AS BIGINT), 110),
        (N'download-zip', N'Download ZIP', N'Downloadable ZIP package.', N'zip', CAST(524288000 AS BIGINT), 120)
    ) AS s ([MediaTypeCode], [MediaTypeName], [Description], [AllowedExtensions], [MaxFileSizeBytes], [DisplayOrder])
    ON t.[MediaTypeCode] = s.[MediaTypeCode]
    WHEN NOT MATCHED THEN
        INSERT ([MediaTypeCode], [MediaTypeName], [Description], [AllowedExtensions], [MaxFileSizeBytes], [DisplayOrder], [IsActive])
        VALUES (s.[MediaTypeCode], s.[MediaTypeName], s.[Description], s.[AllowedExtensions], s.[MaxFileSizeBytes], s.[DisplayOrder], 1);

    /* Document types */
    MERGE [product].[DocumentTypes] AS t
    USING (VALUES
        (N'datasheet', N'Datasheet', N'Product datasheet PDF.', 10),
        (N'certificate', N'Certificate', N'Compliance / test certificate.', 20),
        (N'installation-guide', N'Installation guide', N'Installation instructions.', 30),
        (N'technical-drawing', N'Technical drawing', N'Technical drawing document.', 40),
        (N'brochure', N'Brochure', N'Marketing brochure.', 50),
        (N'cad-package', N'CAD package', N'CAD download package.', 60),
        (N'other', N'Other', N'Other document.', 90)
    ) AS s ([DocumentTypeCode], [DocumentTypeName], [Description], [DisplayOrder])
    ON t.[DocumentTypeCode] = s.[DocumentTypeCode]
    WHEN NOT MATCHED THEN
        INSERT ([DocumentTypeCode], [DocumentTypeName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[DocumentTypeCode], s.[DocumentTypeName], s.[Description], s.[DisplayOrder], 1);

    /* Currencies */
    MERGE [product].[Currencies] AS t
    USING (VALUES
        (N'AUD', N'Australian Dollar', N'$', CAST(2 AS TINYINT), 10),
        (N'USD', N'US Dollar', N'$', CAST(2 AS TINYINT), 20),
        (N'EUR', N'Euro', N'€', CAST(2 AS TINYINT), 30),
        (N'GBP', N'British Pound', N'£', CAST(2 AS TINYINT), 40),
        (N'NZD', N'New Zealand Dollar', N'$', CAST(2 AS TINYINT), 50)
    ) AS s ([CurrencyCode], [CurrencyName], [Symbol], [DecimalPlaces], [DisplayOrder])
    ON t.[CurrencyCode] = s.[CurrencyCode]
    WHEN NOT MATCHED THEN
        INSERT ([CurrencyCode], [CurrencyName], [Symbol], [DecimalPlaces], [DisplayOrder], [IsActive])
        VALUES (s.[CurrencyCode], s.[CurrencyName], s.[Symbol], s.[DecimalPlaces], s.[DisplayOrder], 1);

    /* Regions */
    MERGE [product].[Regions] AS t
    USING (VALUES
        (N'AU', N'Australia', N'AU', 10),
        (N'NZ', N'New Zealand', N'NZ', 20),
        (N'APAC', N'Asia Pacific', N'', 30),
        (N'EMEA', N'Europe / Middle East / Africa', N'', 40),
        (N'AMER', N'Americas', N'', 50)
    ) AS s ([RegionCode], [RegionName], [CountryCodes], [DisplayOrder])
    ON t.[RegionCode] = s.[RegionCode]
    WHEN NOT MATCHED THEN
        INSERT ([RegionCode], [RegionName], [CountryCodes], [DisplayOrder], [IsActive])
        VALUES (s.[RegionCode], s.[RegionName], NULLIF(s.[CountryCodes], N''), s.[DisplayOrder], 1);

    /* Markets (linked to regions where known) */
    DECLARE @AuRegionId BIGINT = (SELECT TOP (1) [RegionId] FROM [product].[Regions] WHERE [RegionCode] = N'AU');
    DECLARE @NzRegionId BIGINT = (SELECT TOP (1) [RegionId] FROM [product].[Regions] WHERE [RegionCode] = N'NZ');

    MERGE [product].[Markets] AS t
    USING (VALUES
        (N'AU-DIST', N'Australia Distribution', @AuRegionId, 10),
        (N'AU-OEM', N'Australia OEM', @AuRegionId, 20),
        (N'NZ-DIST', N'New Zealand Distribution', @NzRegionId, 30),
        (N'EXPORT', N'Export', NULL, 40)
    ) AS s ([MarketCode], [MarketName], [RegionId], [DisplayOrder])
    ON t.[MarketCode] = s.[MarketCode]
    WHEN NOT MATCHED THEN
        INSERT ([MarketCode], [MarketName], [RegionId], [DisplayOrder], [IsActive])
        VALUES (s.[MarketCode], s.[MarketName], s.[RegionId], s.[DisplayOrder], 1);

    /* Spec groups (foundation only) */
    MERGE [product].[SpecificationGroups] AS t
    USING (VALUES
        (N'electrical', N'Electrical', N'Electrical characteristics.', 10),
        (N'mechanical', N'Mechanical', N'Mechanical / dimensional characteristics.', 20),
        (N'construction', N'Construction', N'Cable / product construction.', 30),
        (N'compliance', N'Compliance', N'Standards and compliance.', 40),
        (N'packaging', N'Packaging', N'Packaging and logistics.', 50)
    ) AS s ([GroupCode], [GroupName], [Description], [DisplayOrder])
    ON t.[GroupCode] = s.[GroupCode]
    WHEN NOT MATCHED THEN
        INSERT ([GroupCode], [GroupName], [Description], [DisplayOrder], [IsActive])
        VALUES (s.[GroupCode], s.[GroupName], s.[Description], s.[DisplayOrder], 1);

    COMMIT TRANSACTION;

    SELECT
        (SELECT COUNT(*) FROM [product].[ProductStatuses]) AS ProductStatusCount,
        (SELECT COUNT(*) FROM [product].[ProductLifecycleStatuses]) AS LifecycleStatusCount,
        (SELECT COUNT(*) FROM [product].[MediaTypes]) AS MediaTypeCount,
        (SELECT COUNT(*) FROM [product].[DocumentTypes]) AS DocumentTypeCount,
        (SELECT COUNT(*) FROM [product].[Currencies]) AS CurrencyCount,
        (SELECT COUNT(*) FROM [product].[Regions]) AS RegionCount,
        (SELECT COUNT(*) FROM [product].[Markets]) AS MarketCount,
        (SELECT COUNT(*) FROM [product].[SpecificationGroups]) AS SpecGroupCount;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();
    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
