<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

use TriCab\Core\Product\Domain\Service\LegacyPricingMaskGenerator;

/**
 * Deterministic legacy tc.price.code → ProductVariant identity rules (Phase 13.2).
 *
 * Migration resolution precedence (legacy code → variant) — Phase 13.2A:
 *   1. EXACT — normalized code equals VariantCode or Sku (single hit)
 *   2. EXPLICIT — approved VariantLegacyPricingIdentity / ExternalEntityMapping
 *   3. MASK — XXX family price expands to matching variants (1→N intentional)
 *   4. UNRESOLVED / AMBIGUOUS
 *
 * Variant-direction runtime (existing ResolveLegacyVariantPricingIdentityService):
 *   EXPLICIT (if active) → EXACT → MASK — explicit remains operator override for a variant.
 *
 * Legacy runtime price inheritance (exact row vs mask row for same family):
 *   exact SKU price > mask family price.
 */
final class LegacyPriceIdentityPrecedence
{
    public function __construct(
        private readonly LegacyPricingMaskGenerator $maskGenerator = new LegacyPricingMaskGenerator(),
    ) {
    }

    public function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Resolve a legacy price code against an in-memory catalogue index.
     *
     * @param array<string, list<array{productId: string, productVariantId: string}>> $exactIndex normalized VariantCode/Sku → variants
     * @param array<string, string> $explicitCodeByVariantId variantPublicId → approved legacy code
     * @param array<string, list<array{productId: string, productVariantId: string, variantCode: string}>> $variantsByNormalizedCode
     * @param array<string, list<array{productId: string, productVariantId: string, variantCode: string}>>|null $maskIndex prebuilt maskCode → variants (preferred)
     *
     * @return array{
     *   identityType: string,
     *   productVariantIds: list<string>,
     *   productId: ?string,
     *   productVariantId: ?string,
     *   confidence: string,
     *   reasons: list<string>
     * }
     */
    public function resolveLegacyCode(
        string $legacyCode,
        array $exactIndex,
        array $explicitCodeByVariantId = [],
        array $variantsByNormalizedCode = [],
        ?array $maskIndex = null,
    ): array {
        $normalized = $this->normalize($legacyCode);
        if ($normalized === '') {
            return $this->result(PriceImportResolution::IDENTITY_UNRESOLVED, [], null, null, 'LOW', ['Empty legacy code.']);
        }

        $exactHits = $exactIndex[$normalized] ?? [];
        if (count($exactHits) === 1) {
            return $this->result(
                PriceImportResolution::IDENTITY_EXACT,
                [$exactHits[0]['productVariantId']],
                $exactHits[0]['productId'],
                $exactHits[0]['productVariantId'],
                'HIGH',
                ['Exact VariantCode/Sku match.'],
            );
        }
        if (count($exactHits) > 1) {
            $ids = array_values(array_unique(array_map(
                static fn (array $h): string => $h['productVariantId'],
                $exactHits,
            )));

            return $this->result(
                PriceImportResolution::IDENTITY_AMBIGUOUS,
                $ids,
                null,
                null,
                'LOW',
                ['Exact code matched multiple unrelated variants.'],
            );
        }

        // Explicit: variants whose approved mapping equals this code (after exact miss)
        $explicitHits = [];
        foreach ($explicitCodeByVariantId as $variantId => $mappedCode) {
            if ($this->normalize($mappedCode) === $normalized) {
                $explicitHits[] = (string) $variantId;
            }
        }
        if (count($explicitHits) === 1) {
            $variantId = $explicitHits[0];

            return $this->result(
                PriceImportResolution::IDENTITY_EXPLICIT,
                [$variantId],
                null,
                $variantId,
                'HIGH',
                ['Resolved via approved explicit mapping.'],
            );
        }
        if (count($explicitHits) > 1) {
            return $this->result(
                PriceImportResolution::IDENTITY_AMBIGUOUS,
                $explicitHits,
                null,
                null,
                'LOW',
                ['Multiple explicit mappings point to the same legacy code.'],
            );
        }

        // Mask family: one mask → many valid variants is intentional, not AMBIGUOUS.
        if (str_contains($normalized, 'XXX')) {
            $family = $maskIndex[$normalized]
                ?? $this->variantsMatchingMask($normalized, $variantsByNormalizedCode);
            if ($family === []) {
                return $this->result(
                    PriceImportResolution::IDENTITY_UNRESOLVED,
                    [],
                    null,
                    null,
                    'MEDIUM',
                    ['Mask code has no in-scope variants matching grammar.'],
                );
            }
            $ids = array_values(array_unique(array_map(
                static fn (array $h): string => $h['productVariantId'],
                $family,
            )));

            return $this->result(
                PriceImportResolution::IDENTITY_MASK,
                $ids,
                count($family) === 1 ? $family[0]['productId'] : null,
                count($family) === 1 ? $family[0]['productVariantId'] : null,
                'HIGH',
                ['Mask family price applies to ' . count($ids) . ' variant(s).'],
            );
        }

        return $this->result(
            PriceImportResolution::IDENTITY_UNRESOLVED,
            [],
            null,
            null,
            'LOW',
            ['No exact, mask, or explicit identity.'],
        );
    }

    /**
     * Build maskCode → variants index in one O(variants) pass.
     *
     * @param list<array{productId: string, productVariantId: string, variantCode: string, sku?: string}> $variants
     * @return array<string, list<array{productId: string, productVariantId: string, variantCode: string}>>
     */
    public function buildMaskIndex(array $variants): array
    {
        $index = [];
        foreach ($variants as $v) {
            $vc = strtoupper(trim((string) ($v['variantCode'] ?? '')));
            if ($vc === '') {
                $vc = strtoupper(trim((string) ($v['sku'] ?? '')));
            }
            if ($vc === '') {
                continue;
            }
            foreach ($this->maskGenerator->generateCandidates($vc) as $mask) {
                $index[$mask][] = [
                    'productId' => (string) $v['productId'],
                    'productVariantId' => (string) $v['productVariantId'],
                    'variantCode' => $vc,
                ];
            }
        }

        return $index;
    }

    /**
     * Variant → preferred legacy price code candidates (exact then mask).
     *
     * @return list<string>
     */
    public function candidateCodesForVariant(string $variantCode): array
    {
        $exact = $this->normalize($variantCode);
        if ($exact === '') {
            return [];
        }

        $codes = [$exact];
        foreach ($this->maskGenerator->generateCandidates($exact) as $mask) {
            if (!in_array($mask, $codes, true)) {
                $codes[] = $mask;
            }
        }

        return $codes;
    }

    /**
     * @param array<string, list<array{productId: string, productVariantId: string, variantCode: string}>> $variantsByNormalizedCode
     * @return list<array{productId: string, productVariantId: string, variantCode: string}>
     */
    private function variantsMatchingMask(string $maskCode, array $variantsByNormalizedCode): array
    {
        $hits = [];
        foreach ($variantsByNormalizedCode as $variantCode => $rows) {
            $candidates = $this->maskGenerator->generateCandidates($variantCode);
            if (in_array($maskCode, $candidates, true)) {
                foreach ($rows as $row) {
                    $hits[] = $row;
                }
            }
        }

        return $hits;
    }

    /**
     * @param list<string> $variantIds
     * @param list<string> $reasons
     * @return array{
     *   identityType: string,
     *   productVariantIds: list<string>,
     *   productId: ?string,
     *   productVariantId: ?string,
     *   confidence: string,
     *   reasons: list<string>
     * }
     */
    private function result(
        string $identityType,
        array $variantIds,
        ?string $productId,
        ?string $productVariantId,
        string $confidence,
        array $reasons,
    ): array {
        return [
            'identityType' => $identityType,
            'productVariantIds' => $variantIds,
            'productId' => $productId,
            'productVariantId' => $productVariantId,
            'confidence' => $confidence,
            'reasons' => $reasons,
        ];
    }
}
