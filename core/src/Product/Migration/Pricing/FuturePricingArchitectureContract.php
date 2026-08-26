<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Pricing;

/**
 * Future architecture contracts (Phase 13.7+) — documentation as typed shapes only.
 * No resolver implementation or persistence.
 */
final class FuturePricingArchitectureContract
{
    public const CUSTOMER_PRODUCT_PRICES = 'DORMANT';
    public const PRICING_TIERS = 'DORMANT';
    public const MAKE_COST_FEATURE = 'STAFF_COSTING_FEATURE';
    public const TAX_POLICY = 'TAX_EXCLUSIVE';
    public const TAS_RULE = 'LEGACY_SPECIAL_RULE_REVIEW';

    /**
     * @return array<string, mixed>
     */
    public function customerPricingContextShape(): array
    {
        return [
            'customerId' => null,
            'countryId' => null,
            'territoryId' => null,
            'marketId' => null,
            'regionId' => null,
            'sellCurrency' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerSellPriceResolutionShape(): array
    {
        return [
            'productVariantId' => null,
            'baseCost' => null,
            'baseCurrency' => null,
            'sellCurrency' => null,
            'fxRate' => null,
            'fxSource' => null,
            'marginPercent' => null,
            'marginRuleId' => null,
            'sellPrice' => null,
            'status' => 'NOT_AVAILABLE', // RESOLVED|TBC|NOT_AVAILABLE|REVIEW|LOGIN_REQUIRED|CUSTOMER_REQUIRED
            'trace' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function makeCostResolutionShape(): array
    {
        return [
            'originalBaseCost' => null,
            'requestedLength' => null,
            'setupAdjustment' => null,
            'adjustedCostPerUnit' => null,
            'status' => 'NOT_AVAILABLE',
            'trace' => [],
        ];
    }

    /**
     * @return list<string>
     */
    public function publicApiMustNeverExpose(): array
    {
        return [
            'baseCost',
            'internal margin rule details',
            'internal setup costing',
            'raw tc.price cost',
        ];
    }

    /**
     * @return list<string>
     */
    public function priceDisplayStates(): array
    {
        return [
            'RESOLVED',
            'TBC',
            'LOGIN_REQUIRED',
            'CUSTOMER_REQUIRED',
            'PRICE_NOT_AVAILABLE',
            'REVIEW',
        ];
    }
}
