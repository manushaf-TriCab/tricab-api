<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\DTO\GuestLoginEligibility;
use TriCab\Core\Identity\Application\Exception\GuestLoginNotEligible;
use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;

/**
 * Resolves a pre-registered platform user for an email-otp identity.
 * Does not auto-create identity.Users — guest OTP is invite-only.
 */
final class GuestUserProvisioningService
{
    public const GUEST_ROLE = 'GUEST';
    public const DEFAULT_PERMISSION = 'ACCOUNT.LOGIN';

    public function __construct(
        private readonly UserProvisioningRepository $users,
    ) {
    }

    public function ensureGuestUser(EmailAddress $emailAddress): User
    {
        $identity = EmailOtpExternalIdentityFactory::forEmail($emailAddress);

        $existingByIdentity = $this->users->findByExternalIdentity($identity);
        if ($existingByIdentity !== null) {
            return $existingByIdentity;
        }

        $existingByEmail = $this->users->findByEmail($emailAddress);
        if ($existingByEmail !== null) {
            $this->users->linkExternalIdentity($existingByEmail, $identity);

            return $existingByEmail;
        }

        throw GuestLoginNotEligible::fromEligibility(GuestLoginEligibility::notFound());
    }
}
