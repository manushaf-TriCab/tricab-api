<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

/**
 * External mapping keys for variant → legacy tc.price.code pricing identity.
 */
final class VariantPricingLegacyContext
{
    public const SYSTEM_CODE = 'legacy-tc';

    public const ENTITY_TYPE = 'variant-pricing-item';
}
