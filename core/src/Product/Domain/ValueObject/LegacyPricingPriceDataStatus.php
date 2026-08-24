<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

final class LegacyPricingPriceDataStatus
{
    public const NOT_FOUND = 'NOT_FOUND';

    public const AVAILABLE = 'AVAILABLE';

    public const ZERO_COST = 'ZERO_COST';

    public const PRICE_REVISION_AMBIGUOUS = 'PRICE_REVISION_AMBIGUOUS';
}
