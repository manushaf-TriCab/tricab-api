<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\QuoteOrder\Contract\QuoteLegacyOperatorIdResolver;

/** Test / dry-run double that supplies a fixed legacy operator id. */
final class FixedQuoteLegacyOperatorIdResolver implements QuoteLegacyOperatorIdResolver
{
    public function __construct(private readonly int $operatorId)
    {
    }

    public function resolveRaisedByOperatorId(AuthenticatedUser $actor, bool $customerGenerated): ?int
    {
        return $this->operatorId > 0 ? $this->operatorId : null;
    }
}
