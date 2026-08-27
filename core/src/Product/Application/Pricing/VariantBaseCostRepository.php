<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/** Azure base-cost lookup for customer sell-price resolution. */
interface VariantBaseCostRepository
{
    /**
     * @return array{
     *   baseCost: string,
     *   currencyCode: string,
     *   priceListCode: string,
     *   uomCode: ?string,
     *   uomSymbol: ?string,
     *   variantCode: ?string,
     *   styleCode: ?string,
     *   availability: string
     * }|null
     */
    public function findActiveBaseCost(string $variantPublicId, string $asOfDateUtc): ?array;

    /**
     * @param list<string> $variantPublicIds
     * @return array<string, array{
     *   baseCost: string,
     *   currencyCode: string,
     *   priceListCode: string,
     *   uomCode: ?string,
     *   uomSymbol: ?string,
     *   variantCode: ?string,
     *   styleCode: ?string,
     *   availability: string
     * }>
     */
    public function findActiveBaseCosts(array $variantPublicIds, string $asOfDateUtc): array;
}
