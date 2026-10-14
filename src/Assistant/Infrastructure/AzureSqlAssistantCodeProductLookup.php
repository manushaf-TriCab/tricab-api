<?php

declare(strict_types=1);

namespace TriCab\Api\Assistant\Infrastructure;

use TriCab\Api\Assistant\Application\AssistantCodeProduct;
use TriCab\Api\Assistant\Application\AssistantCodeProductLookup;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Catalogue\CatalogueNavigationImageSigner;

/**
 * The product behind a code: the code's own variant when the index links one,
 * otherwise the product assigned to the code's style.
 *
 * A draft product still supplies the picture, since a photo of the construction
 * is true of the cable whether or not its page has gone live; two thirds of the
 * catalogue is still draft. Its page link and variant are withheld until it is
 * published, because neither works for a customer before then.
 *
 * The style is matched on the code's prefix first and the index's style second,
 * because the two differ for some families: `BHA-CATD/10C1.5OR` is indexed under
 * `BH` while its product is assigned to the `BHA` style.
 *
 * The thumbnail is chosen exactly as product search chooses it, so the card and
 * the search results show the same picture. Stored blob URLs are unsigned; the
 * signer adds a read signature at response time, as it does for the catalogue.
 */
final readonly class AzureSqlAssistantCodeProductLookup implements AssistantCodeProductLookup
{
    public function __construct(
        private SqlConnection $connection,
        private CatalogueNavigationImageSigner $images,
    ) {
    }

    public function forCode(string $codeValue, ?string $styleCode, ?int $coreCount): ?AssistantCodeProduct
    {
        $code = trim($codeValue);
        if ($code === '') {
            return null;
        }
        $prefix = strtoupper(explode('-', $code, 2)[0]);
        $indexStyle = $styleCode !== null && trim($styleCode) !== '' ? strtoupper(trim($styleCode)) : $prefix;
        $coreSuffix = $coreCount !== null ? '%-' . $coreCount . 'C' : '';

        $rows = $this->connection->fetchAll(
            <<<'SQL'
WITH [linked] AS (
    SELECT TOP 1 v.[ProductId], v.[PublicId] AS [VariantPublicId]
    FROM [product].[ProductCodeIndex] i
    INNER JOIN [product].[ProductVariants] v ON v.[ProductVariantId] = i.[ProductVariantId]
    WHERE i.[CodeValue] = :code
      AND v.[DeletedAtUtc] IS NULL
),
[styled] AS (
    SELECT TOP 1 a.[ProductId]
    FROM [product].[ProductStyleAssignments] a
    INNER JOIN [product].[ProductStyles] s ON s.[ProductStyleId] = a.[ProductStyleId]
    INNER JOIN [product].[Products] sp ON sp.[ProductId] = a.[ProductId]
    INNER JOIN [product].[ProductStatuses] sps ON sps.[ProductStatusId] = sp.[ProductStatusId]
    WHERE s.[DeletedAtUtc] IS NULL
      AND sp.[DeletedAtUtc] IS NULL
      AND sps.[StatusCode] IN (N'published', N'draft')
      AND s.[StyleCode] IN (:prefix, :indexStyle)
    ORDER BY
      CASE WHEN s.[StyleCode] = :preferredPrefix THEN 0 ELSE 1 END,
      CASE WHEN sps.[StatusCode] = N'published' THEN 0 ELSE 1 END,
      CASE WHEN :coreSuffix <> N'' AND sp.[InternalCode] LIKE :coreSuffixLike THEN 0 ELSE 1 END,
      sp.[ProductId]
)
SELECT
    CASE WHEN ps.[StatusCode] = N'published'
        THEN CONVERT(NVARCHAR(36), (SELECT [VariantPublicId] FROM [linked])) END AS [VariantPublicId],
    p.[ThumbnailBackgroundColor],
    CASE WHEN ps.[StatusCode] = N'published' THEN (
        SELECT TOP 1 slug.[Slug]
        FROM [product].[ProductSlugs] slug
        WHERE slug.[ProductId] = p.[ProductId]
          AND slug.[DeletedAtUtc] IS NULL
          AND slug.[IsPrimary] = 1
        ORDER BY slug.[ProductSlugId]
    ) END AS [Slug],
    (
        SELECT TOP 1 COALESCE(img.[ThumbnailPublicUrl], img.[PublicUrl])
        FROM [product].[ProductImages] img
        WHERE img.[ProductId] = p.[ProductId]
          AND img.[DeletedAtUtc] IS NULL
          AND COALESCE(img.[ThumbnailPublicUrl], img.[PublicUrl]) IS NOT NULL
        ORDER BY
          CASE WHEN EXISTS (
              SELECT 1
              FROM [product].[ProductImageRoleAssignments] ra
              WHERE ra.[ProductImagesId] = img.[ProductImagesId]
                AND ra.[RoleCode] = N'product-thumbnail'
          ) THEN 0 ELSE 1 END,
          CASE LOWER(img.[ImageRoleCode])
              WHEN N'product-thumbnail' THEN 0
              WHEN N'primary' THEN 1
              ELSE 2
          END,
          CASE WHEN img.[IsPrimary] = 1 THEN 0 ELSE 1 END,
          img.[DisplayOrder],
          img.[ProductImagesId]
    ) AS [ImageUrl]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
WHERE p.[ProductId] = COALESCE((SELECT [ProductId] FROM [linked]), (SELECT [ProductId] FROM [styled]))
  AND p.[DeletedAtUtc] IS NULL
  AND ps.[StatusCode] IN (N'published', N'draft')
SQL,
            [
                'code' => $code,
                'prefix' => $prefix,
                'indexStyle' => $indexStyle,
                'preferredPrefix' => $prefix,
                'coreSuffix' => $coreSuffix,
                'coreSuffixLike' => $coreSuffix,
            ],
        );

        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        return new AssistantCodeProduct(
            imageUrl: $this->images->present(AzureSqlValueMapper::nullableString($row['ImageUrl'] ?? null)),
            imageBackgroundColor: AzureSqlValueMapper::nullableString($row['ThumbnailBackgroundColor'] ?? null),
            productSlug: AzureSqlValueMapper::nullableString($row['Slug'] ?? null),
            variantPublicId: AzureSqlValueMapper::nullableString($row['VariantPublicId'] ?? null),
        );
    }
}
