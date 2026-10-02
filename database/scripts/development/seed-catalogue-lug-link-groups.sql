/*
  Accessories → Lugs & Links groups.

  Adds Links and Lugs under the existing ACC-LUGS-LINKS node, then one
  product-group leaf per code in the required display order. Links a leaf
  to a product only when [product].[Products].[InternalCode] already matches.
  Does not insert products, styles, or product media.

  Idempotent. Safe to re-run on the development catalogue only.
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
WHERE n.[NodeCode] = N'ACC-LUGS-LINKS'
  AND n.[DeletedAtUtc] IS NULL;

IF @ParentId IS NULL
BEGIN
    THROW 51000, N'ACC-LUGS-LINKS catalogue node was not found.', 1;
END;

DECLARE @Groups TABLE (
    [NodeCode] NVARCHAR(80) NOT NULL,
    [NodeName] NVARCHAR(200) NOT NULL,
    [Slug] NVARCHAR(160) NOT NULL,
    [DisplayOrder] INT NOT NULL
);

INSERT INTO @Groups ([NodeCode], [NodeName], [Slug], [DisplayOrder])
VALUES
    (N'LUGS-LINKS', N'Links', N'links', 10),
    (N'LUGS-LUGS', N'Lugs', N'lugs', 20);

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
    (N'LUGS-LINKS', N'LUGS-B07', N'B07', N'Cu Crimp', N'b07', 10, N'/assets/B07-gr.jpg'),
    (N'LUGS-LINKS', N'LUGS-B36', N'B36', N'Cu Reducing', N'b36', 20, N'/assets/B36-gr.jpg'),
    (N'LUGS-LINKS', N'LUGS-B24', N'B24', N'Al Crimp', N'b24', 30, N'/assets/B24-gr.jpg'),
    (N'LUGS-LINKS', N'LUGS-B21', N'B21', N'Bi-Metal Reducer', N'b21', 40, N'/assets/B21-gr.jpg'),
    (N'LUGS-LUGS', N'LUGS-B06', N'B06', N'Cu Bell Mouth', N'b06', 10, N'/assets/B06-gr.jpg'),
    (N'LUGS-LUGS', N'LUGS-B05', N'B05', N'Cu Straight Barrel', N'b05', 20, N'/assets/B05-gr.jpg'),
    (N'LUGS-LUGS', N'LUGS-B12', N'B12', N'Cu CB', N'b12', 30, N'/assets/B12-gr.jpg'),
    (N'LUGS-LUGS', N'LUGS-B10', N'B10', N'Shear Bolt', N'b10', 40, N'/assets/B10-gr.jpg'),
    (N'LUGS-LUGS', N'LUGS-B37', N'B37', N'Al 1-Hole', N'b37', 50, N'/assets/B37-gr.jpg'),
    (N'LUGS-LUGS', N'LUGS-B23', N'B23', N'Al 2-Hole', N'b23', 60, N'/assets/B23-gr.jpg'),
    (N'LUGS-LUGS', N'LUGS-B38', N'B38', N'Al Stalk', N'b38', 70, N'/assets/B38-gr.jpg'),
    (N'LUGS-LUGS', N'LUGS-B20', N'B20', N'Bi-Metal 1-Hole', N'b20', 80, N'/assets/B20-gr.jpg'),
    (N'LUGS-LUGS', N'LUGS-B22', N'B22', N'Bi-Metal Stalk', N'b22', 90, N'/assets/B22-gr.jpg'),
    (N'LUGS-LUGS', N'LUGS-B27', N'B27', N'Bi-Metal Blank', N'b27', 100, N'/assets/B27-gr.jpg');

INSERT INTO [product].[CatalogueNodes] (
    [CatalogueTabId], [ParentNodeId], [NodeCode], [NodeName], [Slug], [Description],
    [NodeTypeCode], [ImageUrl], [ImageAltText], [DisplayOrder], [IsActive], [IsPublic]
)
SELECT
    @TabId,
    parent.[CatalogueNodeId],
    leaf.[NodeCode],
    leaf.[ProductCode],
    leaf.[Slug],
    leaf.[Title],
    N'product-group',
    leaf.[ImageUrl],
    leaf.[ProductCode],
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

COMMIT TRANSACTION;
