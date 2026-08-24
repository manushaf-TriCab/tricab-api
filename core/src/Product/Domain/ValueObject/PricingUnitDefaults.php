<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

/**
 * System fallback when no product / variant / price-row UOM is configured.
 */
final class PricingUnitDefaults
{
    public const CODE = 'M';

    public const SYMBOL = 'm';

    public const NAME = 'Metre';
}
