<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

final class LegacyPricingResolutionSource
{
    public const APPROVED = 'Approved';

    public const EXACT = 'Exact';

    public const MASK = 'Mask';

    public const UNRESOLVED = 'Unresolved';

    public const AMBIGUOUS = 'Ambiguous';

    public const INVALID = 'Invalid';
}
