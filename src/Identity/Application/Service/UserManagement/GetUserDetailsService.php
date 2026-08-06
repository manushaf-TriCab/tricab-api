<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service\UserManagement;

use TriCab\Core\Identity\Application\DTO\UserManagement\UserDetails;
use TriCab\Core\Identity\Application\Exception\UserNotFound;
use TriCab\Core\Identity\Contract\UserManagementAuditRepository;
use TriCab\Core\Identity\Contract\UserManagementRepository;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;

final class GetUserDetailsService
{
    public function __construct(
        private readonly UserManagementRepository $users,
        private readonly UserManagementAuditRepository $audit,
    ) {
    }

    public function execute(string $publicId): UserDetails
    {
        $id = UserPublicId::fromString($publicId);
        $details = $this->users->findDetailsByPublicId($id);

        if ($details === null) {
            throw UserNotFound::forPublicId($id->toString());
        }

        return new UserDetails(
            publicId: $details->publicId,
            emailAddress: $details->emailAddress,
            displayName: $details->displayName,
            status: $details->status,
            roles: $details->roles,
            effectivePermissions: $details->effectivePermissions,
            identityProviders: $details->identityProviders,
            createdAtUtc: $details->createdAtUtc,
            updatedAtUtc: $details->updatedAtUtc,
            lastLoginAtUtc: $details->lastLoginAtUtc,
            recentAuditEvents: $this->audit->listRecentForUser($id->toString(), 10),
        );
    }
}
