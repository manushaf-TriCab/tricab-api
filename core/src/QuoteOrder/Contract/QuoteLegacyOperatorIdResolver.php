<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Contract;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * Resolves legacy tqoth.sa_op for the authenticated staff actor.
 * Portal quotes use the customer's representative — separate resolution.
 */
interface QuoteLegacyOperatorIdResolver
{
    public function resolveRaisedByOperatorId(AuthenticatedUser $actor, bool $customerGenerated): ?int;
}
