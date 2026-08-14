<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\ProductSearchCriteria;
use TriCab\Core\Product\Application\DTO\ProductSearchListResult;
use TriCab\Core\Product\Application\DTO\ProductSearchResult;
use TriCab\Core\Product\Contract\ProductSearchRepository;

/**
 * Azure SQL search boundary suitable for later Azure AI Search replacement.
 * Supports exact code/SKU, partial name, slug, and region-aware published filtering.
 */
final class AzureSqlProductSearchRepository implements ProductSearchRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function search(ProductSearchCriteria $criteria): ProductSearchListResult
    {
        $query = $criteria->normalizedQuery();
        [$where, $params] = $this->buildWhere(
            $query,
            $criteria->regionCode,
            $criteria->publishedOnly,
            $criteria->normalizedStatusCodes(),
        );

        $countRows = $this->connection->fetchAll(
            <<<SQL
SELECT COUNT(*) AS [Total]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
LEFT JOIN [product].[ProductCategories] c ON c.[ProductCategoryId] = p.[ProductCategoryId]
LEFT JOIN [product].[ProductSeries] s ON s.[ProductSeriesId] = p.[ProductSeriesId]
WHERE 1 = 1
{$where}
SQL,
            $params,
        );
        $total = $countRows === [] ? 0 : AzureSqlValueMapper::intValue($countRows[0]['Total'] ?? 0);

        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[ProductName],
    p.[InternalCode] AS [ProductCode],
    (
        SELECT TOP 1 slug.[Slug]
        FROM [product].[ProductSlugs] slug
        WHERE slug.[ProductId] = p.[ProductId]
          AND slug.[DeletedAtUtc] IS NULL
          AND slug.[IsPrimary] = 1
        ORDER BY slug.[ProductSlugId]
    ) AS [Slug],
    c.[CategoryName],
    s.[SeriesName],
    ps.[StatusCode],
    (
        SELECT TOP 1 img.[PublicUrl]
        FROM [product].[ProductImages] img
        WHERE img.[ProductId] = p.[ProductId]
          AND img.[IsPrimary] = 1
          AND img.[DeletedAtUtc] IS NULL
        ORDER BY img.[DisplayOrder]
    ) AS [ThumbnailUrl]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
LEFT JOIN [product].[ProductCategories] c ON c.[ProductCategoryId] = p.[ProductCategoryId]
LEFT JOIN [product].[ProductSeries] s ON s.[ProductSeriesId] = p.[ProductSeriesId]
WHERE 1 = 1
{$where}
ORDER BY
    CASE WHEN p.[InternalCode] = :rankExactInternal THEN 0
         WHEN EXISTS (
            SELECT 1 FROM [product].[ProductVariants] vx
            WHERE vx.[ProductId] = p.[ProductId] AND vx.[DeletedAtUtc] IS NULL AND vx.[Sku] = :rankExactSku
         ) THEN 1
         WHEN p.[ProductName] = :rankExactName THEN 2
         ELSE 3 END ASC,
    p.[ProductName] ASC
OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
SQL,
            [
                ...$params,
                ':rankExactInternal' => $query,
                ':rankExactSku' => $query,
                ':rankExactName' => $query,
                ':offset' => $offset,
                ':pageSize' => $criteria->pageSize,
            ],
        );

        $items = array_map(static fn (array $row): ProductSearchResult => new ProductSearchResult(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            productName: AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
            productCode: AzureSqlValueMapper::nullableString($row['ProductCode'] ?? null),
            slug: AzureSqlValueMapper::nullableString($row['Slug'] ?? null),
            categoryName: AzureSqlValueMapper::nullableString($row['CategoryName'] ?? null),
            seriesName: AzureSqlValueMapper::nullableString($row['SeriesName'] ?? null),
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
            thumbnailUrl: AzureSqlValueMapper::nullableString($row['ThumbnailUrl'] ?? null),
        ), $rows);

        return new ProductSearchListResult(
            items: $items,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function suggest(string $query, int $limit = 10): array
    {
        $trimmed = trim($query);
        if ($trimmed === '' || $limit < 1) {
            return [];
        }

        $limit = min($limit, 25);
        $like = $trimmed . '%';

        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP 25 suggestion FROM (
    SELECT p.[ProductName] AS suggestion, 1 AS rankOrder
    FROM [product].[Products] p
    INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
    WHERE p.[DeletedAtUtc] IS NULL
      AND ps.[StatusCode] = N'published'
      AND p.[ProductName] LIKE :likePrefixName
    UNION
    SELECT p.[InternalCode] AS suggestion, 2 AS rankOrder
    FROM [product].[Products] p
    INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
    WHERE p.[DeletedAtUtc] IS NULL
      AND p.[InternalCode] IS NOT NULL
      AND p.[InternalCode] LIKE :likePrefixCode
    UNION
    SELECT v.[Sku] AS suggestion, 3 AS rankOrder
    FROM [product].[ProductVariants] v
    WHERE v.[DeletedAtUtc] IS NULL
      AND v.[Sku] IS NOT NULL
      AND v.[Sku] LIKE :likePrefixSku
) ranked
WHERE suggestion IS NOT NULL
ORDER BY rankOrder ASC, suggestion ASC
SQL,
            [
                ':likePrefixName' => $like,
                ':likePrefixCode' => $like,
                ':likePrefixSku' => $like,
            ],
        );

        $suggestions = [];
        foreach ($rows as $row) {
            $value = AzureSqlValueMapper::stringValue($row['suggestion'] ?? $row['Suggestion'] ?? '');
            if ($value !== '' && !in_array($value, $suggestions, true)) {
                $suggestions[] = $value;
            }
            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * @param list<string> $statusCodes
     * @return array{0: string, 1: array<string, scalar|SqlBinary|null>}
     */
    private function buildWhere(
        string $query,
        ?string $regionCode,
        bool $publishedOnly,
        array $statusCodes,
    ): array
    {
        $where = ' AND p.[DeletedAtUtc] IS NULL';
        $like = '%' . $query . '%';
        // sqlsrv rejects duplicate named parameters; each occurrence needs a distinct name.
        $params = [
            ':exactInternal' => $query,
            ':likeName' => $like,
            ':exactSku' => $query,
            ':exactSlug' => $query,
            ':exactCode' => $query,
            ':likeCategory' => $like,
            ':likeSeries' => $like,
            ':likeShortDescription' => $like,
            ':likeFullDescription' => $like,
            ':likeApplicationName' => $like,
            ':likeApplicationCode' => $like,
            ':likeSpecificationName' => $like,
            ':likeSpecificationCode' => $like,
            ':likeSpecificationText' => $like,
            ':likeSpecificationLongText' => $like,
            ':likeSpecificationOption' => $like,
            ':likeSpecificationUnit' => $like,
        ];

        if ($publishedOnly) {
            $where .= <<<'SQL'
 AND ps.[StatusCode] = N'published'
 AND p.[IsActive] = 1
 AND (p.[PublishAtUtc] IS NULL OR p.[PublishAtUtc] <= SYSUTCDATETIME())
 AND (p.[UnpublishAtUtc] IS NULL OR p.[UnpublishAtUtc] > SYSUTCDATETIME())
SQL;
        }

        if ($statusCodes !== []) {
            $statusPlaceholders = [];
            foreach ($statusCodes as $index => $statusCode) {
                $placeholder = ':allowedStatus' . $index;
                $statusPlaceholders[] = $placeholder;
                $params[$placeholder] = $statusCode;
            }
            $where .= ' AND LOWER(ps.[StatusCode]) IN (' . implode(', ', $statusPlaceholders) . ')';
        }

        $where .= <<<'SQL'
 AND (
    p.[InternalCode] = :exactInternal
    OR p.[ProductName] LIKE :likeName
    OR EXISTS (
        SELECT 1 FROM [product].[ProductVariants] v
        WHERE v.[ProductId] = p.[ProductId]
          AND v.[DeletedAtUtc] IS NULL
          AND v.[Sku] = :exactSku
    )
    OR EXISTS (
        SELECT 1 FROM [product].[ProductSlugs] slug
        WHERE slug.[ProductId] = p.[ProductId]
          AND slug.[DeletedAtUtc] IS NULL
          AND slug.[Slug] = :exactSlug
    )
    OR EXISTS (
        SELECT 1 FROM [product].[ProductCodes] pc
        WHERE pc.[ProductId] = p.[ProductId]
          AND pc.[DeletedAtUtc] IS NULL
          AND pc.[CodeValue] = :exactCode
    )
    OR c.[CategoryName] LIKE :likeCategory
    OR s.[SeriesName] LIKE :likeSeries
    OR p.[ShortDescription] LIKE :likeShortDescription
    OR p.[FullDescription] LIKE :likeFullDescription
    OR EXISTS (
        SELECT 1
        FROM [product].[ProductApplications] pa
        INNER JOIN [product].[Applications] a ON a.[ApplicationId] = pa.[ApplicationId]
        WHERE pa.[ProductId] = p.[ProductId]
          AND a.[DeletedAtUtc] IS NULL
          AND a.[IsActive] = 1
          AND (
              a.[ApplicationName] LIKE :likeApplicationName
              OR a.[ApplicationCode] LIKE :likeApplicationCode
          )
    )
    OR EXISTS (
        SELECT 1
        FROM [product].[ProductSpecificationValues] psv
        INNER JOIN [product].[SpecificationDefinitions] sd
            ON sd.[SpecificationDefinitionId] = psv.[SpecificationDefinitionId]
        LEFT JOIN [product].[SpecificationOptions] so
            ON so.[SpecificationOptionId] = psv.[SpecificationOptionId]
        WHERE psv.[ProductId] = p.[ProductId]
          AND psv.[DeletedAtUtc] IS NULL
          AND sd.[DeletedAtUtc] IS NULL
          AND sd.[IsActive] = 1
          AND (
              sd.[DefinitionName] LIKE :likeSpecificationName
              OR sd.[DefinitionCode] LIKE :likeSpecificationCode
              OR psv.[ValueText] LIKE :likeSpecificationText
              OR psv.[ValueLongText] LIKE :likeSpecificationLongText
              OR so.[OptionLabel] LIKE :likeSpecificationOption
              OR COALESCE(psv.[UnitOverride], sd.[Unit]) LIKE :likeSpecificationUnit
          )
    )
 )
SQL;

        if ($regionCode !== null && trim($regionCode) !== '') {
            $where .= <<<'SQL'
 AND EXISTS (
    SELECT 1
    FROM [product].[ProductRegions] pr
    INNER JOIN [product].[Regions] r ON r.[RegionId] = pr.[RegionId]
    WHERE pr.[ProductId] = p.[ProductId]
      AND pr.[DeletedAtUtc] IS NULL
      AND pr.[IsVisible] = 1
      AND r.[RegionCode] = :regionCode
 )
SQL;
            $params[':regionCode'] = trim($regionCode);
        }

        return [$where, $params];
    }
}
