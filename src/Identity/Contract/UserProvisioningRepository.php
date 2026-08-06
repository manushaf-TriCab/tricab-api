<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Write-capable user port for guest provisioning.
 * Read methods mirror UserRepository for convenience in application services.
 */
interface UserProvisioningRepository
{
    public function findByEmail(EmailAddress $emailAddress): ?User;

    public function findByExternalIdentity(ExternalIdentity $identity): ?User;

    /**
     * Persists a new user and links the external identity in one operation.
     * Implementations assign database UserId / PublicId values.
     *
     * @param list<RoleCode> $roles
     * @param list<PermissionCode> $permissions
     */
    public function register(
        EmailAddress $emailAddress,
        string $displayName,
        UserStatus $status,
        array $roles,
        array $permissions,
        ExternalIdentity $identity,
    ): User;

    /**
     * Links an external identity to an existing user when the identity is absent.
     */
    public function linkExternalIdentity(User $user, ExternalIdentity $identity): void;
}
