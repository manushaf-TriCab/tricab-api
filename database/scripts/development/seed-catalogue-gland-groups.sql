/*
  Accessories → Glands groups.

  Adds Brass, Nylon, and Stainless Steel under the existing ACC-GLANDS node.
  Navigation labels live on the catalogue node description.
  Product names and product media are left unchanged.
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
WHERE n.[NodeCode] = N'ACC-GLANDS'
  AND n.[DeletedAtUtc] IS NULL;

IF @ParentId IS NULL
BEGIN
    THROW 51000, N'ACC-GLANDS catalogue node was not found.', 1;
END;

DECLARE @Groups TABLE (
    [NodeCode] NVARCHAR(80) NOT NULL,
    [NodeName] NVARCHAR(200) NOT NULL,
    [Slug] NVARCHAR(160) NOT NULL,
    [DisplayOrder] INT NOT NULL
);

INSERT INTO @Groups ([NodeCode], [NodeName], [Slug], [DisplayOrder])
VALUES
    (N'GLAND-BRASS', N'Brass', N'brass', 10),
    (N'GLAND-NYLON', N'Nylon', N'nylon', 20),
    (N'GLAND-SS', N'Stainless Steel', N'stainless-steel', 30);

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
    (N'GLAND-BRASS', N'GLAND-D01', N'D01', N'Brass Standard', N'd01', 10, N'/assets/D01-gr.jpg'),
    (N'GLAND-BRASS', N'GLAND-D06', N'D06', N'Brass Long Thread', N'd06', 20, N'/assets/D06-gr.jpg'),
    (N'GLAND-BRASS', N'GLAND-D13', N'D13', N'Brass Reducer', N'd13', 30, N'/assets/D13-gr.jpg'),
    (N'GLAND-BRASS', N'GLAND-D14', N'D14', N'Brass Enlarger', N'd14', 40, N'/assets/D14-gr.jpg'),
    (N'GLAND-BRASS', N'GLAND-D11', N'D11', N'Brass EMC', N'd11', 50, N'/assets/D11-gr.jpg'),
    (N'GLAND-BRASS', N'GLAND-D24', N'D24', N'Brass EMC Spring', N'd24', 60, N'/assets/D24-gr.jpg'),
    (N'GLAND-BRASS', N'GLAND-D15', N'D15', N'Brass Armoured', N'd15', 70, N'/assets/D15-gr.jpg'),
    (N'GLAND-BRASS', N'GLAND-D29', N'D29', N'Brass Ex-rated', N'd29', 80, N'/assets/D29-gr.jpg'),
    (N'GLAND-NYLON', N'GLAND-D08', N'D08', N'Nylon Standard', N'd08', 10, N'/assets/D08-gr.jpg'),
    (N'GLAND-NYLON', N'GLAND-D03', N'D03', N'Nylon Long Thread', N'd03', 20, N'/assets/D03-gr.jpg'),
    (N'GLAND-NYLON', N'GLAND-D12', N'D12', N'Nylon Multi Hole', N'd12', 30, N'/assets/D12-gr.jpg'),
    (N'GLAND-SS', N'GLAND-D02', N'D02', N'SS Standard', N'd02', 10, N'/assets/D02-gr.jpg'),
    (N'GLAND-SS', N'GLAND-D19', N'D19', N'SS Fire Rated', N'd19', 20, N'/assets/D19-gr.jpg');

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
