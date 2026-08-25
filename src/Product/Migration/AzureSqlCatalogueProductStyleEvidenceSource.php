<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Migration;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Contract\CatalogueProductStyleEvidenceSource;

/**
 * Read-only Azure SQL snapshot for CatalogueNode → ProductStyle dry-run discovery.
 */
final class AzureSqlCatalogueProductStyleEvidenceSource implements CatalogueProductStyleEvidenceSource
{
    public function __construct(
        private readonly SqlConnection $db,
    ) {
    }

    public function loadSnapshot(): array
    {
        ++$this->azureReads;
        $nodes = $this->loadNodes();
        $directProducts = $this->loadDirectProducts();
        $styleAssignments = $this->loadStyleAssignments();
        $productStyles = $this->loadProductStyles();
        $products = $this->loadProducts();
        $productRegions = $this->loadProductRegions();
        $regions = $this->loadRegions();

        return [
            'nodes' => $nodes,
            'directProducts' => $directProducts,
            'styleAssignments' => $styleAssignments,
            'productStyles' => $productStyles,
            'products' => $products,
            'productRegions' => $productRegions,
            'regions' => $regions,
        ];
    }

    private int $azureReads = 0;

    /**
     * @return list<array<string, mixed>>
     */
    private function loadNodes(): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [PublicId],
    n.[NodeCode],
    n.[NodeName],
    n.[Slug],
    n.[DisplayOrder],
    n.[IsActive],
    n.[IsPublic],
    CONVERT(NVARCHAR(36), n.[ParentNodeId]) AS [ParentNodeIdRaw],
    CONVERT(NVARCHAR(36), parent.[PublicId]) AS [ParentPublicId],
    t.[TabName],
    t.[Slug] AS [TabSlug],
    CONVERT(NVARCHAR(36), n.[CatalogueTabId]) AS [CatalogueTabIdRaw]
FROM [product].[CatalogueNodes] n
INNER JOIN [product].[CatalogueTabs] t ON t.[CatalogueTabId] = n.[CatalogueTabId]
LEFT JOIN [product].[CatalogueNodes] parent ON parent.[CatalogueNodeId] = n.[ParentNodeId]
WHERE n.[DeletedAtUtc] IS NULL
  AND t.[DeletedAtUtc] IS NULL
ORDER BY t.[DisplayOrder], t.[TabName], n.[DisplayOrder], n.[NodeName]
SQL,
        );

        $byInternalId = [];
        foreach ($rows as $row) {
            $byInternalId[(string) ($row['PublicId'] ?? '')] = $row;
        }

        $pathFor = static function (array $row) use (&$pathFor, $rows): string {
            static $memo = [];
            $publicId = (string) ($row['PublicId'] ?? '');
            if ($publicId === '') {
                return '';
            }
            if (isset($memo[$publicId])) {
                return $memo[$publicId];
            }
            $parentPublicId = (string) ($row['ParentPublicId'] ?? '');
            $tabName = (string) ($row['TabName'] ?? '');
            if ($parentPublicId === '') {
                $memo[$publicId] = $tabName . ' / ' . (string) ($row['NodeName'] ?? '');

                return $memo[$publicId];
            }
            foreach ($rows as $candidate) {
                if ((string) ($candidate['PublicId'] ?? '') === $parentPublicId) {
                    $memo[$publicId] = $pathFor($candidate) . ' / ' . (string) ($row['NodeName'] ?? '');

                    return $memo[$publicId];
                }
            }
            $memo[$publicId] = $tabName . ' / ' . (string) ($row['NodeName'] ?? '');

            return $memo[$publicId];
        };

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'nodeCode' => AzureSqlValueMapper::stringValue($row['NodeCode'] ?? ''),
                'nodeName' => AzureSqlValueMapper::stringValue($row['NodeName'] ?? ''),
                'slug' => AzureSqlValueMapper::stringValue($row['Slug'] ?? ''),
                'displayOrder' => AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                'isActive' => AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
                'isPublic' => AzureSqlValueMapper::boolValue($row['IsPublic'] ?? true),
                'parentPublicId' => AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null),
                'tabName' => AzureSqlValueMapper::stringValue($row['TabName'] ?? ''),
                'tabSlug' => AzureSqlValueMapper::stringValue($row['TabSlug'] ?? ''),
                'cataloguePath' => $pathFor($row),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadDirectProducts(): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), n.[PublicId]) AS [NodePublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    p.[ProductName],
    p.[InternalCode] AS [ProductCode],
    cnp.[DisplayOrder],
    cnp.[IsActive],
    cnp.[IsFeatured]
FROM [product].[CatalogueNodeProducts] cnp
INNER JOIN [product].[CatalogueNodes] n ON n.[CatalogueNodeId] = cnp.[CatalogueNodeId]
INNER JOIN [product].[Products] p ON p.[ProductId] = cnp.[ProductId]
WHERE n.[DeletedAtUtc] IS NULL
  AND p.[DeletedAtUtc] IS NULL
ORDER BY n.[PublicId], cnp.[DisplayOrder], p.[ProductName]
SQL,
        );

        return array_map(static fn (array $row): array => [
            'nodePublicId' => AzureSqlValueMapper::stringValue($row['NodePublicId'] ?? ''),
            'productPublicId' => AzureSqlValueMapper::stringValue($row['ProductPublicId'] ?? ''),
            'productName' => AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
            'productCode' => strtoupper(trim(AzureSqlValueMapper::stringValue($row['ProductCode'] ?? ''))),
            'displayOrder' => AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            'isActive' => AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            'isFeatured' => AzureSqlValueMapper::boolValue($row['IsFeatured'] ?? false),
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadStyleAssignments(): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    p.[ProductName],
    UPPER(LTRIM(RTRIM(p.[InternalCode]))) AS [ProductCode],
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [StylePublicId],
    s.[StyleCode],
    CONVERT(NVARCHAR(36), parent.[PublicId]) AS [ParentStylePublicId],
    parent.[StyleCode] AS [ParentStyleCode]
FROM [product].[ProductStyleAssignments] psa
INNER JOIN [product].[Products] p ON p.[ProductId] = psa.[ProductId]
INNER JOIN [product].[ProductStyles] s ON s.[ProductStyleId] = psa.[ProductStyleId]
LEFT JOIN [product].[ProductStyles] parent ON parent.[ProductStyleId] = s.[ParentStyleId]
WHERE p.[DeletedAtUtc] IS NULL
  AND s.[DeletedAtUtc] IS NULL
SQL,
        );

        return array_map(static fn (array $row): array => [
            'productPublicId' => AzureSqlValueMapper::stringValue($row['ProductPublicId'] ?? ''),
            'productName' => AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
            'productCode' => AzureSqlValueMapper::stringValue($row['ProductCode'] ?? ''),
            'stylePublicId' => AzureSqlValueMapper::stringValue($row['StylePublicId'] ?? ''),
            'styleCode' => strtoupper(trim(AzureSqlValueMapper::stringValue($row['StyleCode'] ?? ''))),
            'parentStylePublicId' => AzureSqlValueMapper::nullableString($row['ParentStylePublicId'] ?? null),
            'parentStyleCode' => AzureSqlValueMapper::nullableString($row['ParentStyleCode'] ?? null) !== null
                ? strtoupper(trim((string) AzureSqlValueMapper::nullableString($row['ParentStyleCode'] ?? null)))
                : null,
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadProductStyles(): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [PublicId],
    s.[StyleCode],
    s.[DisplayName],
    s.[SortOrder],
    s.[IsActive],
    s.[DeletedAtUtc],
    CONVERT(NVARCHAR(36), parent.[PublicId]) AS [ParentPublicId],
    parent.[StyleCode] AS [ParentStyleCode]
FROM [product].[ProductStyles] s
LEFT JOIN [product].[ProductStyles] parent ON parent.[ProductStyleId] = s.[ParentStyleId]
WHERE s.[DeletedAtUtc] IS NULL
ORDER BY s.[StyleCode]
SQL,
        );

        return array_map(static fn (array $row): array => [
            'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            'styleCode' => strtoupper(trim(AzureSqlValueMapper::stringValue($row['StyleCode'] ?? ''))),
            'displayName' => AzureSqlValueMapper::stringValue($row['DisplayName'] ?? ''),
            'sortOrder' => AzureSqlValueMapper::intValue($row['SortOrder'] ?? 0),
            'isActive' => AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            'isDeleted' => false,
            'parentPublicId' => AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null),
            'parentStyleCode' => AzureSqlValueMapper::nullableString($row['ParentStyleCode'] ?? null) !== null
                ? strtoupper(trim((string) AzureSqlValueMapper::nullableString($row['ParentStyleCode'] ?? null)))
                : null,
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadProducts(): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId],
    p.[ProductName],
    UPPER(LTRIM(RTRIM(p.[InternalCode]))) AS [InternalCode],
    p.[IsActive],
    p.[DeletedAtUtc],
    p.[PublishAtUtc],
    p.[UnpublishAtUtc],
    ps.[StatusCode] AS [PublicationStatus]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
WHERE p.[DeletedAtUtc] IS NULL
SQL,
        );

        return array_map(static fn (array $row): array => [
            'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            'productName' => AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
            'internalCode' => AzureSqlValueMapper::stringValue($row['InternalCode'] ?? ''),
            'isActive' => AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            'deletedAtUtc' => AzureSqlValueMapper::nullableString($row['DeletedAtUtc'] ?? null),
            'publishAtUtc' => AzureSqlValueMapper::nullableString($row['PublishAtUtc'] ?? null),
            'unpublishAtUtc' => AzureSqlValueMapper::nullableString($row['UnpublishAtUtc'] ?? null),
            'publicationStatus' => AzureSqlValueMapper::stringValue($row['PublicationStatus'] ?? ''),
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadProductRegions(): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    r.[RegionCode]
FROM [product].[ProductRegions] prv
INNER JOIN [product].[Products] p ON p.[ProductId] = prv.[ProductId]
INNER JOIN [product].[Regions] r ON r.[RegionId] = prv.[RegionId]
WHERE prv.[DeletedAtUtc] IS NULL
  AND prv.[IsVisible] = 1
  AND p.[DeletedAtUtc] IS NULL
  AND (prv.[AvailableFromUtc] IS NULL OR prv.[AvailableFromUtc] <= SYSUTCDATETIME())
  AND (prv.[AvailableToUtc] IS NULL OR prv.[AvailableToUtc] > SYSUTCDATETIME())
SQL,
        );

        return array_map(static fn (array $row): array => [
            'productPublicId' => AzureSqlValueMapper::stringValue($row['ProductPublicId'] ?? ''),
            'regionCode' => strtoupper(trim(AzureSqlValueMapper::stringValue($row['RegionCode'] ?? ''))),
        ], $rows);
    }

    /**
     * @return list<string>
     */
    private function loadRegions(): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
SELECT [RegionCode]
FROM [product].[Regions]
WHERE [DeletedAtUtc] IS NULL
  AND [IsActive] = 1
ORDER BY [DisplayOrder], [RegionCode]
SQL,
        );

        return array_values(array_filter(array_map(
            static fn (array $row): string => strtoupper(trim(AzureSqlValueMapper::stringValue($row['RegionCode'] ?? ''))),
            $rows,
        ), static fn (string $code): bool => $code !== ''));
    }
}
