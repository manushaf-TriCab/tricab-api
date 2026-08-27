<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

/**
 * Server-derived commercial context for sell-price resolution.
 * Never accept margin/dbco/priceListId from the browser as authority.
 */
final readonly class CustomerPricingContext
{
    public const MODE_SELF = 'SELF_CUSTOMER';
    public const MODE_STAFF_SELECTED = 'STAFF_SELECTED_CUSTOMER';

    public function __construct(
        public ?string $customerPublicId,
        public ?string $countryCode,
        public ?string $territoryCode,
        public ?string $marketCode,
        public string $sellCurrency,
        public string $authState,
        public string $pricingMode = self::MODE_SELF,
        public ?string $overrideMarginPercent = null,
        public ?string $customerCode = null,
        public ?string $customerDisplayName = null,
        public ?string $contactPublicId = null,
        public ?string $contactDisplayName = null,
        public ?string $contactTitle = null,
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

    public function withOverrideMargin(?string $marginPercent): self
    {
        return new self(
            customerPublicId: $this->customerPublicId,
            countryCode: $this->countryCode,
            territoryCode: $this->territoryCode,
            marketCode: $this->marketCode,
            sellCurrency: $this->sellCurrency,
            authState: $this->authState,
            pricingMode: $this->pricingMode,
            overrideMarginPercent: $marginPercent,
            customerCode: $this->customerCode,
            customerDisplayName: $this->customerDisplayName,
            contactPublicId: $this->contactPublicId,
            contactDisplayName: $this->contactDisplayName,
            contactTitle: $this->contactTitle,
        );
    }
}
