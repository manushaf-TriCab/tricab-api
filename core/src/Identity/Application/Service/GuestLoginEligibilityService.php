<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\DTO\GuestLoginEligibility;
use TriCab\Core\Identity\Application\Exception\GuestLoginNotEligible;
use TriCab\Core\Identity\Contract\GuestLoginEligibilityLookup;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Application gate for guest OTP eligibility.
 * Does not create users, challenges, or send email.
 */
final class GuestLoginEligibilityService
{
    public function __construct(
        private readonly GuestLoginEligibilityLookup $lookup,
    ) {
    }

    public function evaluate(EmailAddress $emailAddress): GuestLoginEligibility
    {
        return $this->lookup->evaluate($emailAddress);
    }

    /**
     * @throws GuestLoginNotEligible
     */
    public function assertEligible(EmailAddress $emailAddress): GuestLoginEligibility
    {
        $eligibility = $this->evaluate($emailAddress);
        if (!$eligibility->eligible) {
            throw GuestLoginNotEligible::fromEligibility($eligibility);
        }

        return $eligibility;
    }
}
