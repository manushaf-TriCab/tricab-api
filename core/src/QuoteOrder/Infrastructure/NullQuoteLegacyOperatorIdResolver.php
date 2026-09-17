<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver;

/**
 * Placeholder until staff↔legacy descript/operator mapping is proven.
 * Always returns null → SaveQuote records MISSING_LEGACY_OPERATOR_ID blocker.
 */
final class NullQuoteLegacyOperatorIdResolver implements QuoteLegacyOperatorIdResolver
{
    public function resolveRaisedByOperatorId(AuthenticatedUser $actor, bool $customerGenerated): ?int
    {
        return null;
    }
}
