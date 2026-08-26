<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Server-derived commercial context for sell-price resolution.
 * Never accept margin/dbco/priceListId from the browser.
 */
final readonly class CustomerPricingContext
{
    public function __construct(
        public ?string $customerPublicId,
        public ?string $countryCode,
        public ?string $territoryCode,
        public ?string $marketCode,
        public string $sellCurrency,
        public string $authState,
    ) {
    }

    public static function guest(): self
    {
        return new self(null, null, null, null, 'AUD', CustomerSellPriceStatus::LOGIN_REQUIRED);
    }

    public static function authenticatedWithoutCustomer(string $sellCurrency = 'AUD'): self
    {
        return new self(null, null, null, null, $sellCurrency, CustomerSellPriceStatus::CUSTOMER_REQUIRED);
    }
}
