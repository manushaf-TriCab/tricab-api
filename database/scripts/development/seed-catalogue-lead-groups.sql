/*
  Accessories → Leads groups.

  Adds four category nodes under the existing ACC-LEAD node and one
  product-group leaf per existing style/product code. Links a leaf to a
  product only when [product].[Products].[InternalCode] already matches.
  Reuses that product's existing thumbnail URL. Does not insert products
  or image files.

  Idempotent. Safe to re-run on the development catalogue only.
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

DECLARE @LeadId BIGINT;
DECLARE @TabId BIGINT;

SELECT
    @LeadId = n.[CatalogueNodeId],
    @TabId = n.[CatalogueTabId]
FROM [product].[CatalogueNodes] n
WHERE n.[NodeCode] = N'ACC-LEAD'
  AND n.[DeletedAtUtc] IS NULL;

IF @LeadId IS NULL
BEGIN
    THROW 51000, N'ACC-LEAD catalogue node was not found.', 1;
END;

UPDATE [product].[CatalogueNodes]
SET [NodeName] = N'Leads',
    [UpdatedAtUtc] = SYSUTCDATETIME()
WHERE [CatalogueNodeId] = @LeadId
  AND [NodeName] = N'Lead'
  AND [Slug] = N'lead';

DECLARE @Groups TABLE (
    [NodeCode] NVARCHAR(80) NOT NULL,
    [NodeName] NVARCHAR(200) NOT NULL,
    [Slug] NVARCHAR(160) NOT NULL,
    [DisplayOrder] INT NOT NULL
);

INSERT INTO @Groups ([NodeCode], [NodeName], [Slug], [DisplayOrder])
VALUES
    (N'LEAD-LUG', N'Lug Leads', N'lug-leads', 10),
    (N'LEAD-PLUG-SOCKET', N'Plug-Socket Leads', N'plug-socket-leads', 20),
    (N'LEAD-CEEFORM', N'CEEform Leads', N'ceeform-leads', 30),
    (N'LEAD-EARTHING', N'Earthing & Bonding Leads', N'earthing-bonding-leads', 40);

INSERT INTO [product].[CatalogueNodes] (
    [CatalogueTabId], [ParentNodeId], [NodeCode], [NodeName], [Slug],
    [NodeTypeCode], [DisplayOrder], [IsActive], [IsPublic]
)
SELECT
    @TabId, @LeadId, g.[NodeCode], g.[NodeName], g.[Slug],
    N'category', g.[DisplayOrder], 1, 1
FROM @Groups g
WHERE NOT EXISTS (
    SELECT 1
    FROM [product].[CatalogueNodes] existing
    WHERE existing.[CatalogueTabId] = @TabId
      AND existing.[NodeCode] = g.[NodeCode]
      AND existing.[DeletedAtUtc] IS NULL
);

DECLARE @Leaves TABLE (
    [ParentCode] NVARCHAR(80) NOT NULL,
    [NodeCode] NVARCHAR(80) NOT NULL,
    [ProductCode] NVARCHAR(80) NOT NULL,
    [Title] NVARCHAR(200) NOT NULL,
    [Slug] NVARCHAR(160) NOT NULL,
    [DisplayOrder] INT NOT NULL
);

INSERT INTO @Leaves ([ParentCode], [NodeCode], [ProductCode], [Title], [Slug], [DisplayOrder])
VALUES
    (N'LEAD-LUG', N'LEAD-LL1E', N'LL1E', N'Earth', N'll1e', 10),
    (N'LEAD-LUG', N'LEAD-LLA1', N'LLA1', N'ALIFLEX', N'lla1', 20),
    (N'LEAD-LUG', N'LEAD-PS6', N'PS6', N'Anderson Leads', N'ps6', 30),
    (N'LEAD-PLUG-SOCKET', N'LEAD-PS1A', N'PS1A', N'10A', N'ps1a', 10),
    (N'LEAD-PLUG-SOCKET', N'LEAD-PS1B', N'PS1B', N'20A', N'ps1b', 20),
    (N'LEAD-PLUG-SOCKET', N'LEAD-PS1C', N'PS1C', N'32A', N'ps1c', 30),
    (N'LEAD-PLUG-SOCKET', N'LEAD-PS1D', N'PS1D', N'50A', N'ps1d', 40),
    (N'LEAD-CEEFORM', N'LEAD-PS3C', N'PS3C', N'63A', N'ps3c', 10),
    (N'LEAD-CEEFORM', N'LEAD-PSXA', N'PSXA', N'C19 Connector', N'psxa', 20),
    (N'LEAD-EARTHING', N'LEAD-EBL', N'EBL', N'Earth Bonding', N'ebl', 10);

INSERT INTO [product].[CatalogueNodes] (
    [CatalogueTabId], [ParentNodeId], [NodeCode], [NodeName], [Slug], [Description],
    [NodeTypeCode], [DisplayOrder], [IsActive], [IsPublic]
)
SELECT
    @TabId,
    parent.[CatalogueNodeId],
    leaf.[NodeCode],
    leaf.[ProductCode],
    leaf.[Slug],
    leaf.[Title],
    N'product-group',
    leaf.[DisplayOrder],
    1,
    1
FROM @Leaves leaf
INNER JOIN [product].[CatalogueNodes] parent
    ON parent.[CatalogueTabId] = @TabId
   AND parent.[NodeCode] = leaf.[ParentCode]
   AND parent.[DeletedAtUtc] IS NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM [product].[CatalogueNodes] existing
    WHERE existing.[CatalogueTabId] = @TabId
      AND existing.[NodeCode] = leaf.[NodeCode]
      AND existing.[DeletedAtUtc] IS NULL
);

UPDATE node
SET [NodeName] = leaf.[ProductCode],
    [Description] = leaf.[Title],
    [DisplayOrder] = leaf.[DisplayOrder],
    [UpdatedAtUtc] = SYSUTCDATETIME()
FROM [product].[CatalogueNodes] node
INNER JOIN @Leaves leaf ON leaf.[NodeCode] = node.[NodeCode]
WHERE node.[CatalogueTabId] = @TabId
  AND node.[DeletedAtUtc] IS NULL
  AND (
      node.[NodeName] <> leaf.[ProductCode]
      OR ISNULL(node.[Description], N'') <> leaf.[Title]
      OR node.[DisplayOrder] <> leaf.[DisplayOrder]
  );

INSERT INTO [product].[CatalogueNodeProducts] (
    [CatalogueNodeId], [ProductId], [DisplayOrder], [IsFeatured], [IsActive]
)
SELECT
    node.[CatalogueNodeId],
    product.[ProductId],
    leaf.[DisplayOrder],
    0,
    1
FROM @Leaves leaf
INNER JOIN [product].[CatalogueNodes] node
    ON node.[CatalogueTabId] = @TabId
   AND node.[NodeCode] = leaf.[NodeCode]
   AND node.[DeletedAtUtc] IS NULL
INNER JOIN [product].[Products] product
    ON product.[InternalCode] = leaf.[ProductCode]
   AND product.[DeletedAtUtc] IS NULL
WHERE NOT EXISTS (
    SELECT 1
    FROM [product].[CatalogueNodeProducts] link
    WHERE link.[CatalogueNodeId] = node.[CatalogueNodeId]
      AND link.[ProductId] = product.[ProductId]
);

DECLARE @Images TABLE (
    [NodeCode] NVARCHAR(80) NOT NULL,
    [ImageUrl] NVARCHAR(1000) NOT NULL
);

INSERT INTO @Images ([NodeCode], [ImageUrl])
VALUES
    (N'LEAD-LL1E', N'/assets/LL1E-gr.jpg'),
    (N'LEAD-LLA1', N'/assets/LLA1-gr.jpg'),
    (N'LEAD-PS6', N'/assets/PS6-gr.jpg'),
    (N'LEAD-PS1A', N'/assets/PS1A-gr.jpg'),
    (N'LEAD-PS1B', N'/assets/PS1B-gr.jpg'),
    (N'LEAD-PS1C', N'/assets/PS1C-gr.jpg'),
    (N'LEAD-PS1D', N'/assets/PS1D-gr.jpg'),
    (N'LEAD-PS3C', N'/assets/PS3C-gr.jpg'),
    (N'LEAD-PSXA', N'/assets/PSXA-gr.jpg'),
    (N'LEAD-EBL', N'/assets/EBL-gr.jpg');

UPDATE node
SET [ImageUrl] = img.[ImageUrl],
    [ImageAltText] = leaf.[ProductCode],
    [UpdatedAtUtc] = SYSUTCDATETIME()
FROM [product].[CatalogueNodes] node
INNER JOIN @Images img ON img.[NodeCode] = node.[NodeCode]
INNER JOIN @Leaves leaf ON leaf.[NodeCode] = node.[NodeCode]
WHERE node.[CatalogueTabId] = @TabId
  AND node.[DeletedAtUtc] IS NULL
  AND ISNULL(node.[ImageUrl], N'') <> img.[ImageUrl];

COMMIT TRANSACTION;
