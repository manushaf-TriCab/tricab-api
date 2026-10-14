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
use TriCab\Core\Product\Domain\ValueObject\ThumbnailBackgroundColor;

/**
 * Azure SQL search boundary suitable for later Azure AI Search replacement.
 * Supports exact/prefix code/SKU/variant code, partial name/description,
 * slug, and region-aware published filtering.
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
    p.[ThumbnailBackgroundColor],
    (
        SELECT TOP 1 COALESCE(img.[ThumbnailPublicUrl], img.[PublicUrl])
        FROM [product].[ProductImages] img
        WHERE img.[ProductId] = p.[ProductId]
          AND img.[DeletedAtUtc] IS NULL
          AND img.[IsActive] = 1
          AND COALESCE(img.[ThumbnailPublicUrl], img.[PublicUrl]) IS NOT NULL
          AND (img.[UploadStatusCode] IS NULL OR img.[UploadStatusCode] = N'active')
          AND (
                img.[ScanStatusCode] IS NULL
                OR img.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
          )
        ORDER BY
          CASE WHEN EXISTS (
              SELECT 1
              FROM [product].[ProductImageRoleAssignments] a
              WHERE a.[ProductImagesId] = img.[ProductImagesId]
                AND a.[RoleCode] = N'product-thumbnail'
          ) THEN 0 ELSE 1 END,
          CASE LOWER(img.[ImageRoleCode])
              WHEN N'product-thumbnail' THEN 0
              WHEN N'primary' THEN 1
              ELSE 2
          END,
          CASE WHEN img.[IsPrimary] = 1 THEN 0 ELSE 1 END,
          img.[DisplayOrder],
          img.[ProductImagesId]
    ) AS [ThumbnailImageUrl],
    (
        SELECT TOP 1 thumb.[Url]
        FROM (
            -- Catalogue side-panel leaf image (direct product placement)
            SELECT
                NULLIF(LTRIM(RTRIM(n.[ImageUrl])), N'') AS [Url],
                0 AS [SourceRank],
                cnp.[DisplayOrder] AS [SortOrder]
            FROM [product].[CatalogueNodeProducts] cnp
            INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId]
            WHERE cnp.[ProductId] = p.[ProductId]
              AND n.[DeletedAtUtc] IS NULL
              AND n.[IsActive] = 1
              AND n.[IsPublic] = 1
              AND NULLIF(LTRIM(RTRIM(n.[ImageUrl])), N'') IS NOT NULL
            UNION ALL
            -- Catalogue side-panel image via style placement
            SELECT
                NULLIF(LTRIM(RTRIM(n.[ImageUrl])), N'') AS [Url],
                1 AS [SourceRank],
                cnps.[DisplayOrder] AS [SortOrder]
            FROM [product].[CatalogueNodeProductStyles] cnps
            INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnps.[CatalogueNodeId]
            INNER JOIN [product].[ProductStyles] ps ON ps.[ProductStyleId] = cnps.[ProductStyleId]
            INNER JOIN [product].[ProductStyleAssignments] psa ON psa.[ProductStyleId] = ps.[ProductStyleId]
            WHERE psa.[ProductId] = p.[ProductId]
              AND cnps.[IsActive] = 1
              AND n.[DeletedAtUtc] IS NULL
              AND n.[IsActive] = 1
              AND n.[IsPublic] = 1
              AND ps.[DeletedAtUtc] IS NULL
              AND NULLIF(LTRIM(RTRIM(n.[ImageUrl])), N'') IS NOT NULL
            UNION ALL
            -- Catalogue leaf named/coded like the product series (e.g. BV.png side panel)
            SELECT
                NULLIF(LTRIM(RTRIM(n.[ImageUrl])), N'') AS [Url],
                2 AS [SourceRank],
                n.[DisplayOrder] AS [SortOrder]
            FROM [product].[CatalogueNodes] n
            WHERE n.[DeletedAtUtc] IS NULL
              AND n.[IsActive] = 1
              AND n.[IsPublic] = 1
              AND p.[InternalCode] IS NOT NULL
              AND (
                  n.[NodeName] = p.[InternalCode]
                  OR n.[NodeCode] = p.[InternalCode]
              )
              AND NULLIF(LTRIM(RTRIM(n.[ImageUrl])), N'') IS NOT NULL
            UNION ALL
            -- Product media, preferring primary/thumbnail/hero over cross-section
            SELECT
                COALESCE(img.[ThumbnailPublicUrl], img.[PublicUrl]) AS [Url],
                3 AS [SourceRank],
                CASE LOWER(img.[ImageRoleCode])
                    WHEN N'product-thumbnail' THEN -1
                    WHEN N'primary' THEN 0
                    WHEN N'thumbnail' THEN 1
                    WHEN N'hero' THEN 2
                    WHEN N'gallery' THEN 3
                    ELSE 4
                END AS [SortOrder]
            FROM [product].[ProductImages] img
            WHERE img.[ProductId] = p.[ProductId]
              AND img.[DeletedAtUtc] IS NULL
              AND COALESCE(img.[ThumbnailPublicUrl], img.[PublicUrl]) IS NOT NULL
        ) thumb
        WHERE thumb.[Url] IS NOT NULL
        ORDER BY thumb.[SourceRank], thumb.[SortOrder]
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
            WHERE vx.[ProductId] = p.[ProductId]
              AND vx.[DeletedAtUtc] IS NULL
              AND (vx.[Sku] = :rankExactSku OR vx.[VariantCode] = :rankExactVariantCode)
         ) THEN 1
         WHEN p.[InternalCode] LIKE :rankPrefixInternal THEN 2
         WHEN EXISTS (
            SELECT 1 FROM [product].[ProductVariants] vx
            WHERE vx.[ProductId] = p.[ProductId]
              AND vx.[DeletedAtUtc] IS NULL
              AND (
                  vx.[Sku] LIKE :rankPrefixSku
                  OR vx.[VariantCode] LIKE :rankPrefixVariantCode
              )
         ) THEN 3
         WHEN p.[ProductName] = :rankExactName THEN 4
         ELSE 5 END ASC,
    p.[ProductName] ASC
OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
SQL,
            [
                ...$params,
                ':rankExactInternal' => $query,
                ':rankExactSku' => $query,
                ':rankExactVariantCode' => $query,
                ':rankPrefixInternal' => $query . '%',
                ':rankPrefixSku' => $query . '%',
                ':rankPrefixVariantCode' => $query . '%',
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
            thumbnailImageUrl: AzureSqlValueMapper::nullableString($row['ThumbnailImageUrl'] ?? null),
            thumbnailBackgroundColor: ThumbnailBackgroundColor::tryFromString(
                AzureSqlValueMapper::nullableString($row['ThumbnailBackgroundColor'] ?? null),
            )?->toString(),
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
     * Free-text search was matching one opaque '%whole query%' against every column,
     * so "marine 3.3kv cable" found nothing: no single column contains that string.
     * Now every token must match somewhere — AND across tokens, OR across columns —
     * while the whole query is still tried as an exact or prefix identity match, so
     * pasting a full code or SKU keeps ranking first.
     *
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
        // sqlsrv rejects duplicate named parameters; each occurrence needs a distinct name.
        $prefix = self::escapeLike($query) . '%';
        /** @var array<string, scalar|SqlBinary|null> $params */
        $params = [
            ':exactInternal' => $query,
            ':prefixInternal' => $prefix,
            ':exactSku' => $query,
            ':prefixSku' => $prefix,
            ':exactVariantCode' => $query,
            ':prefixVariantCode' => $prefix,
            ':exactSlug' => $query,
            ':exactCode' => $query,
            ':prefixCode' => $prefix,
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

        $tokenClauses = [];
        foreach (self::tokenize($query) as $index => $token) {
            [$clause, $tokenParams] = self::tokenClause($index, $token);
            $tokenClauses[] = $clause;
            $params = [...$params, ...$tokenParams];
        }
        $tokensMatch = $tokenClauses === []
            ? '1 = 0'
            : "(\n" . implode("\n    AND ", $tokenClauses) . "\n    )";

        $where .= <<<SQL
 AND (
    p.[InternalCode] = :exactInternal
    OR p.[InternalCode] LIKE :prefixInternal ESCAPE N'~'
    OR EXISTS (
        SELECT 1 FROM [product].[ProductVariants] v
        WHERE v.[ProductId] = p.[ProductId]
          AND v.[DeletedAtUtc] IS NULL
          AND (
              v.[Sku] = :exactSku
              OR v.[Sku] LIKE :prefixSku ESCAPE N'~'
              OR v.[VariantCode] = :exactVariantCode
              OR v.[VariantCode] LIKE :prefixVariantCode ESCAPE N'~'
          )
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
          AND (
              pc.[CodeValue] = :exactCode
              OR pc.[CodeValue] LIKE :prefixCode ESCAPE N'~'
          )
    )
    OR {$tokensMatch}
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

    /**
     * One token, OR'd across every searchable column. All of these must hold for the
     * row to match, one clause per token.
     *
     * @return array{0: string, 1: array<string, scalar|SqlBinary|null>}
     */
    private static function tokenClause(int $index, string $token): array
    {
        $like = '%' . self::escapeLike($token) . '%';
        $names = [
            'Name', 'Sku', 'VariantCode', 'Internal', 'Code', 'Category', 'Series',
            'ShortDescription', 'FullDescription', 'ApplicationName', 'ApplicationCode',
            'SpecificationName', 'SpecificationCode', 'SpecificationText',
            'SpecificationLongText', 'SpecificationOption', 'SpecificationUnit',
        ];

        /** @var array<string, scalar|SqlBinary|null> $params */
        $params = [];
        /** @var array<string, string> $p */
        $p = [];
        foreach ($names as $name) {
            $placeholder = ':t' . $index . $name;
            $p[$name] = $placeholder;
            $params[$placeholder] = $like;
        }

        $clause = <<<SQL
(
        p.[ProductName] LIKE {$p['Name']} ESCAPE N'~'
        OR p.[InternalCode] LIKE {$p['Internal']} ESCAPE N'~'
        OR EXISTS (
            SELECT 1 FROM [product].[ProductVariants] v
            WHERE v.[ProductId] = p.[ProductId]
              AND v.[DeletedAtUtc] IS NULL
              AND (
                  v.[Sku] LIKE {$p['Sku']} ESCAPE N'~'
                  OR v.[VariantCode] LIKE {$p['VariantCode']} ESCAPE N'~'
              )
        )
        OR EXISTS (
            SELECT 1 FROM [product].[ProductCodes] pc
            WHERE pc.[ProductId] = p.[ProductId]
              AND pc.[DeletedAtUtc] IS NULL
              AND pc.[CodeValue] LIKE {$p['Code']} ESCAPE N'~'
        )
        OR c.[CategoryName] LIKE {$p['Category']} ESCAPE N'~'
        OR s.[SeriesName] LIKE {$p['Series']} ESCAPE N'~'
        OR p.[ShortDescription] LIKE {$p['ShortDescription']} ESCAPE N'~'
        OR p.[FullDescription] LIKE {$p['FullDescription']} ESCAPE N'~'
        OR EXISTS (
            SELECT 1
            FROM [product].[ProductApplications] pa
            INNER JOIN [product].[Applications] a ON a.[ApplicationId] = pa.[ApplicationId]
            WHERE pa.[ProductId] = p.[ProductId]
              AND a.[DeletedAtUtc] IS NULL
              AND a.[IsActive] = 1
              AND (
                  a.[ApplicationName] LIKE {$p['ApplicationName']} ESCAPE N'~'
                  OR a.[ApplicationCode] LIKE {$p['ApplicationCode']} ESCAPE N'~'
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
                  sd.[DefinitionName] LIKE {$p['SpecificationName']} ESCAPE N'~'
                  OR sd.[DefinitionCode] LIKE {$p['SpecificationCode']} ESCAPE N'~'
                  OR psv.[ValueText] LIKE {$p['SpecificationText']} ESCAPE N'~'
                  OR psv.[ValueLongText] LIKE {$p['SpecificationLongText']} ESCAPE N'~'
                  OR so.[OptionLabel] LIKE {$p['SpecificationOption']} ESCAPE N'~'
                  OR COALESCE(psv.[UnitOverride], sd.[Unit]) LIKE {$p['SpecificationUnit']} ESCAPE N'~'
              )
        )
    )
SQL;

        return [$clause, $params];
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $query): array
    {
        $tokens = preg_split('/\s+/', trim($query));
        if ($tokens === false) {
            return [$query];
        }

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => $token !== '',
        )));
    }

    /**
     * A customer-typed '%' or '_' would otherwise widen the search rather than narrow it.
     *
     * Escaped with a tilde, not a backslash: PDO's placeholder scanner reads N'\' as an
     * unterminated string and drops every named parameter that follows it.
     */
    private static function escapeLike(string $value): string
    {
        return strtr($value, ['~' => '~~', '%' => '~%', '_' => '~_', '[' => '~[']);
    }
}
