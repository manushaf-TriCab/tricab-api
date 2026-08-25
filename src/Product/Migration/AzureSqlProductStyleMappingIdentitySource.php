<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Migration;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\ProductStyleMappingSnapshot;
use TriCab\Core\Product\Application\DTO\UnresolvedLegacyStyleProduct;
use TriCab\Core\Product\Contract\ProductStyleMappingIdentitySource;
use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;
use TriCab\Core\Product\Migration\Contract\LegacyWordPressRepository;
use TriCab\Core\Product\Migration\Live\LiveLegacySourceConfig;
use TriCab\Core\Product\Migration\Support\MigrationPayload;

/**
 * Read-only Azure + canonical + WordPress identity join. Never writes.
 */
final class AzureSqlProductStyleMappingIdentitySource implements ProductStyleMappingIdentitySource
{
    private int $wordpressReads = 0;
    private int $tcReads = 0;
    private int $azureReads = 0;

    /** @var list<array<string, mixed>>|null */
    private ?array $productCache = null;

    /** @var list<array<string, mixed>>|null */
    private ?array $canonicalCache = null;

    /** @var array<string, array{styleCode: ?string, title: ?string, kind: ?string}>|null */
    private ?array $wordpressCache = null;

    public function __construct(
        private readonly SqlConnection $db,
        private readonly LiveLegacySourceConfig $config,
        private readonly LegacyWordPressRepository $wordpress,
    ) {
    }

    public function listProductSnapshots(): array
    {
        $products = $this->loadProducts();
        $canonicalByTarget = [];
        $canonicalBySourceId = [];
        foreach ($this->loadCanonical() as $row) {
            $target = $row['targetPublicId'];
            if (is_string($target) && $target !== '') {
                $canonicalByTarget[strtolower($target)] = $row;
            }
            $sourceId = $row['sourceId'];
            if (is_string($sourceId) && $sourceId !== '') {
                $canonicalBySourceId[$sourceId] = $row;
            }
        }
        $wpByPostId = $this->loadWordpressIdentity();
        $mappingByTarget = $this->loadLegacyMappings();

        $out = [];
        foreach ($products as $product) {
            $publicId = strtolower((string) $product['publicId']);
            $legacyId = $product['legacyProductId'];
            $canonical = $canonicalByTarget[$publicId]
                ?? ((is_string($legacyId) && $legacyId !== '') ? ($canonicalBySourceId[$legacyId] ?? null) : null);
            $mapping = $mappingByTarget[$publicId] ?? null;
            $wp = (is_string($legacyId) && $legacyId !== '') ? ($wpByPostId[$legacyId] ?? $wpByPostId[(string) (int) $legacyId] ?? null) : null;
            if ($wp === null && is_array($mapping) && is_string($mapping['sourceId'] ?? null)) {
                $wp = $wpByPostId[$mapping['sourceId']] ?? $wpByPostId[(string) (int) $mapping['sourceId']] ?? null;
            }

            $variantCodes = [];
            $canonicalStyle = null;
            $sourceKey = null;
            $matchMethod = null;
            $title = is_array($wp) ? ($wp['title'] ?? null) : null;
            $kind = is_array($wp) ? ($wp['kind'] ?? null) : null;
            if (is_array($canonical)) {
                $canonicalStyle = $canonical['legacyStyleCode'];
                $sourceKey = $canonical['sourceKey'];
                $matchMethod = $canonical['matchMethod'];
                $variantCodes = $canonical['variantStyles'];
                if ($title === null) {
                    $title = $canonical['title'];
                }
                if ($kind === null) {
                    $kind = $canonical['kind'];
                }
            }
            if ($sourceKey === null && is_array($mapping)) {
                $sourceKey = $mapping['sourceKey'];
                $matchMethod = $matchMethod ?? 'legacy-product-mapping';
            }
            $sources = ['azure-sql'];
            if (is_array($canonical)) {
                $sources[] = 'canonical';
            }
            if (is_array($mapping)) {
                $sources[] = 'legacy-product-mapping';
            }
            if (is_array($wp)) {
                $sources[] = 'wordpress';
            }

            $out[] = new ProductStyleMappingSnapshot(
                productPublicId: (string) $product['publicId'],
                productName: (string) $product['productName'],
                productCode: $product['productCode'],
                legacyProductId: $legacyId,
                statusCode: (string) $product['statusCode'],
                isActive: (bool) $product['isActive'],
                isArchived: (bool) $product['isArchived'],
                assignedStylePublicId: $product['assignedStylePublicId'],
                assignedStyleCode: $product['assignedStyleCode'],
                sourceKey: $sourceKey,
                canonicalRawStyleCode: $canonicalStyle,
                wordpressRawStyleCode: is_array($wp) ? $wp['styleCode'] : null,
                tcRawStyleCode: null,
                variantRawStyleCodes: $variantCodes,
                legacyTitle: $title,
                legacyKind: $kind,
                matchMethod: $matchMethod,
                sourcesAvailable: $sources,
                internalCode: $product['internalCode'],
            );
        }

        return $out;
    }

    public function listTargetProducts(): array
    {
        $out = [];
        foreach ($this->loadProducts() as $product) {
            $out[] = new ProductStyleMappingSnapshot(
                productPublicId: (string) $product['publicId'],
                productName: (string) $product['productName'],
                productCode: $product['productCode'],
                legacyProductId: $product['legacyProductId'],
                statusCode: (string) $product['statusCode'],
                isActive: (bool) $product['isActive'],
                isArchived: (bool) $product['isArchived'],
                assignedStylePublicId: $product['assignedStylePublicId'],
                assignedStyleCode: $product['assignedStyleCode'],
                sourceKey: null,
                canonicalRawStyleCode: null,
                wordpressRawStyleCode: null,
                tcRawStyleCode: null,
                variantRawStyleCodes: [],
                sourcesAvailable: ['azure-sql'],
                internalCode: $product['internalCode'],
            );
        }

        return $out;
    }

    public function unresolvedLegacyProducts(): array
    {
        $assignedLegacyIds = [];
        $assignedTargets = [];
        foreach ($this->loadProducts() as $product) {
            if (is_string($product['legacyProductId']) && $product['legacyProductId'] !== '') {
                $assignedLegacyIds[$product['legacyProductId']] = true;
                $assignedLegacyIds[(string) (int) $product['legacyProductId']] = true;
            }
            $assignedTargets[strtolower((string) $product['publicId'])] = true;
        }
        $out = [];
        $seen = [];
        foreach ($this->loadCanonical() as $row) {
            $target = is_string($row['targetPublicId']) ? strtolower($row['targetPublicId']) : '';
            if ($target !== '' && isset($assignedTargets[$target])) {
                continue;
            }
            $sourceId = $row['sourceId'];
            if (is_string($sourceId) && isset($assignedLegacyIds[$sourceId])) {
                continue;
            }
            $sourceKey = (string) $row['sourceKey'];
            $seen[$sourceKey] = true;
            if (is_string($sourceId) && $sourceId !== '') {
                $seen[$sourceId] = true;
                $seen['wordpress:wp_posts:' . $sourceId] = true;
            }
            $out[] = new UnresolvedLegacyStyleProduct(
                sourceKey: $sourceKey,
                rawStyleCode: $row['legacyStyleCode'],
                title: $row['title'],
                kind: $row['kind'],
                reason: 'Canonical product has no matching target Product.',
            );
        }
        foreach ($this->loadWordpressIdentity() as $postId => $wp) {
            $wpKey = 'wordpress:wp_posts:' . $postId;
            if (isset($assignedLegacyIds[(string) $postId]) || isset($seen[(string) $postId]) || isset($seen[$wpKey])) {
                continue;
            }
            $seen[$wpKey] = true;
            $out[] = new UnresolvedLegacyStyleProduct(
                sourceKey: $wpKey,
                rawStyleCode: $wp['styleCode'],
                title: $wp['title'],
                kind: $wp['kind'],
                reason: 'WordPress product has no matching target Product.',
            );
        }

        return $out;
    }

    public function wordpressReads(): int
    {
        return $this->wordpressReads;
    }

    public function tcReads(): int
    {
        return $this->tcReads;
    }

    public function filesystemReads(): int
    {
        return 0;
    }

    public function azureReads(): int
    {
        return $this->azureReads;
    }

    /**
     * @return list<array{
     *   publicId: string,
     *   productName: string,
     *   productCode: ?string,
     *   internalCode: ?string,
     *   legacyProductId: ?string,
     *   statusCode: string,
     *   isActive: bool,
     *   isArchived: bool,
     *   assignedStylePublicId: ?string,
     *   assignedStyleCode: ?string
     * }>
     */
    private function loadProducts(): array
    {
        if ($this->productCache !== null) {
            return $this->productCache;
        }
        $rows = $this->db->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [publicId],
    p.[ProductName] AS [productName],
    p.[InternalCode] AS [internalCode],
    (
        SELECT TOP 1 pc.[CodeValue]
        FROM [product].[ProductCodes] pc
        WHERE pc.[ProductId] = p.[ProductId]
          AND pc.[IsPrimary] = 1
          AND pc.[DeletedAtUtc] IS NULL
        ORDER BY pc.[ProductCodeId]
    ) AS [productCode],
    p.[LegacyProductId] AS [legacyProductId],
    ps.[StatusCode] AS [statusCode],
    CASE WHEN ps.[StatusCode] = N'archived' THEN 1 ELSE 0 END AS [isArchived],
    CASE WHEN p.[DeletedAtUtc] IS NULL THEN 1 ELSE 0 END AS [isActive],
    CONVERT(NVARCHAR(36), st.[PublicId]) AS [assignedStylePublicId],
    st.[StyleCode] AS [assignedStyleCode]
FROM [product].[Products] p
INNER JOIN [product].[ProductStatuses] ps ON ps.[ProductStatusId] = p.[ProductStatusId]
LEFT JOIN [product].[ProductStyleAssignments] a ON a.[ProductId] = p.[ProductId]
LEFT JOIN [product].[ProductStyles] st ON st.[ProductStyleId] = a.[ProductStyleId]
WHERE p.[DeletedAtUtc] IS NULL
ORDER BY p.[ProductName]
SQL
        );
        ++$this->azureReads;
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
                'productName' => AzureSqlValueMapper::stringValue($row['productName'] ?? ''),
                'internalCode' => AzureSqlValueMapper::nullableString($row['internalCode'] ?? null),
                'productCode' => AzureSqlValueMapper::nullableString($row['internalCode'] ?? $row['productCode'] ?? null),
                'legacyProductId' => AzureSqlValueMapper::nullableString($row['legacyProductId'] ?? null),
                'statusCode' => AzureSqlValueMapper::stringValue($row['statusCode'] ?? ''),
                'isActive' => ((int) ($row['isActive'] ?? 1)) === 1,
                'isArchived' => ((int) ($row['isArchived'] ?? 0)) === 1,
                'assignedStylePublicId' => AzureSqlValueMapper::nullableString($row['assignedStylePublicId'] ?? null),
                'assignedStyleCode' => AzureSqlValueMapper::nullableString($row['assignedStyleCode'] ?? null),
            ];
        }
        $this->productCache = $out;

        return $out;
    }

    /**
     * @return list<array{
     *   sourceKey: string,
     *   sourceId: ?string,
     *   targetPublicId: ?string,
     *   legacyStyleCode: ?string,
     *   title: ?string,
     *   kind: ?string,
     *   matchMethod: ?string,
     *   variantStyles: list<string>
     * }>
     */
    private function loadCanonical(): array
    {
        if ($this->canonicalCache !== null) {
            return $this->canonicalCache;
        }
        try {
            $rows = $this->db->fetchAll(
                <<<'SQL'
SELECT
    [SourceKey] AS [sourceKey],
    CONVERT(NVARCHAR(36), [TargetProductPublicId]) AS [targetPublicId],
    [CanonicalJson] AS [canonicalJson],
    [MatchMethod] AS [matchMethod],
    [CanonicalProductId] AS [canonicalProductId]
FROM [migration].[CanonicalProducts]
ORDER BY [CanonicalProductId] DESC
SQL
            );
            ++$this->azureReads;
        } catch (\Throwable) {
            $this->canonicalCache = [];

            return [];
        }
        $best = [];
        foreach ($rows as $row) {
            $sourceKey = AzureSqlValueMapper::stringValue($row['sourceKey'] ?? '');
            if ($sourceKey === '' || isset($best[$sourceKey])) {
                continue;
            }
            $json = AzureSqlValueMapper::stringValue($row['canonicalJson'] ?? '');
            $decoded = json_decode($json, true);
            $canonical = null;
            if (is_array($decoded)) {
                try {
                    $canonical = CanonicalProduct::fromArray(MigrationPayload::assoc($decoded));
                } catch (\Throwable) {
                    $canonical = null;
                }
            }
            $sourceId = null;
            $parts = explode(':', $sourceKey);
            if (count($parts) >= 3) {
                $sourceId = $parts[array_key_last($parts)];
            }
            $variantStyles = [];
            if ($canonical !== null) {
                foreach ($canonical->variants as $variant) {
                    if (is_string($variant->legacyStyleCode) && trim($variant->legacyStyleCode) !== '') {
                        $variantStyles[] = $variant->legacyStyleCode;
                    }
                }
            }
            $best[$sourceKey] = [
                'sourceKey' => $sourceKey,
                'sourceId' => $sourceId,
                'targetPublicId' => AzureSqlValueMapper::nullableString($row['targetPublicId'] ?? null),
                'legacyStyleCode' => $canonical?->legacyStyleCode,
                'title' => $canonical?->name,
                'kind' => $canonical?->legacyPostType,
                'matchMethod' => AzureSqlValueMapper::nullableString($row['matchMethod'] ?? null),
                'variantStyles' => $variantStyles,
            ];
        }

        $this->canonicalCache = array_values($best);

        return $this->canonicalCache;
    }

    /**
     * @return array<string, array{styleCode: ?string, title: ?string, kind: ?string}>
     */
    private function loadWordpressIdentity(): array
    {
        if ($this->wordpressCache !== null) {
            return $this->wordpressCache;
        }
        if (!$this->config->wordpressConfigured()) {
            $this->wordpressCache = [];

            return [];
        }
        try {
            $rows = $this->wordpress->discoverProductIdentityRows(['cable', 'accessory']);
            ++$this->wordpressReads;
        } catch (\Throwable) {
            $this->wordpressCache = [];

            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $postId = (string) (int) ($row['post_id'] ?? $row['ID'] ?? 0);
            if ($postId === '0') {
                continue;
            }
            $style = MigrationPayload::stringOrNull($row['style_code_raw'] ?? $row['style_code'] ?? null);
            $out[$postId] = [
                'styleCode' => $style,
                'title' => MigrationPayload::stringOrNull($row['post_title'] ?? $row['title'] ?? null),
                'kind' => MigrationPayload::stringOrNull($row['post_type'] ?? null),
            ];
        }
        $this->wordpressCache = $out;

        return $out;
    }

    /**
     * @return array<string, array{sourceKey: string, sourceId: ?string}>
     */
    private function loadLegacyMappings(): array
    {
        try {
            $rows = $this->db->fetchAll(
                <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), [TargetPublicId]) AS [targetPublicId],
    [SourceProductKey] AS [sourceProductKey],
    [SourceSystemCode] AS [sourceSystemCode],
    [SourceTable] AS [sourceTable]
FROM [migration].[LegacyProductMappings]
WHERE [TargetPublicId] IS NOT NULL
SQL
            );
            ++$this->azureReads;
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $target = strtolower(AzureSqlValueMapper::stringValue($row['targetPublicId'] ?? ''));
            if ($target === '') {
                continue;
            }
            $sourceId = AzureSqlValueMapper::nullableString($row['sourceProductKey'] ?? null);
            $system = AzureSqlValueMapper::stringValue($row['sourceSystemCode'] ?? 'wordpress');
            $table = AzureSqlValueMapper::nullableString($row['sourceTable'] ?? null) ?? 'wp_posts';
            $out[$target] = [
                'sourceKey' => $system . ':' . $table . ':' . (string) $sourceId,
                'sourceId' => $sourceId,
            ];
        }

        return $out;
    }
}
