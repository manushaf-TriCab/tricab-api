<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Pricing;

use TriCab\Core\Customer\Application\Service\ResolveAuthenticatedCustomerContextService;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * Builds CustomerPricingContext from authenticated user + linked customer record.
 * Never trusts browser-supplied margin / dbco / customer impersonation (except staff later).
 */
final class ResolveCustomerPricingContextService implements CustomerPricingContextResolver
{
    public function __construct(
        private readonly ResolveAuthenticatedCustomerContextService $customerContext,
        private readonly CustomerRepository $customers,
    ) {
    }

    public function fromAuthenticatedUser(?AuthenticatedUser $user): CustomerPricingContext
    {
        if ($user === null) {
            return CustomerPricingContext::guest();
        }

        $portal = $this->customerContext->execute($user->publicId, $user->userId, $user->roles);
        if ($portal === null || $portal->customerPublicIds === []) {
            return CustomerPricingContext::authenticatedWithoutCustomer($this->currencyForCountry(null));
        }

        $customerPublicId = $portal->customerPublicIds[0];
        $details = $this->customers->findDetailsByPublicId(CustomerPublicId::fromString($customerPublicId));
        if ($details === null) {
            return CustomerPricingContext::authenticatedWithoutCustomer($this->currencyForCountry(null));
        }

        $country = $details->countryCode;
        $sellCurrency = $this->currencyForCountry($country);

        return new CustomerPricingContext(
            customerPublicId: $customerPublicId,
            countryCode: $country,
            territoryCode: $details->territory,
            marketCode: $details->marketCode,
            sellCurrency: $sellCurrency,
            authState: 'OK',
        );
    }

    private function currencyForCountry(?string $countryCode): string
    {
        return match (strtoupper((string) $countryCode)) {
            'NZ' => 'NZD',
            'US' => 'USD',
            'AU', '' => 'AUD',
            default => 'AUD',
        };
    }
}
