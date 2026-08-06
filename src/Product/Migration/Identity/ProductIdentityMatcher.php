<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Identity;

use TriCab\Core\Product\Contract\LegacyProductMappingRepository;
use TriCab\Core\Product\Migration\Canonical\CanonicalProduct;
use TriCab\Core\Product\Migration\Contract\MigrationLookupIndex;

/**
 * Deterministic Product identity matching. Never matches on name alone.
 */
final class ProductIdentityMatcher
{
    public function __construct(
        private readonly LegacyProductMappingRepository $legacyMappings,
        private readonly MigrationLookupIndex $lookups,
    ) {
    }

    public function match(CanonicalProduct $product): ProductIdentityMatchResult
    {
        [$system, $table, $id] = self::parseSourceKey($product->sourceKey);
        $sourceProductKey = $table !== '' ? $table . ':' . $id : $id;

        $existing = $this->legacyMappings->findBySource($system, $sourceProductKey);
        if ($existing === null && $id !== '') {
            $existing = $this->legacyMappings->findBySource($system, $id);
        }
        if ($existing !== null && $existing->productPublicId !== null) {
            return new ProductIdentityMatchResult(
                targetProductPublicId: $existing->productPublicId,
                targetVariantPublicId: $existing->productVariantPublicId,
                matchMethod: 'legacy-product-mapping',
                matchConfidence: ProductIdentityMatchResult::CONFIDENCE_EXACT,
                reason: 'Existing LegacyProductMappings row',
                requiresReview: false,
            );
        }

        $exactSource = $this->lookups->findProductPublicIdByExactSource($product->sourceKey);
        if ($exactSource !== null) {
            return new ProductIdentityMatchResult(
                $exactSource,
                null,
                'exact-legacy-source',
                ProductIdentityMatchResult::CONFIDENCE_EXACT,
                'Exact legacy source key',
                false,
            );
        }

        if ($product->legacyStyleCode !== null && $product->legacyStyleCode !== '') {
            $styleHits = $this->lookups->findProductPublicIdsByStyleCode($product->legacyStyleCode);
            if (count($styleHits) === 1) {
                return new ProductIdentityMatchResult(
                    $styleHits[0],
                    null,
                    'exact-style-code',
                    ProductIdentityMatchResult::CONFIDENCE_HIGH,
                    'Exact style code ' . $product->legacyStyleCode,
                    false,
                );
            }
            if (count($styleHits) > 1) {
                return ProductIdentityMatchResult::ambiguous(
                    'Multiple products share style code ' . $product->legacyStyleCode,
                );
            }
        }

        if ($product->productCode !== null && $product->productCode !== '') {
            $codeHits = $this->lookups->findProductPublicIdsByProductCode($product->productCode);
            if (count($codeHits) === 1) {
                return new ProductIdentityMatchResult(
                    $codeHits[0],
                    null,
                    'exact-product-code',
                    ProductIdentityMatchResult::CONFIDENCE_HIGH,
                    'Exact product code ' . $product->productCode,
                    false,
                );
            }
            if (count($codeHits) > 1) {
                return ProductIdentityMatchResult::ambiguous(
                    'Duplicate product code ' . $product->productCode,
                );
            }
        }

        foreach ($product->variants as $variant) {
            $sku = $variant->sku ?? $variant->productCode;
            if ($sku === null || $sku === '') {
                continue;
            }
            $skuHits = $this->lookups->findProductPublicIdsBySku($sku);
            if (count($skuHits) === 1) {
                return new ProductIdentityMatchResult(
                    $skuHits[0],
                    null,
                    'exact-sku',
                    ProductIdentityMatchResult::CONFIDENCE_HIGH,
                    'Exact SKU ' . $sku,
                    false,
                );
            }
            if (count($skuHits) > 1) {
                return ProductIdentityMatchResult::ambiguous('Duplicate SKU ' . $sku);
            }
        }

        if ($product->slug !== null && $product->slug !== '') {
            $normalized = strtolower(trim($product->slug));
            $slugHits = $this->lookups->findProductPublicIdsByNormalizedSlug($normalized);
            if (count($slugHits) === 1) {
                return new ProductIdentityMatchResult(
                    $slugHits[0],
                    null,
                    'exact-normalized-slug',
                    ProductIdentityMatchResult::CONFIDENCE_MEDIUM,
                    'Exact normalized slug ' . $normalized,
                    false,
                );
            }
            if (count($slugHits) > 1) {
                return ProductIdentityMatchResult::ambiguous('Duplicate slug ' . $normalized);
            }
        }

        $composite = $this->lookups->findApprovedCompositeMatch($product);
        if ($composite !== null) {
            return new ProductIdentityMatchResult(
                $composite,
                null,
                'approved-composite',
                ProductIdentityMatchResult::CONFIDENCE_MEDIUM,
                'Approved composite matching rule',
                false,
            );
        }

        return ProductIdentityMatchResult::none('No deterministic identity match; manual review required');
    }

    /** @return array{0: string, 1: string, 2: string} */
    public static function parseSourceKey(string $sourceKey): array
    {
        $parts = explode(':', $sourceKey);
        if (count($parts) >= 3) {
            return [$parts[0], $parts[1], implode(':', array_slice($parts, 2))];
        }
        if (count($parts) === 2) {
            return [$parts[0], '', $parts[1]];
        }

        return ['unknown', '', $sourceKey];
    }
}
