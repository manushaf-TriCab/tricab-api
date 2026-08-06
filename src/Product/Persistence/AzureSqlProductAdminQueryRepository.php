<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlBinary;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\ProductAdminDetail;
use TriCab\Core\Product\Application\DTO\ProductAdminListItem;
use TriCab\Core\Product\Application\DTO\ProductListCriteria;
use TriCab\Core\Product\Application\DTO\ProductListResult;
use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

/**
 * Admin query repository — DTO reads only.
 * Default excludes soft-deleted; archived included unless filtered.
 */
final class AzureSqlProductAdminQueryRepository implements ProductAdminQueryRepository
{
    private const LIST_SELECT_SQL = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[ProductName],
    p.[InternalCode],
    (
        SELECT TOP 1 pc.[CodeValue]
        FROM [product].[ProductCodes] pc
        WHERE pc.[ProductId] = p.[ProductId]
          AND pc.[IsPrimary] = 1
          AND pc.[DeletedAtUtc] IS NULL
        ORDER BY pc.[ProductCodeId]
    ) AS [PrimaryProductCode],
    (
        SELECT TOP 1 img.[PublicUrl]
        FROM [product].[ProductImages] img
        WHERE img.[ProductId] = p.[ProductId]
          AND img.[IsPrimary] = 1
          AND img.[DeletedAtUtc] IS NULL
        ORDER BY img.[DisplayOrder]
    ) AS [ThumbnailUrl],
    (
        SELECT STRING_AGG(r.[RegionCode], N',') WITHIN GROUP (ORDER BY r.[DisplayOrder])
        FROM [product].[ProductRegions] pr
        INNER JOIN [product].[Regions] r ON r.[RegionId] = pr.[RegionId]
        WHERE pr.[ProductId] = p.[ProductId]
          AND pr.[DeletedAtUtc] IS NULL
    ) AS [RegionCodesCsv],
    f.[FamilyName],
    c.[CategoryName],
    s.[SeriesName],
    (
        SELECT COUNT(*)
        FROM [product].[ProductVariants] v
        WHERE v.[ProductId] = p.[ProductId]
          AND v.[DeletedAtUtc] IS NULL
    ) AS [VariantCount],
    ps.[StatusCode],
    pls.[LifecycleStatusCode],
    CONVERT(VARCHAR(33), p.[UpdatedAtUtc], 127) AS [UpdatedAtUtc],
    u.[DisplayName] AS [UpdatedByDisplayName],
    CASE WHEN ps.[StatusCode] = N'archived' THEN 1 ELSE 0 END AS [IsArchived],
    CONVERT(VARBINARY(8), p.[RowVersion]) AS [RowVersion]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
INNER JOIN [product].[ProductLifecycleStatuses] pls ON pls.[ProductLifecycleStatusId] = p.[ProductLifecycleStatusId]
LEFT JOIN [product].[ProductFamilies] f ON f.[ProductFamilyId] = p.[ProductFamilyId]
LEFT JOIN [product].[ProductCategories] c ON c.[ProductCategoryId] = p.[ProductCategoryId]
LEFT JOIN [product].[ProductSeries] s ON s.[ProductSeriesId] = p.[ProductSeriesId]
LEFT JOIN [identity].[Users] u ON u.[UserId] = p.[UpdatedByUserId]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function list(ProductListCriteria $criteria): ProductListResult
    {
        [$where, $params] = $this->buildFilters($criteria);

        $countRows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [Total] FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
INNER JOIN [product].[ProductLifecycleStatuses] pls ON pls.[ProductLifecycleStatusId] = p.[ProductLifecycleStatusId]
LEFT JOIN [product].[ProductFamilies] f ON f.[ProductFamilyId] = p.[ProductFamilyId]
LEFT JOIN [product].[ProductCategories] c ON c.[ProductCategoryId] = p.[ProductCategoryId]
LEFT JOIN [product].[ProductSeries] s ON s.[ProductSeriesId] = p.[ProductSeriesId]
WHERE 1 = 1' . $where,
            $params,
        );
        $total = $countRows === [] ? 0 : AzureSqlValueMapper::intValue($countRows[0]['Total'] ?? 0);

        $sortColumn = $this->sortColumn($criteria->sortBy);
        $sortDirection = $criteria->normalizedSortDirection() === ProductListCriteria::SORT_ASC ? 'ASC' : 'DESC';
        $offset = ($criteria->page - 1) * $criteria->pageSize;

        $rows = $this->connection->fetchAll(
            self::LIST_SELECT_SQL . <<<SQL

WHERE 1 = 1{$where}
ORDER BY {$sortColumn} {$sortDirection}
OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY
SQL,
            [...$params, ':offset' => $offset, ':pageSize' => $criteria->pageSize],
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->hydrateListItem($row);
        }

        return new ProductListResult(
            items: $items,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function findDetailByPublicId(ProductPublicId $publicId): ?ProductAdminDetail
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[ProductName],
    p.[InternalName],
    p.[InternalCode],
    p.[ShortDescription],
    p.[FullDescription],
    CONVERT(NVARCHAR(36), f.[PublicId]) AS [FamilyPublicId],
    CONVERT(NVARCHAR(36), c.[PublicId]) AS [CategoryPublicId],
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [SeriesPublicId],
    ps.[StatusCode],
    pls.[LifecycleStatusCode],
    p.[IsFeatured],
    CONVERT(VARCHAR(33), p.[PublishAtUtc], 127) AS [PublishAtUtc],
    CONVERT(VARCHAR(33), p.[UnpublishAtUtc], 127) AS [UnpublishAtUtc],
    CONVERT(VARCHAR(33), p.[DiscontinueAtUtc], 127) AS [DiscontinueAtUtc],
    p.[ErpReference],
    p.[LegacyProductId],
    p.[IsActive],
    CASE WHEN ps.[StatusCode] = N'archived' THEN 1 ELSE 0 END AS [IsArchived],
    CONVERT(VARCHAR(33), p.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(VARCHAR(33), p.[UpdatedAtUtc], 127) AS [UpdatedAtUtc],
    cu.[DisplayName] AS [CreatedByDisplayName],
    uu.[DisplayName] AS [UpdatedByDisplayName],
    CONVERT(VARBINARY(8), p.[RowVersion]) AS [RowVersion],
    (
        SELECT TOP 1 slug.[Slug]
        FROM [product].[ProductSlugs] slug
        WHERE slug.[ProductId] = p.[ProductId]
          AND slug.[IsPrimary] = 1
          AND slug.[DeletedAtUtc] IS NULL
        ORDER BY slug.[ProductSlugId]
    ) AS [PrimarySlug]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
INNER JOIN [product].[ProductLifecycleStatuses] pls ON pls.[ProductLifecycleStatusId] = p.[ProductLifecycleStatusId]
LEFT JOIN [product].[ProductFamilies] f ON f.[ProductFamilyId] = p.[ProductFamilyId]
LEFT JOIN [product].[ProductCategories] c ON c.[ProductCategoryId] = p.[ProductCategoryId]
LEFT JOIN [product].[ProductSeries] s ON s.[ProductSeriesId] = p.[ProductSeriesId]
LEFT JOIN [identity].[Users] cu ON cu.[UserId] = p.[CreatedByUserId]
LEFT JOIN [identity].[Users] uu ON uu.[UserId] = p.[UpdatedByUserId]
WHERE p.[PublicId] = :publicId
  AND p.[DeletedAtUtc] IS NULL
SQL,
            [':publicId' => $publicId->toString()],
        );

        if ($rows === []) {
            return null;
        }

        $row = $rows[0];
        $variants = $this->listVariantSummariesByProduct($publicId, includeArchived: true, includeDeleted: false);

        return new ProductAdminDetail(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            productName: AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
            internalName: AzureSqlValueMapper::nullableString($row['InternalName'] ?? null),
            internalCode: AzureSqlValueMapper::nullableString($row['InternalCode'] ?? null),
            shortDescription: AzureSqlValueMapper::nullableString($row['ShortDescription'] ?? null),
            fullDescription: AzureSqlValueMapper::nullableString($row['FullDescription'] ?? null),
            familyPublicId: AzureSqlValueMapper::nullableString($row['FamilyPublicId'] ?? null),
            categoryPublicId: AzureSqlValueMapper::nullableString($row['CategoryPublicId'] ?? null),
            seriesPublicId: AzureSqlValueMapper::nullableString($row['SeriesPublicId'] ?? null),
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
            lifecycleStatusCode: AzureSqlValueMapper::stringValue($row['LifecycleStatusCode'] ?? ''),
            isFeatured: AzureSqlValueMapper::boolValue($row['IsFeatured'] ?? false),
            publishAtUtc: AzureSqlValueMapper::nullableString($row['PublishAtUtc'] ?? null),
            unpublishAtUtc: AzureSqlValueMapper::nullableString($row['UnpublishAtUtc'] ?? null),
            discontinueAtUtc: AzureSqlValueMapper::nullableString($row['DiscontinueAtUtc'] ?? null),
            erpReference: AzureSqlValueMapper::nullableString($row['ErpReference'] ?? null),
            legacyProductId: AzureSqlValueMapper::nullableString($row['LegacyProductId'] ?? null),
            variants: $variants,
            regionCodes: $this->scalarCodesForProduct($publicId, 'regions'),
            marketCodes: $this->scalarCodesForProduct($publicId, 'markets'),
            industryCodes: $this->scalarCodesForProduct($publicId, 'industries'),
            applicationCodes: $this->scalarCodesForProduct($publicId, 'applications'),
            primarySlug: AzureSqlValueMapper::nullableString($row['PrimarySlug'] ?? null),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            isArchived: AzureSqlValueMapper::boolValue($row['IsArchived'] ?? false),
            createdAtUtc: AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
            updatedAtUtc: AzureSqlValueMapper::stringValue($row['UpdatedAtUtc'] ?? ''),
            createdByDisplayName: AzureSqlValueMapper::nullableString($row['CreatedByDisplayName'] ?? null),
            updatedByDisplayName: AzureSqlValueMapper::nullableString($row['UpdatedByDisplayName'] ?? null),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
        );
    }

    public function listVariantSummariesByProduct(
        ProductPublicId $productPublicId,
        bool $includeArchived = true,
        bool $includeDeleted = false,
    ): array {
        $where = ' WHERE p.[PublicId] = :productPublicId';
        $params = [':productPublicId' => $productPublicId->toString()];
        if (!$includeDeleted) {
            $where .= ' AND v.[DeletedAtUtc] IS NULL';
        }
        if (!$includeArchived) {
            $where .= " AND ps.[StatusCode] <> N'archived'";
        }

        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [PublicId],
    v.[VariantName],
    v.[Sku],
    v.[VariantCode],
    ps.[StatusCode],
    v.[DisplayOrder],
    v.[IsActive]
FROM [product].[ProductVariants] v
INNER JOIN [product].[Products] p ON p.[ProductId] = v.[ProductId]
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = v.[ProductStatusId]
{$where}
ORDER BY v.[DisplayOrder] ASC, v.[ProductVariantId] ASC
SQL,
            $params,
        );

        return array_map(fn (array $row): ProductVariantSummary => new ProductVariantSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            variantName: AzureSqlValueMapper::stringValue($row['VariantName'] ?? ''),
            sku: AzureSqlValueMapper::nullableString($row['Sku'] ?? null),
            variantCode: AzureSqlValueMapper::nullableString($row['VariantCode'] ?? null),
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
        ), $rows);
    }

    public function findVariantSummaryByPublicId(
        ProductVariantPublicId $publicId,
        bool $includeDeleted = false,
    ): ?ProductVariantSummary {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [PublicId],
    v.[VariantName],
    v.[Sku],
    v.[VariantCode],
    ps.[StatusCode],
    v.[DisplayOrder],
    v.[IsActive]
FROM [product].[ProductVariants] v
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = v.[ProductStatusId]
WHERE v.[PublicId] = :publicId
SQL;
        if (!$includeDeleted) {
            $sql .= ' AND v.[DeletedAtUtc] IS NULL';
        }

        $rows = $this->connection->fetchAll($sql, [':publicId' => $publicId->toString()]);
        if ($rows === []) {
            return null;
        }

        $row = $rows[0];

        return new ProductVariantSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            variantName: AzureSqlValueMapper::stringValue($row['VariantName'] ?? ''),
            sku: AzureSqlValueMapper::nullableString($row['Sku'] ?? null),
            variantCode: AzureSqlValueMapper::nullableString($row['VariantCode'] ?? null),
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
        );
    }

    /**
     * @return array{0: string, 1: array<string, scalar|SqlBinary|null>}
     */
    private function buildFilters(ProductListCriteria $criteria): array
    {
        $where = '';
        $params = [];

        if (!$criteria->includeDeleted) {
            $where .= ' AND p.[DeletedAtUtc] IS NULL';
        }

        if ($criteria->isArchived === true) {
            $where .= " AND ps.[StatusCode] = N'archived'";
        } elseif ($criteria->isArchived === false) {
            $where .= " AND ps.[StatusCode] <> N'archived'";
        }

        if ($criteria->isActive !== null) {
            $where .= ' AND p.[IsActive] = :isActive';
            $params[':isActive'] = $criteria->isActive ? 1 : 0;
        }

        if ($criteria->statusCode !== null) {
            $where .= ' AND ps.[StatusCode] = :statusCode';
            $params[':statusCode'] = $criteria->statusCode;
        }

        if ($criteria->lifecycleStatusCode !== null) {
            $where .= ' AND pls.[LifecycleStatusCode] = :lifecycleStatusCode';
            $params[':lifecycleStatusCode'] = $criteria->lifecycleStatusCode;
        }

        if ($criteria->familyPublicId !== null) {
            $where .= ' AND CONVERT(NVARCHAR(36), f.[PublicId]) = :familyPublicId';
            $params[':familyPublicId'] = $criteria->familyPublicId;
        }

        if ($criteria->categoryPublicId !== null) {
            $where .= ' AND CONVERT(NVARCHAR(36), c.[PublicId]) = :categoryPublicId';
            $params[':categoryPublicId'] = $criteria->categoryPublicId;
        }

        if ($criteria->seriesPublicId !== null) {
            $where .= ' AND CONVERT(NVARCHAR(36), s.[PublicId]) = :seriesPublicId';
            $params[':seriesPublicId'] = $criteria->seriesPublicId;
        }

        $search = $criteria->normalizedSearch();
        if ($search !== null) {
            $where .= <<<'SQL'
 AND (
    p.[ProductName] LIKE :searchName
    OR p.[InternalCode] LIKE :searchCode
    OR CONVERT(NVARCHAR(36), p.[PublicId]) LIKE :searchPublicId
 )
SQL;
            $like = '%' . $search . '%';
            $params[':searchName'] = $like;
            $params[':searchCode'] = $like;
            $params[':searchPublicId'] = $like;
        }

        if ($criteria->hasVariants === true) {
            $where .= ' AND EXISTS (SELECT 1 FROM [product].[ProductVariants] hv WHERE hv.[ProductId] = p.[ProductId] AND hv.[DeletedAtUtc] IS NULL)';
        } elseif ($criteria->hasVariants === false) {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM [product].[ProductVariants] hv WHERE hv.[ProductId] = p.[ProductId] AND hv.[DeletedAtUtc] IS NULL)';
        }

        if ($criteria->hasDocuments === true) {
            $where .= ' AND EXISTS (SELECT 1 FROM [product].[ProductDocuments] hd WHERE hd.[ProductId] = p.[ProductId] AND hd.[DeletedAtUtc] IS NULL)';
        } elseif ($criteria->hasDocuments === false) {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM [product].[ProductDocuments] hd WHERE hd.[ProductId] = p.[ProductId] AND hd.[DeletedAtUtc] IS NULL)';
        }

        if ($criteria->hasMedia === true) {
            $where .= ' AND EXISTS (SELECT 1 FROM [product].[ProductImages] hm WHERE hm.[ProductId] = p.[ProductId] AND hm.[DeletedAtUtc] IS NULL)';
        } elseif ($criteria->hasMedia === false) {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM [product].[ProductImages] hm WHERE hm.[ProductId] = p.[ProductId] AND hm.[DeletedAtUtc] IS NULL)';
        }

        if ($criteria->hasPricing === true) {
            $where .= ' AND EXISTS (SELECT 1 FROM [product].[ProductPrices] hp WHERE hp.[ProductId] = p.[ProductId] AND hp.[DeletedAtUtc] IS NULL)';
        } elseif ($criteria->hasPricing === false) {
            $where .= ' AND NOT EXISTS (SELECT 1 FROM [product].[ProductPrices] hp WHERE hp.[ProductId] = p.[ProductId] AND hp.[DeletedAtUtc] IS NULL)';
        }

        if ($criteria->regionPublicId !== null) {
            $where .= <<<'SQL'
 AND EXISTS (
    SELECT 1
    FROM [product].[ProductRegions] pr
    INNER JOIN [product].[Regions] r ON r.[RegionId] = pr.[RegionId]
    WHERE pr.[ProductId] = p.[ProductId]
      AND pr.[DeletedAtUtc] IS NULL
      AND CONVERT(NVARCHAR(36), r.[PublicId]) = :regionPublicId
 )
SQL;
            $params[':regionPublicId'] = $criteria->regionPublicId;
        }

        if ($criteria->marketPublicId !== null) {
            $where .= <<<'SQL'
 AND EXISTS (
    SELECT 1
    FROM [product].[ProductMarkets] pm
    INNER JOIN [product].[Markets] m ON m.[MarketId] = pm.[MarketId]
    WHERE pm.[ProductId] = p.[ProductId]
      AND pm.[DeletedAtUtc] IS NULL
      AND CONVERT(NVARCHAR(36), m.[PublicId]) = :marketPublicId
 )
SQL;
            $params[':marketPublicId'] = $criteria->marketPublicId;
        }

        if ($criteria->industryPublicId !== null) {
            $where .= <<<'SQL'
 AND EXISTS (
    SELECT 1
    FROM [product].[ProductIndustries] pi
    INNER JOIN [product].[Industries] i ON i.[IndustryId] = pi.[IndustryId]
    WHERE pi.[ProductId] = p.[ProductId]
      AND CONVERT(NVARCHAR(36), i.[PublicId]) = :industryPublicId
 )
SQL;
            $params[':industryPublicId'] = $criteria->industryPublicId;
        }

        if ($criteria->applicationPublicId !== null) {
            $where .= <<<'SQL'
 AND EXISTS (
    SELECT 1
    FROM [product].[ProductApplications] pa
    INNER JOIN [product].[Applications] a ON a.[ApplicationId] = pa.[ApplicationId]
    WHERE pa.[ProductId] = p.[ProductId]
      AND CONVERT(NVARCHAR(36), a.[PublicId]) = :applicationPublicId
 )
SQL;
            $params[':applicationPublicId'] = $criteria->applicationPublicId;
        }

        if ($criteria->updatedFromUtc !== null) {
            $where .= ' AND p.[UpdatedAtUtc] >= :updatedFromUtc';
            $params[':updatedFromUtc'] = $criteria->updatedFromUtc;
        }

        if ($criteria->updatedToUtc !== null) {
            $where .= ' AND p.[UpdatedAtUtc] <= :updatedToUtc';
            $params[':updatedToUtc'] = $criteria->updatedToUtc;
        }

        // Missing required information: no primary slug OR no status published readiness heuristic.
        if ($criteria->missingRequiredInformation === true) {
            $where .= <<<'SQL'
 AND (
    NOT EXISTS (
        SELECT 1 FROM [product].[ProductSlugs] ms
        WHERE ms.[ProductId] = p.[ProductId] AND ms.[IsPrimary] = 1 AND ms.[DeletedAtUtc] IS NULL
    )
    OR p.[ShortDescription] IS NULL
 )
SQL;
        }

        return [$where, $params];
    }

    private function sortColumn(string $sortBy): string
    {
        return match ($sortBy) {
            ProductListCriteria::SORT_NAME => 'p.[ProductName]',
            ProductListCriteria::SORT_PRODUCT_CODE => 'p.[InternalCode]',
            ProductListCriteria::SORT_CATEGORY => 'c.[CategoryName]',
            ProductListCriteria::SORT_SERIES => 's.[SeriesName]',
            ProductListCriteria::SORT_STATUS => 'ps.[StatusCode]',
            ProductListCriteria::SORT_LIFECYCLE => 'pls.[LifecycleStatusCode]',
            ProductListCriteria::SORT_CREATED_AT => 'p.[CreatedAtUtc]',
            ProductListCriteria::SORT_DISPLAY_ORDER => 'p.[ProductId]',
            default => 'p.[UpdatedAtUtc]',
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateListItem(array $row): ProductAdminListItem
    {
        $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        $regionCsv = AzureSqlValueMapper::nullableString($row['RegionCodesCsv'] ?? null);
        $regionCodes = $regionCsv === null || $regionCsv === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $regionCsv))));

        return new ProductAdminListItem(
            publicId: $publicId,
            productName: AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
            internalCode: AzureSqlValueMapper::nullableString($row['InternalCode'] ?? null),
            primaryProductCode: AzureSqlValueMapper::nullableString($row['PrimaryProductCode'] ?? null),
            thumbnailUrl: AzureSqlValueMapper::nullableString($row['ThumbnailUrl'] ?? null),
            familyName: AzureSqlValueMapper::nullableString($row['FamilyName'] ?? null),
            categoryName: AzureSqlValueMapper::nullableString($row['CategoryName'] ?? null),
            seriesName: AzureSqlValueMapper::nullableString($row['SeriesName'] ?? null),
            variantCount: AzureSqlValueMapper::intValue($row['VariantCount'] ?? 0),
            regionCodes: $regionCodes,
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
            lifecycleStatusCode: AzureSqlValueMapper::stringValue($row['LifecycleStatusCode'] ?? ''),
            updatedAtUtc: AzureSqlValueMapper::stringValue($row['UpdatedAtUtc'] ?? ''),
            updatedByDisplayName: AzureSqlValueMapper::nullableString($row['UpdatedByDisplayName'] ?? null),
            isArchived: AzureSqlValueMapper::boolValue($row['IsArchived'] ?? false),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
        );
    }

    /**
     * @return list<string>
     */
    private function scalarCodesForProduct(ProductPublicId $publicId, string $kind): array
    {
        $sql = match ($kind) {
            'regions' => <<<'SQL'
SELECT r.[RegionCode] AS [Code]
FROM [product].[ProductRegions] pr
INNER JOIN [product].[Regions] r ON r.[RegionId] = pr.[RegionId]
INNER JOIN [product].[Products] p ON p.[ProductId] = pr.[ProductId]
WHERE p.[PublicId] = :publicId AND pr.[DeletedAtUtc] IS NULL
ORDER BY r.[DisplayOrder]
SQL,
            'markets' => <<<'SQL'
SELECT m.[MarketCode] AS [Code]
FROM [product].[ProductMarkets] pm
INNER JOIN [product].[Markets] m ON m.[MarketId] = pm.[MarketId]
INNER JOIN [product].[Products] p ON p.[ProductId] = pm.[ProductId]
WHERE p.[PublicId] = :publicId AND pm.[DeletedAtUtc] IS NULL
ORDER BY m.[DisplayOrder]
SQL,
            'industries' => <<<'SQL'
SELECT i.[IndustryCode] AS [Code]
FROM [product].[ProductIndustries] pi
INNER JOIN [product].[Industries] i ON i.[IndustryId] = pi.[IndustryId]
INNER JOIN [product].[Products] p ON p.[ProductId] = pi.[ProductId]
WHERE p.[PublicId] = :publicId
ORDER BY pi.[DisplayOrder]
SQL,
            default => <<<'SQL'
SELECT a.[ApplicationCode] AS [Code]
FROM [product].[ProductApplications] pa
INNER JOIN [product].[Applications] a ON a.[ApplicationId] = pa.[ApplicationId]
INNER JOIN [product].[Products] p ON p.[ProductId] = pa.[ProductId]
WHERE p.[PublicId] = :publicId
ORDER BY pa.[DisplayOrder]
SQL,
        };

        $rows = $this->connection->fetchAll($sql, [':publicId' => $publicId->toString()]);

        return array_values(array_filter(array_map(
            static fn (array $row): string => AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
            $rows,
        )));
    }
}
