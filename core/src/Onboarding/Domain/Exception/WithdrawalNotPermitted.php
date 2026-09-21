<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Exception;

use DomainException;

/**
 * This allocation may not be withdrawn.
 *
 * There is exactly one case, and it is almost always a stale tab: somebody
 * withdrew it already. Maps to 409.
 */
final class WithdrawalNotPermitted extends DomainException
{
    public static function alreadyWithdrawn(): self
    {
        return new self('That allocation was already withdrawn.');
    }
}
