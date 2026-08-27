<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\Service;

use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Product\Application\Pricing\CustomerPricingContext;

/**
 * Resolves EffectiveCustomerId for cart isolation keys.
 */
final class CartEffectiveCustomerResolver
{
    public function __construct(
        private readonly CustomerRepository $customers,
    ) {
    }

    public function resolve(?CustomerPricingContext $context): ?int
    {
        if ($context === null || $context->customerPublicId === null || $context->customerPublicId === '') {
            return null;
        }

        $publicId = $context->customerPublicId;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $publicId) === 1) {
            $id = $this->customers->findIdByPublicId(CustomerPublicId::fromString($publicId));

            return $id?->toInt();
        }

        // Stable synthetic id for non-UUID fixture contexts (unit tests / prepare fixtures).
        $hash = (int) sprintf('%u', crc32($publicId));

        return $hash === 0 ? 1 : $hash;
    }
}
