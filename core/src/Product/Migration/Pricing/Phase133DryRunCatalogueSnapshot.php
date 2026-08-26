<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * In-memory snapshot for Phase 13.3 dry-run (no DB writes).
 */
final readonly class Phase133DryRunCatalogueSnapshot
{
    /**
     * @param list<array{
     *   sourceRowId: string,
     *   code: string,
     *   cost: string|null,
     *   dc: string|null,
     *   dbco: int,
     *   br?: string|null,
     *   uwt?: string|null,
     *   uwtcu?: string|null,
     *   setup?: string|null
     * }> $legacyPriceRows all scanned rows including out-of-scope 2519 for counters
     * @param list<array{
     *   productId: string,
     *   productVariantId: string,
     *   variantCode: string,
     *   sku: string
     * }> $variants
     * @param array<string, string> $explicitLegacyCodeByVariantId active only
     * @param array<string, array{priceListCode: string, currencyCode: string, baseCost: string, effectiveFrom: ?string, source?: ?string}> $existingPrices keyed by variantId|priceListCode
     * @param array<string, array{priceListId: ?string, currencyId: ?string, currencyCode: string, dbco: int}> $priceListsByCode
     */
    public function __construct(
        public array $legacyPriceRows,
        public array $variants,
        public array $explicitLegacyCodeByVariantId = [],
        public array $existingPrices = [],
        public array $priceListsByCode = [],
        public string $referenceResolutionMode = Phase133BaseCostDryRunResolution::REF_CONTRACT_ONLY,
    ) {
    }
}
