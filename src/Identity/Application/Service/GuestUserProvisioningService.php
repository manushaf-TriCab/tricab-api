<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Ensures a guest platform user exists for an email-otp identity.
 * Role codes are resolved as value objects — never hard-coded numeric IDs.
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

        return $this->users->register(
            emailAddress: $emailAddress,
            displayName: $emailAddress->toString(),
            status: UserStatus::active(),
            roles: [RoleCode::fromString(self::GUEST_ROLE)],
            permissions: [PermissionCode::fromString(self::DEFAULT_PERMISSION)],
            identity: $identity,
        );
    }
}
