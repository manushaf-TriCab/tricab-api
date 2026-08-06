<?php

declare(strict_types=1);

namespace TriCab\Api\Customer\Persistence;

use RuntimeException;
use TriCab\Core\Customer\Contract\CustomerPortalUserProvisioner;
use TriCab\Core\Customer\Contract\ProvisionedPortalUser;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Identity-side adapter for the Customer bounded context's
 * CustomerPortalUserProvisioner port. Provisions a brand-new, active
 * Identity user carrying the requested customer-facing role via the
 * standard UserManagementRepository — never fabricates Entra identities.
 */
final class UserManagementCustomerPortalUserProvisioner implements CustomerPortalUserProvisioner
{
    public function __construct(
        private readonly UserManagementRepository $users,
    ) {
    }

    public function createActivePortalUser(
        EmailAddress $email,
        string $displayName,
        RoleCode $customerRole,
        UserId $actorUserId,
    ): ProvisionedPortalUser {
        $created = $this->users->createUser(
            emailAddress: $email,
            displayName: $displayName,
            status: UserStatus::active(),
            roleCodes: [$customerRole],
            actorUserId: $actorUserId,
        );

        $userPublicId = UserPublicId::fromString($created->publicId);
        $userId = $this->users->findUserIdByPublicId($userPublicId);

        if ($userId === null) {
            throw new RuntimeException(sprintf('Newly provisioned user "%s" could not be resolved.', $userPublicId->toString()));
        }

        return new ProvisionedPortalUser(
            userId: $userId,
            userPublicId: $userPublicId,
            email: $email,
            displayName: $displayName,
        );
    }
}
