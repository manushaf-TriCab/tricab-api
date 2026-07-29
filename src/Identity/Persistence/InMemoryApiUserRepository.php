<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;
use TriCab\Core\Identity\Domain\ValueObject\ExternalSubjectId;
use TriCab\Core\Identity\Domain\ValueObject\ExternalTenantId;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Development/test-only UserRepository implementation.
 * Not for production and not part of TriCab Core.
 */
final class InMemoryApiUserRepository implements UserRepository
{
    private readonly User $user;
    private readonly ExternalIdentity $externalIdentity;

    public function __construct()
    {
        $this->user = new User(
            UserId::fromInt(1),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            EmailAddress::fromString('thilanh@tricab.com'),
            'Thilan Lakshitha',
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            [PermissionCode::fromString('account.login')],
        );

        $this->externalIdentity = new ExternalIdentity(
            ExternalProvider::fromString('microsoft-entra'),
            ExternalSubjectId::fromString('development-thilan'),
            ExternalTenantId::fromString('development-tenant'),
        );
    }

    public function findById(UserId $userId): ?User
    {
        return $this->user->id()->equals($userId) ? $this->user : null;
    }

    public function findByEmail(EmailAddress $emailAddress): ?User
    {
        return $this->user->emailAddress()->equals($emailAddress) ? $this->user : null;
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?User
    {
        return $this->externalIdentity->equals($identity) ? $this->user : null;
    }
}
