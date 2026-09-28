<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Application\Service;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\DTO\RfqAccessSummary;
use TriCab\Core\Rfq\Contract\RfqBranchDirectory;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;

/**
 * The authenticated platform user, as an RFQ actor. Once per request.
 *
 * Permissions come from the platform, which reloads them from Azure SQL on
 * every request; nothing here caches them. That is what makes a revoked RFQ
 * grant take effect on the next call rather than on the next sign-in
 * (invariant 1).
 */
final class ResolveRfqActorService
{
    public function __construct(
        private readonly RfqBranchDirectory $branches,
    ) {
    }

    public function forUser(AuthenticatedUser $user): RfqActor
    {
        return new RfqActor(
            $user->userId,
            $user->publicId,
            $user->permissions,
            $this->branches->branchFor($user->userId),
        );
    }

    public function accessSummary(AuthenticatedUser $user): RfqAccessSummary
    {
        return new RfqAccessSummary($this->forUser($user), $this->branches->source());
    }
}
