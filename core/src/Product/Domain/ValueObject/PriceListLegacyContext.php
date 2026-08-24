<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\ValueObject;

/**
 * External mapping keys for legacy tc.price debtor-company (dbco) contexts.
 */
final class PriceListLegacyContext
{
    public const SYSTEM_CODE = 'legacy-tc';

    public const CONTEXT_TYPE = 'dbco';
}
