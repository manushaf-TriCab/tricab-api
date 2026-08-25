<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Contract\StyleSharedDataEvidenceSource;

/**
 * Set-based Azure SQL reads for Style shared-data generalisation dry-run.
 */
final class AzureSqlStyleSharedDataEvidenceSource implements StyleSharedDataEvidenceSource
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listPersistedFamilies(): array
    {
        $sql = <<<'SQL'
SELECT
    parent.[StyleCode] AS [ParentStyleCode],
    CONVERT(NVARCHAR(36), parent.[PublicId]) AS [ParentStylePublicId],
    child.[StyleCode] AS [ChildStyleCode],
    CONVERT(NVARCHAR(36), child.[PublicId]) AS [ChildStylePublicId]
FROM [product].[ProductStyles] child
INNER JOIN [product].[ProductStyles] parent
    ON parent.[ProductStyleId] = child.[ParentStyleId]
WHERE child.[DeletedAtUtc] IS NULL
  AND parent.[DeletedAtUtc] IS NULL
ORDER BY parent.[StyleCode], child.[SortOrder], child.[StyleCode]
SQL;

        $rows = $this->connection->fetchAll($sql);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'parentStyleCode' => AzureSqlValueMapper::stringValue($row['ParentStyleCode'] ?? ''),
                'parentStylePublicId' => AzureSqlValueMapper::stringValue($row['ParentStylePublicId'] ?? ''),
                'childStyleCode' => AzureSqlValueMapper::stringValue($row['ChildStyleCode'] ?? ''),
                'childStylePublicId' => AzureSqlValueMapper::stringValue($row['ChildStylePublicId'] ?? ''),
            ];
        }

        return $out;
    }

    public function listStyleAssignments(): array
    {
        $sql = <<<'SQL'
SELECT
    ps.[StyleCode] AS [StyleCode],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    p.[InternalCode] AS [ProductCode],
    p.[ProductName] AS [ProductName],
    pst.[StatusCode] AS [StatusCode],
    p.[IsActive] AS [IsActive]
FROM [product].[ProductStyleAssignments] psa
INNER JOIN [product].[ProductStyles] ps ON ps.[ProductStyleId] = psa.[ProductStyleId]
INNER JOIN [product].[Products] p ON p.[ProductId] = psa.[ProductId]
LEFT JOIN [product].[ProductStatuses] pst ON pst.[ProductStatusId] = p.[ProductStatusId]
WHERE ps.[DeletedAtUtc] IS NULL
  AND p.[DeletedAtUtc] IS NULL
ORDER BY ps.[StyleCode], p.[InternalCode]
SQL;

        $rows = $this->connection->fetchAll($sql);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'styleCode' => AzureSqlValueMapper::stringValue($row['StyleCode'] ?? ''),
                'productPublicId' => AzureSqlValueMapper::stringValue($row['ProductPublicId'] ?? ''),
                'productCode' => AzureSqlValueMapper::stringValue($row['ProductCode'] ?? ''),
                'productName' => AzureSqlValueMapper::stringValue($row['ProductName'] ?? ''),
                'statusCode' => AzureSqlValueMapper::stringValue($row['StatusCode'] ?? ''),
                'isActive' => AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            ];
        }

        return $out;
    }

    public function loadSpecificationsForProducts(array $productCodes): array
    {
        if ($productCodes === []) {
            return [];
        }
        $placeholders = $this->placeholders($productCodes);
        $sql = <<<SQL
SELECT
    p.[InternalCode] AS [ProductCode],
    d.[DefinitionCode] AS [DefinitionCode],
    d.[DefinitionName] AS [DefinitionName],
    COALESCE(
        NULLIF(LTRIM(RTRIM(psv.[ValueText])), ''),
        so.[OptionLabel],
        CAST(psv.[ValueInteger] AS NVARCHAR(100)),
        CAST(psv.[ValueDecimal] AS NVARCHAR(100)),
        CASE WHEN psv.[ValueBoolean] IS NOT NULL THEN CASE WHEN psv.[ValueBoolean] = 1 THEN 'Yes' ELSE 'No' END END
    ) AS [DisplayValue]
FROM [product].[Products] p
INNER JOIN [product].[ProductSpecificationValues] psv ON psv.[ProductId] = p.[ProductId]
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = psv.[SpecificationDefinitionId]
LEFT JOIN [product].[SpecificationOptions] so ON so.[SpecificationOptionId] = psv.[SpecificationOptionId]
WHERE p.[InternalCode] IN ({$placeholders})
  AND p.[DeletedAtUtc] IS NULL
  AND psv.[DeletedAtUtc] IS NULL
  AND d.[DeletedAtUtc] IS NULL
ORDER BY p.[InternalCode], d.[DisplayOrder], d.[DefinitionCode]
SQL;

        return $this->mapSpecRows($this->connection->fetchAll($sql, $this->bindCodes($productCodes)));
    }

    public function loadMediaForProducts(array $productCodes): array
    {
        if ($productCodes === []) {
            return [];
        }
        $placeholders = $this->placeholders($productCodes);
        $sql = <<<SQL
SELECT
    p.[InternalCode] AS [ProductCode],
    i.[StoragePath] AS [StoragePath],
    i.[ImageRoleCode] AS [RoleCode],
    mt.[MediaTypeCode] AS [MediaTypeCode]
FROM [product].[Products] p
INNER JOIN [product].[ProductImages] i
    ON i.[ProductId] = p.[ProductId] AND i.[DeletedAtUtc] IS NULL
INNER JOIN [product].[MediaTypes] mt ON mt.[MediaTypeId] = i.[MediaTypeId]
WHERE p.[InternalCode] IN ({$placeholders})
  AND p.[DeletedAtUtc] IS NULL
  AND i.[StoragePath] IS NOT NULL
  AND LTRIM(RTRIM(i.[StoragePath])) <> ''
ORDER BY p.[InternalCode], i.[DisplayOrder]
SQL;

        $rows = $this->connection->fetchAll($sql, $this->bindCodes($productCodes));
        $out = [];
        foreach ($rows as $row) {
            $path = AzureSqlValueMapper::nullableString($row['StoragePath'] ?? null);
            if ($path === null || $path === '') {
                continue;
            }
            $out[] = [
                'productCode' => AzureSqlValueMapper::stringValue($row['ProductCode'] ?? ''),
                'storagePath' => $path,
                'roleCode' => AzureSqlValueMapper::nullableString($row['RoleCode'] ?? null),
                'mediaTypeCode' => AzureSqlValueMapper::stringValue($row['MediaTypeCode'] ?? 'IMAGE'),
            ];
        }

        return $out;
    }

    public function loadRegionsForProducts(array $productCodes): array
    {
        if ($productCodes === []) {
            return [];
        }
        $placeholders = $this->placeholders($productCodes);
        $sql = <<<SQL
SELECT
    p.[InternalCode] AS [ProductCode],
    r.[RegionCode] AS [RegionCode]
FROM [product].[Products] p
INNER JOIN [product].[ProductRegions] pr ON pr.[ProductId] = p.[ProductId]
INNER JOIN [product].[Regions] r ON r.[RegionId] = pr.[RegionId]
WHERE p.[InternalCode] IN ({$placeholders})
  AND p.[DeletedAtUtc] IS NULL
  AND pr.[DeletedAtUtc] IS NULL
  AND pr.[IsVisible] = 1
ORDER BY p.[InternalCode], r.[RegionCode]
SQL;

        $rows = $this->connection->fetchAll($sql, $this->bindCodes($productCodes));
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'productCode' => AzureSqlValueMapper::stringValue($row['ProductCode'] ?? ''),
                'regionCode' => AzureSqlValueMapper::stringValue($row['RegionCode'] ?? ''),
            ];
        }

        return $out;
    }

    public function loadResourceCountsForProducts(array $productCodes): array
    {
        if ($productCodes === []) {
            return [];
        }
        $placeholders = $this->placeholders($productCodes);
        $queries = [
            'documents' => <<<SQL
SELECT p.[InternalCode] AS [ProductCode], COUNT(*) AS [Count]
FROM [product].[Products] p
INNER JOIN [product].[ProductDocuments] d ON d.[ProductId] = p.[ProductId]
WHERE p.[InternalCode] IN ({$placeholders}) AND p.[DeletedAtUtc] IS NULL AND d.[DeletedAtUtc] IS NULL
GROUP BY p.[InternalCode]
SQL,
            'spin360' => <<<SQL
SELECT p.[InternalCode] AS [ProductCode], COUNT(*) AS [Count]
FROM [product].[Products] p
INNER JOIN [product].[Product360Sets] s ON s.[ProductId] = p.[ProductId]
WHERE p.[InternalCode] IN ({$placeholders}) AND p.[DeletedAtUtc] IS NULL AND s.[DeletedAtUtc] IS NULL
GROUP BY p.[InternalCode]
SQL,
            'models3d' => <<<SQL
SELECT p.[InternalCode] AS [ProductCode], COUNT(*) AS [Count]
FROM [product].[Products] p
INNER JOIN [product].[Product3DModels] m ON m.[ProductId] = p.[ProductId]
WHERE p.[InternalCode] IN ({$placeholders}) AND p.[DeletedAtUtc] IS NULL AND m.[DeletedAtUtc] IS NULL
GROUP BY p.[InternalCode]
SQL,
            'ar' => <<<SQL
SELECT p.[InternalCode] AS [ProductCode], COUNT(*) AS [Count]
FROM [product].[Products] p
INNER JOIN [product].[ProductARModels] a ON a.[ProductId] = p.[ProductId]
WHERE p.[InternalCode] IN ({$placeholders}) AND p.[DeletedAtUtc] IS NULL AND a.[DeletedAtUtc] IS NULL
GROUP BY p.[InternalCode]
SQL,
        ];

        $bind = $this->bindCodes($productCodes);
        $out = [];
        foreach ($queries as $type => $sql) {
            try {
                $rows = $this->connection->fetchAll($sql, $bind);
            } catch (\Throwable) {
                continue;
            }
            foreach ($rows as $row) {
                $out[] = [
                    'productCode' => AzureSqlValueMapper::stringValue($row['ProductCode'] ?? ''),
                    'resourceType' => $type,
                    'count' => AzureSqlValueMapper::intValue($row['Count'] ?? 0),
                ];
            }
        }

        return $out;
    }

    public function loadEnvironmentSafety(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT @@SERVERNAME AS ServerName, DB_NAME() AS DbName, SUSER_SNAME() AS SqlUser',
        );
        $row = $rows[0] ?? [];

        return [
            'appEnv' => getenv('APP_ENV') ?: null,
            'userRepository' => getenv('USER_REPOSITORY') ?: null,
            'dbHost' => getenv('DB_HOST') ?: null,
            'dbDatabase' => getenv('DB_DATABASE') ?: null,
            'sqlServerName' => AzureSqlValueMapper::nullableString($row['ServerName'] ?? null),
            'sqlDbName' => AzureSqlValueMapper::nullableString($row['DbName'] ?? null),
            'sqlUser' => AzureSqlValueMapper::nullableString($row['SqlUser'] ?? null),
            'isProduction' => (getenv('APP_ENV') ?: '') === 'production' ? 'YES' : 'NO',
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{productCode: string, definitionCode: string, definitionName: string, displayValue: string}>
     */
    private function mapSpecRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $display = AzureSqlValueMapper::nullableString($row['DisplayValue'] ?? null);
            if ($display === null || trim($display) === '') {
                continue;
            }
            $out[] = [
                'productCode' => AzureSqlValueMapper::stringValue($row['ProductCode'] ?? ''),
                'definitionCode' => AzureSqlValueMapper::stringValue($row['DefinitionCode'] ?? ''),
                'definitionName' => AzureSqlValueMapper::stringValue($row['DefinitionName'] ?? ''),
                'displayValue' => $display,
            ];
        }

        return $out;
    }

    /** @param list<string> $codes */
    private function placeholders(array $codes): string
    {
        return implode(', ', array_map(static fn (int $i): string => ':c' . $i, array_keys($codes)));
    }

    /** @param list<string> $codes */
    private function bindCodes(array $codes): array
    {
        $bind = [];
        foreach ($codes as $i => $code) {
            $bind['c' . $i] = $code;
        }

        return $bind;
    }
}
