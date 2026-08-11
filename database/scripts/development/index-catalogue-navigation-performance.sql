/*
  Optional covering index for public/admin catalogue node lists.

  Existing indexes already cover the main access paths:
    IX_product_CatalogueNodes_Tab_Parent_DisplayOrder
      (CatalogueTabId, ParentNodeId, DisplayOrder) WHERE DeletedAtUtc IS NULL
    UQ_product_CatalogueNodeRegions_Node_Region
      (CatalogueNodeId, RegionId) — seeks by CatalogueNodeId

  This index adds IsActive/IsPublic for public panel filters.
  Safe to re-run. Do not apply until Query Store confirms residual predicates.
*/

SET NOCOUNT ON;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'[product].[CatalogueNodes]')
      AND name = N'IX_product_CatalogueNodes_Tab_Active_Public_DisplayOrder'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_product_CatalogueNodes_Tab_Active_Public_DisplayOrder
        ON [product].[CatalogueNodes] ([CatalogueTabId], [IsActive], [IsPublic], [DisplayOrder])
        WHERE [DeletedAtUtc] IS NULL;
END;

PRINT N'Catalogue navigation performance index ready.';
