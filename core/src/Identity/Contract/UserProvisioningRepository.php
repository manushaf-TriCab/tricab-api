<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Application\DTO\VerifiedEmailOwner;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Write-capable user port for guest provisioning.
 * Read methods mirror UserRepository for convenience in application services.
 */
interface UserProvisioningRepository
{
    public function findByEmail(EmailAddress $emailAddress): ?User;

    /**
     * @return list<User>
     */
    public function findAllByEmail(EmailAddress $emailAddress): array;

    /**
     * Every user holding this address as a *verified* address, with what that
     * address is for. Supersedes findAllByEmail() in the resolution path: a
     * person may hold both a personal and a corporate address, and matching
     * only the primary one forces a choice between their two logins.
     *
     * Still expected to yield at most one user — the normalized address is
     * unique — but returns a list so the caller can tell "none" from
     * "ambiguous" rather than silently picking one.
     *
     * @return list<VerifiedEmailOwner>
     */
    public function findAllByVerifiedEmail(EmailAddress $emailAddress): array;

    /**
     * Whether this address is one of the user's active verified addresses.
     * Used to accept an already-linked identity whose provider email is not
     * the user's primary address.
     */
    public function hasVerifiedAddress(UserId $userId, EmailAddress $emailAddress): bool;

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

    /**
     * Returns the active provider subject for a user, if one exists.
     */
    public function findActiveProviderSubjectForUser(UserId $userId, ExternalProvider $provider): ?ExternalSubjectId;
}
