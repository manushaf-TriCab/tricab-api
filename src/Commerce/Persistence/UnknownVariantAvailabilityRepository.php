<?php

declare(strict_types=1);

namespace TriCab\Api\Commerce\Persistence;

use TriCab\Core\Commerce\Contract\VariantAvailabilityRepository;
use TriCab\Core\Product\Application\Pricing\CustomerSellPriceStatus;

/**
 * Stub availability source.
 *
 * AVAILABILITY_SOURCE_REVIEW: Azure stock is not authoritative yet.
 * Temporary legacy mapping (2302=STOCKED, 2301=MAKE) belongs in a dedicated
 * LegacyVariantAvailabilityRepository — do not query legacy from HTTP actions.
 */
final class UnknownVariantAvailabilityRepository implements VariantAvailabilityRepository
{
    public function findAvailability(string $variantPublicId): string
    {
        unset($variantPublicId);

        return CustomerSellPriceStatus::AVAILABILITY_UNKNOWN;
    }
}
