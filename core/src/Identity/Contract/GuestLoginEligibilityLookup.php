<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Application\DTO\GuestLoginEligibility;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Resolves whether a normalized email may receive a guest OTP login code.
 */
interface GuestLoginEligibilityLookup
{
    public function evaluate(EmailAddress $emailAddress): GuestLoginEligibility;
}
