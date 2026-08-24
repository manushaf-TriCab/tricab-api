<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

final class LegacyPricingResolutionStatus
{
    public const EXPLICIT = 'EXPLICIT';

    public const EXACT_CODE = 'EXACT_CODE';

    public const MASK = 'MASK';

    public const UNRESOLVED = 'UNRESOLVED';

    public const AMBIGUOUS = 'AMBIGUOUS';

    public const EXPLICIT_MAPPING_INVALID = 'EXPLICIT_MAPPING_INVALID';
}
