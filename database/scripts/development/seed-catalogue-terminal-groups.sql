/*
  Accessories → Terminals groups.

  Adds four category nodes under the existing ACC-TERMINALS node, then one
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
WHERE n.[NodeCode] = N'ACC-TERMINALS'
  AND n.[DeletedAtUtc] IS NULL;

IF @ParentId IS NULL
BEGIN
    THROW 51000, N'ACC-TERMINALS catalogue node was not found.', 1;
END;

DECLARE @Groups TABLE (
    [NodeCode] NVARCHAR(80) NOT NULL,
    [NodeName] NVARCHAR(200) NOT NULL,
    [Slug] NVARCHAR(160) NOT NULL,
    [DisplayOrder] INT NOT NULL
);

INSERT INTO @Groups ([NodeCode], [NodeName], [Slug], [DisplayOrder])
VALUES
    (N'TERM-BOOTLACE', N'Bootlace / Ferrule', N'bootlace-ferrule', 10),
    (N'TERM-PIN-BLADE', N'Pin & Blade', N'pin-blade', 20),
    (N'TERM-QUICK-CONNECT', N'Quick Connect', N'quick-connect', 30),
    (N'TERM-RING-FORK', N'Ring & Fork', N'ring-fork', 40);

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
    (N'TERM-BOOTLACE', N'TERM-A01', N'A01', N'Single', N'a01', 10, N'/assets/A01-gr.jpg'),
    (N'TERM-BOOTLACE', N'TERM-A03', N'A03', N'Twin', N'a03', 20, N'/assets/A03-gr.jpg'),
    (N'TERM-BOOTLACE', N'TERM-A20', N'A20', N'Kit', N'a20', 30, N'/assets/A20-gr.jpg'),
    (N'TERM-BOOTLACE', N'TERM-A40', N'A40', N'Reels', N'a40', 40, N'/assets/A40-gr.jpg'),
    (N'TERM-BOOTLACE', N'TERM-A02', N'A02', N'Single', N'a02', 50, N'/assets/A02-gr.jpg'),
    (N'TERM-PIN-BLADE', N'TERM-A09', N'A09', N'Pin', N'a09', 10, N'/assets/A09-gr.jpg'),
    (N'TERM-PIN-BLADE', N'TERM-A29', N'A29', N'Pin DG', N'a29', 20, N'/assets/A29-gr.jpg'),
    (N'TERM-PIN-BLADE', N'TERM-A11', N'A11', N'Blade', N'a11', 30, N'/assets/A11-gr.jpg'),
    (N'TERM-PIN-BLADE', N'TERM-A21', N'A21', N'Blade DG', N'a21', 40, N'/assets/A21-gr.jpg'),
    (N'TERM-QUICK-CONNECT', N'TERM-A30', N'A30', N'Spade Male', N'a30', 10, N'/assets/A30-gr.jpg'),
    (N'TERM-QUICK-CONNECT', N'TERM-A33', N'A33', N'Spade Male', N'a33', 20, N'/assets/A33-gr.jpg'),
    (N'TERM-QUICK-CONNECT', N'TERM-A31', N'A31', N'Spade Female', N'a31', 30, N'/assets/A31-gr.jpg'),
    (N'TERM-QUICK-CONNECT', N'TERM-A32', N'A32', N'Spade Female', N'a32', 40, N'/assets/A32-gr.jpg'),
    (N'TERM-QUICK-CONNECT', N'TERM-A37', N'A37', N'Splice Connectors', N'a37', 50, N'/assets/A37-gr.jpg'),
    (N'TERM-QUICK-CONNECT', N'TERM-A38', N'A38', N'Splice Heat-Shrink', N'a38', 60, N'/assets/A38-gr.jpg'),
    (N'TERM-QUICK-CONNECT', N'TERM-A34', N'A34', N'Spade Female', N'a34', 70, N'/assets/A34-gr.jpg'),
    (N'TERM-RING-FORK', N'TERM-A05', N'A05', N'Ring', N'a05', 10, N'/assets/A05-gr.jpg'),
    (N'TERM-RING-FORK', N'TERM-A25', N'A25', N'Ring DG', N'a25', 20, N'/assets/A25-gr.jpg'),
    (N'TERM-RING-FORK', N'TERM-A07', N'A07', N'Fork', N'a07', 30, N'/assets/A07-gr.jpg'),
    (N'TERM-RING-FORK', N'TERM-A27', N'A27', N'Fork DG', N'a27', 40, N'/assets/A27-gr.jpg'),
    (N'TERM-RING-FORK', N'TERM-A06', N'A06', N'Ring', N'a06', 50, N'/assets/A06-gr.jpg'),
    (N'TERM-RING-FORK', N'TERM-A08', N'A08', N'Fork', N'a08', 60, N'/assets/A08-gr.jpg');

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
