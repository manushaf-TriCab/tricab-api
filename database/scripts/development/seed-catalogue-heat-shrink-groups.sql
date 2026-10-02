/*
  Accessories → Heat Shrink groups.

  Adds Cold Shrink and Heat Shrink under the existing ACC-HEAT-SHRINK node.
  The inner group keeps the display name Heat Shrink and uses slug
  heat-shrink-products so it does not collide with the parent slug.
  Links a leaf only when a product with that internal code already exists.
  Does not insert products, styles, or product media.

  Idempotent. Development catalogue only.
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRANSACTION;

DECLARE @ParentId BIGINT;
DECLARE @TabId BIGINT;

SELECT
    @ParentId = n.[CatalogueNodeId],
    @TabId = n.[CatalogueTabId]
FROM [product].[CatalogueNodes] n
WHERE n.[NodeCode] = N'ACC-HEAT-SHRINK'
  AND n.[DeletedAtUtc] IS NULL;

IF @ParentId IS NULL
BEGIN
    THROW 51000, N'ACC-HEAT-SHRINK catalogue node was not found.', 1;
END;

DECLARE @Groups TABLE (
    [NodeCode] NVARCHAR(80) NOT NULL,
    [NodeName] NVARCHAR(200) NOT NULL,
    [Slug] NVARCHAR(160) NOT NULL,
    [DisplayOrder] INT NOT NULL
);

INSERT INTO @Groups ([NodeCode], [NodeName], [Slug], [DisplayOrder])
VALUES
    (N'HS-COLD', N'Cold Shrink', N'cold-shrink', 10),
    (N'HS-HEAT', N'Heat Shrink', N'heat-shrink-products', 20);

INSERT INTO [product].[CatalogueNodes] (
    [CatalogueTabId], [ParentNodeId], [NodeCode], [NodeName], [Slug],
    [NodeTypeCode], [DisplayOrder], [IsActive], [IsPublic]
)
SELECT
    @TabId, @ParentId, g.[NodeCode], g.[NodeName], g.[Slug],
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
    [DisplayOrder] INT NOT NULL,
    [ImageUrl] NVARCHAR(1000) NOT NULL
);

INSERT INTO @Leaves ([ParentCode], [NodeCode], [ProductCode], [Title], [Slug], [DisplayOrder], [ImageUrl])
VALUES
    (N'HS-COLD', N'HS-G12', N'G12', N'Cold Shrink', N'g12', 10, N'/assets/G12-gr.jpg'),
    (N'HS-HEAT', N'HS-G01', N'G01', N'Thin Wall', N'g01', 10, N'/assets/G01-gr.jpg'),
    (N'HS-HEAT', N'HS-G03', N'G03', N'Dual Wall', N'g03', 20, N'/assets/G03-gr.jpg'),
    (N'HS-HEAT', N'HS-G06', N'G06', N'Thin Wall RP', N'g06', 30, N'/assets/G06-gr.jpg'),
    (N'HS-HEAT', N'HS-G08', N'G08', N'Dual Wall RP', N'g08', 40, N'/assets/G08-gr.jpg'),
    (N'HS-HEAT', N'HS-G10', N'G10', N'Breakout', N'g10', 50, N'/assets/G10-gr.jpg'),
    (N'HS-HEAT', N'HS-G07', N'G07', N'End Cap', N'g07', 60, N'/assets/G07-gr.jpg'),
    (N'HS-HEAT', N'HS-G15', N'G15', N'Fabric', N'g15', 70, N'/assets/G15-gr.jpg');

INSERT INTO [product].[CatalogueNodes] (
    [CatalogueTabId], [ParentNodeId], [NodeCode], [NodeName], [Slug], [Description],
    [NodeTypeCode], [ImageUrl], [ImageAltText], [DisplayOrder], [IsActive], [IsPublic]
)
SELECT
    @TabId, parent.[CatalogueNodeId], leaf.[NodeCode], leaf.[ProductCode], leaf.[Slug], leaf.[Title],
    N'product-group', leaf.[ImageUrl], leaf.[ProductCode], leaf.[DisplayOrder], 1, 1
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
    [ImageUrl] = leaf.[ImageUrl],
    [ImageAltText] = leaf.[ProductCode],
    [DisplayOrder] = leaf.[DisplayOrder],
    [UpdatedAtUtc] = SYSUTCDATETIME()
FROM [product].[CatalogueNodes] node
INNER JOIN @Leaves leaf ON leaf.[NodeCode] = node.[NodeCode]
WHERE node.[CatalogueTabId] = @TabId
  AND node.[DeletedAtUtc] IS NULL
  AND (
      node.[NodeName] <> leaf.[ProductCode]
      OR ISNULL(node.[Description], N'') <> leaf.[Title]
      OR ISNULL(node.[ImageUrl], N'') <> leaf.[ImageUrl]
      OR node.[DisplayOrder] <> leaf.[DisplayOrder]
  );

INSERT INTO [product].[CatalogueNodeProducts] (
    [CatalogueNodeId], [ProductId], [DisplayOrder], [IsFeatured], [IsActive]
)
SELECT node.[CatalogueNodeId], product.[ProductId], leaf.[DisplayOrder], 0, 1
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

COMMIT TRANSACTION;
