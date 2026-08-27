<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Commercial variant catalogue metadata for customer sell-price resolution.
 * Does NOT require ProductVariantPrices — supports Cost API-first pricing.
 */
interface VariantCommercialCatalogRepository
{
    /**
     * @return array{
     *   variantPublicId: string,
     *   variantCode: string,
     *   sku: ?string,
     *   styleCode: ?string,
     *   uomCode: string,
     *   uomSymbol: string,
     *   legacyProductCode: string,
     *   azureBase: ?array{
     *     baseCost: string,
     *     currencyCode: string,
     *     priceListCode: string,
     *     availability: string
     *   }
     * }|null
     */
    public function findCommercialCatalog(string $variantPublicId, string $asOfDateUtc): ?array;
}
