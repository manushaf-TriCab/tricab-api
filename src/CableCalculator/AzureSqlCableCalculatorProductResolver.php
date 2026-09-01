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
            $stmt = $pdo->prepare(
                <<<'SQL'
SELECT
    CONVERT(nvarchar(36), p.[PublicId]) AS [ProductPublicId],
    p.[Slug] AS [Slug],
    p.[Name] AS [DisplayName],
    ps.[StatusCode] AS [StatusCode],
    CONVERT(nvarchar(36), v.[PublicId]) AS [VariantPublicId],
    v.[VariantCode] AS [VariantCode]
FROM [product].[ProductCodes] pc
INNER JOIN [product].[Products] p ON p.[ProductId] = pc.[ProductId] AND p.[DeletedAtUtc] IS NULL
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
LEFT JOIN [product].[ProductVariants] v
    ON v.[ProductId] = p.[ProductId]
    AND v.[DeletedAtUtc] IS NULL
    AND v.[VariantCode] = pc.[CodeValue]
WHERE pc.[CodeValue] = :code
  AND pc.[DeletedAtUtc] IS NULL
ORDER BY CASE WHEN v.[VariantCode] = pc.[CodeValue] THEN 0 ELSE 1 END, v.[ProductVariantId]
SQL
            );
            $stmt->execute(['code' => $code]);
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
