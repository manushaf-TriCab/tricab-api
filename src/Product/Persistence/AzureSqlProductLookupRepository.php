<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Contract\ProductLookupRepository;

/**
 * Lightweight admin lookup DTOs. Active-only by default.
 */
final class AzureSqlProductLookupRepository implements ProductLookupRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function loadAdminLookups(): array
    {
        return [
            'statuses' => $this->listStatuses(),
            'lifecycleStatuses' => $this->listLifecycleStatuses(),
            'families' => $this->listLookup(
                <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [FamilyCode] AS [Code], [FamilyName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], [DisplayOrder], [IsActive]
FROM [product].[ProductFamilies]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [DisplayOrder], [FamilyName]
SQL
            ),
            'categories' => $this->listLookup(
                <<<'SQL'
SELECT CONVERT(NVARCHAR(36), c.[PublicId]) AS [PublicId], c.[CategoryCode] AS [Code], c.[CategoryName] AS [Name],
       CONVERT(NVARCHAR(36), f.[PublicId]) AS [ParentPublicId], c.[DisplayOrder], c.[IsActive]
FROM [product].[ProductCategories] c
LEFT JOIN [product].[ProductFamilies] f ON f.[ProductFamilyId] = c.[ProductFamilyId]
WHERE c.[DeletedAtUtc] IS NULL AND c.[IsActive] = 1
ORDER BY c.[DisplayOrder], c.[CategoryName]
SQL
            ),
            'series' => $this->listLookup(
                <<<'SQL'
SELECT CONVERT(NVARCHAR(36), s.[PublicId]) AS [PublicId], s.[SeriesCode] AS [Code], s.[SeriesName] AS [Name],
       CONVERT(NVARCHAR(36), c.[PublicId]) AS [ParentPublicId], s.[DisplayOrder], s.[IsActive]
FROM [product].[ProductSeries] s
LEFT JOIN [product].[ProductCategories] c ON c.[ProductCategoryId] = s.[ProductCategoryId]
WHERE s.[DeletedAtUtc] IS NULL AND s.[IsActive] = 1
ORDER BY s.[DisplayOrder], s.[SeriesName]
SQL
            ),
            'regions' => $this->listLookup(
                <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [RegionCode] AS [Code], [RegionName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], [DisplayOrder], [IsActive]
FROM [product].[Regions]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [DisplayOrder], [RegionName]
SQL
            ),
            'markets' => $this->listLookup(
                <<<'SQL'
SELECT CONVERT(NVARCHAR(36), m.[PublicId]) AS [PublicId], m.[MarketCode] AS [Code], m.[MarketName] AS [Name],
       CONVERT(NVARCHAR(36), r.[PublicId]) AS [ParentPublicId], m.[DisplayOrder], m.[IsActive]
FROM [product].[Markets] m
LEFT JOIN [product].[Regions] r ON r.[RegionId] = m.[RegionId]
WHERE m.[DeletedAtUtc] IS NULL AND m.[IsActive] = 1
ORDER BY m.[DisplayOrder], m.[MarketName]
SQL
            ),
            'industries' => $this->listLookup(
                <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [IndustryCode] AS [Code], [IndustryName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], [DisplayOrder], [IsActive]
FROM [product].[Industries]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [DisplayOrder], [IndustryName]
SQL
            ),
            'applications' => $this->listLookup(
                <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [ApplicationCode] AS [Code], [ApplicationName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], [DisplayOrder], [IsActive]
FROM [product].[Applications]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [DisplayOrder], [ApplicationName]
SQL
            ),
            'mediaTypes' => $this->listMediaTypes(),
            'documentTypes' => $this->listDocumentTypes(),
            'currencies' => $this->listCurrencies(),
            'specificationDefinitions' => $this->listLookup(
                <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [DefinitionCode] AS [Code], [DefinitionName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], [DisplayOrder], [IsActive]
FROM [product].[SpecificationDefinitions]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [DisplayOrder], [DefinitionName]
SQL
            ),
            'codeRuleSets' => $this->listLookup(
                <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [RuleSetCode] AS [Code], [RuleSetName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], CAST(0 AS INT) AS [DisplayOrder], [IsActive]
FROM [product].[ProductCodeRuleSets]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [RuleSetName]
SQL
            ),
        ];
    }

    public function listStatuses(): array
    {
        return $this->listLookup(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [StatusCode] AS [Code], [StatusName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], [DisplayOrder], [IsActive]
FROM [product].[ProductStatuses]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [DisplayOrder], [StatusName]
SQL
        );
    }

    public function listLifecycleStatuses(): array
    {
        return $this->listLookup(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [LifecycleStatusCode] AS [Code], [LifecycleStatusName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], [DisplayOrder], [IsActive]
FROM [product].[ProductLifecycleStatuses]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [DisplayOrder], [LifecycleStatusName]
SQL
        );
    }

    public function listMediaTypes(): array
    {
        return $this->listLookup(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [MediaTypeCode] AS [Code], [MediaTypeName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], [DisplayOrder], [IsActive]
FROM [product].[MediaTypes]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [DisplayOrder], [MediaTypeName]
SQL
        );
    }

    public function listDocumentTypes(): array
    {
        return $this->listLookup(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [DocumentTypeCode] AS [Code], [DocumentTypeName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], [DisplayOrder], [IsActive]
FROM [product].[DocumentTypes]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [DisplayOrder], [DocumentTypeName]
SQL
        );
    }

    public function listCurrencies(): array
    {
        return $this->listLookup(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [CurrencyCode] AS [Code], [CurrencyName] AS [Name],
       CAST(NULL AS NVARCHAR(36)) AS [ParentPublicId], [DisplayOrder], [IsActive]
FROM [product].[Currencies]
WHERE [DeletedAtUtc] IS NULL AND [IsActive] = 1
ORDER BY [DisplayOrder], [CurrencyCode]
SQL
        );
    }

    /**
     * @return list<ProductLookupItem>
     */
    private function listLookup(string $sql): array
    {
        $rows = $this->connection->fetchAll($sql);

        return array_map(static fn (array $row): ProductLookupItem => new ProductLookupItem(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            parentPublicId: AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
        ), $rows);
    }
}
