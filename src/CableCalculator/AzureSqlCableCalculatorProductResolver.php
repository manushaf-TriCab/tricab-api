<?php

declare(strict_types=1);

namespace TriCab\Api\CableCalculator;

use PDO;
use TriCab\Core\CableCalculator\Application\ProductResolution\CalculatedProductReference;
use TriCab\Core\CableCalculator\Contract\CableCalculatorProductResolver;

/**
 * Exact product-code lookup in new Product Module. Never substitutes alternate codes.
 * Failures become NOT_MIGRATED / CATALOGUE_UNAVAILABLE / RESOLVER_ERROR — never change calculation.
 *
 * PDO is obtained lazily so DI construction of the calculate action never opens Azure SQL.
 */
final class AzureSqlCableCalculatorProductResolver implements CableCalculatorProductResolver
{
    /**
     * Exact code lookup against both code sources, never fuzzy.
     *
     * [product].[ProductCodes] holds curated codes and is authoritative when present, but
     * catalog-load leaves it empty by design, so [product].[ProductVariants].[VariantCode]
     * is what actually carries the loaded variant codes. Both branches are exact equality;
     * curated codes rank first so adding them later cannot be overridden by a variant.
     *
     * Slug comes from [product].[ProductSlugs] — [Products] has no [Slug] column, and no
     * [Name] column either (it is [ProductName]). Selecting those two non-existent columns
     * was why every live call raised "Invalid column name" and was buried as RESOLVER_ERROR.
     *
     * The resolver takes no region, so the slug preference is primary first, then the
     * region-neutral slug, then lowest id — deterministic without guessing a region.
     *
     * The code is bound twice under distinct names because sqlsrv rejects a named parameter
     * that appears more than once in a statement (SQLSTATE 07002).
     */
    public const RESOLVE_SQL = <<<'SQL'
WITH [CodeMatch] AS (
    SELECT
        COALESCE(pc.[ProductId], pcv.[ProductId]) AS [ProductId],
        pc.[ProductVariantId] AS [ProductVariantId],
        0 AS [MatchRank]
    FROM [product].[ProductCodes] pc
    LEFT JOIN [product].[ProductVariants] pcv
        ON pcv.[ProductVariantId] = pc.[ProductVariantId]
        AND pcv.[DeletedAtUtc] IS NULL
    WHERE pc.[CodeValue] = :codeCurated
      AND pc.[DeletedAtUtc] IS NULL

    UNION ALL

    SELECT
        v.[ProductId] AS [ProductId],
        v.[ProductVariantId] AS [ProductVariantId],
        1 AS [MatchRank]
    FROM [product].[ProductVariants] v
    WHERE v.[VariantCode] = :codeVariant
      AND v.[DeletedAtUtc] IS NULL
)
SELECT
    CONVERT(nvarchar(36), p.[PublicId]) AS [ProductPublicId],
    (
        SELECT TOP 1 slug.[Slug]
        FROM [product].[ProductSlugs] slug
        WHERE slug.[ProductId] = p.[ProductId]
          AND slug.[DeletedAtUtc] IS NULL
          AND slug.[IsActive] = 1
        ORDER BY
            CASE WHEN slug.[IsPrimary] = 1 THEN 0 ELSE 1 END,
            CASE WHEN slug.[RegionId] IS NULL THEN 0 ELSE 1 END,
            slug.[ProductSlugId]
    ) AS [Slug],
    p.[ProductName] AS [DisplayName],
    ps.[StatusCode] AS [StatusCode],
    CONVERT(nvarchar(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantCode] AS [VariantCode]
FROM [CodeMatch] m
INNER JOIN [product].[Products] p
    ON p.[ProductId] = m.[ProductId]
    AND p.[DeletedAtUtc] IS NULL
INNER JOIN [product].[ProductStatuses] ps
    ON ps.[ProductStatusId] = p.[ProductStatusId]
LEFT JOIN [product].[ProductVariants] v
    ON v.[ProductVariantId] = m.[ProductVariantId]
    AND v.[DeletedAtUtc] IS NULL
ORDER BY
    m.[MatchRank],
    CASE WHEN v.[ProductVariantId] IS NULL THEN 1 ELSE 0 END,
    v.[ProductVariantId]
SQL;

    /** @var (callable(): ?PDO)|null */
    private $pdoProvider;

    private ?PDO $pdo;

    /**
     * @param (callable(): ?PDO)|null $pdoProvider Lazy provider — preferred.
     * @param PDO|null $pdo Eager PDO for tests only.
     */
    public function __construct(
        ?callable $pdoProvider = null,
        ?PDO $pdo = null,
    ) {
        $this->pdoProvider = $pdoProvider;
        $this->pdo = $pdo;
    }

    public function resolve(
        string $productCode,
        string $role,
        string $temperatureBand,
        ?float $sizeMm2,
    ): CalculatedProductReference {
        $code = trim($productCode);
        if ($code === '') {
            return new CalculatedProductReference(
                productCode: $code,
                role: $role,
                temperatureBand: $temperatureBand,
                sizeMm2: $sizeMm2,
                resolutionStatus: 'NOT_MIGRATED',
            );
        }

        $pdo = $this->obtainPdo();
        if ($pdo === null) {
            return new CalculatedProductReference(
                productCode: $code,
                role: $role,
                temperatureBand: $temperatureBand,
                sizeMm2: $sizeMm2,
                resolutionStatus: 'CATALOGUE_UNAVAILABLE',
            );
        }

        try {
            $stmt = $pdo->prepare(self::RESOLVE_SQL);
            $stmt->execute(['codeCurated' => $code, 'codeVariant' => $code]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($rows === []) {
                return new CalculatedProductReference(
                    productCode: $code,
                    role: $role,
                    temperatureBand: $temperatureBand,
                    sizeMm2: $sizeMm2,
                    resolutionStatus: 'NOT_MIGRATED',
                );
            }

            $productIds = array_unique(array_map(
                static fn (array $row): string => (string) ($row['ProductPublicId'] ?? ''),
                $rows,
            ));
            if (count(array_filter($productIds)) > 1) {
                return new CalculatedProductReference(
                    productCode: $code,
                    role: $role,
                    temperatureBand: $temperatureBand,
                    sizeMm2: $sizeMm2,
                    resolutionStatus: 'AMBIGUOUS',
                );
            }

            $row = $rows[0];
            $status = strtoupper((string) ($row['StatusCode'] ?? ''));
            if (in_array($status, ['INACTIVE', 'ARCHIVED', 'DELETED'], true)) {
                return new CalculatedProductReference(
                    productCode: $code,
                    role: $role,
                    temperatureBand: $temperatureBand,
                    sizeMm2: $sizeMm2,
                    resolutionStatus: 'INACTIVE',
                    resolvedProductPublicId: (string) ($row['ProductPublicId'] ?? ''),
                    slug: isset($row['Slug']) ? (string) $row['Slug'] : null,
                    displayName: isset($row['DisplayName']) ? (string) $row['DisplayName'] : null,
                );
            }

            $slug = isset($row['Slug']) ? (string) $row['Slug'] : null;

            return new CalculatedProductReference(
                productCode: $code,
                role: $role,
                temperatureBand: $temperatureBand,
                sizeMm2: $sizeMm2,
                resolutionStatus: 'RESOLVED',
                resolvedProductPublicId: (string) ($row['ProductPublicId'] ?? ''),
                resolvedVariantPublicId: isset($row['VariantPublicId']) && $row['VariantPublicId'] !== null
                    ? (string) $row['VariantPublicId']
                    : null,
                slug: $slug,
                displayName: isset($row['DisplayName']) ? (string) $row['DisplayName'] : null,
                imageUrl: null,
                customerUrl: $slug !== null && $slug !== '' ? '/products/' . $slug : null,
            );
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());
            $catalogueUnavailable = str_contains($message, 'invalid object name')
                || str_contains($message, 'cannot open database')
                || str_contains($message, 'login failed')
                || str_contains($message, 'connection')
                || str_contains($message, 'timeout')
                || str_contains($message, 'hy000')
                || str_contains($message, '08001')
                || str_contains($message, '08s01')
                || str_contains($message, 'not currently available');

            return new CalculatedProductReference(
                productCode: $code,
                role: $role,
                temperatureBand: $temperatureBand,
                sizeMm2: $sizeMm2,
                resolutionStatus: $catalogueUnavailable ? 'CATALOGUE_UNAVAILABLE' : 'RESOLVER_ERROR',
            );
        }
    }

    private function obtainPdo(): ?PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if ($this->pdoProvider === null) {
            return null;
        }

        try {
            $pdo = ($this->pdoProvider)();
            if ($pdo instanceof PDO) {
                $this->pdo = $pdo;

                return $pdo;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
