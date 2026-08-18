<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Temporary in-memory UserRepository for Core smoke verification.
 * No database access.
 */
final class FakeUserRepository implements UserRepository
{
    private readonly User $user;

    public function __construct()
    {
        $this->user = new User(
            UserId::fromInt(1),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            EmailAddress::fromString('thilanh@tricab.com'),
            'Thilan Lakshitha',
            UserStatus::active(),
            [RoleCode::fromString('employee')],
            [PermissionCode::fromString('PROFILE:READ')],
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
        return null;
    }

    /**
     * @return array{
     *     id: int,
     *     email: string,
     *     displayName: string,
     *     status: string,
     *     roles: list<string>
     * }
     */
    public function toSmokeArray(): array
    {
        $roles = [];
        foreach ($this->user->roles() as $role) {
            $roles[] = strtolower($role->toString());
        }

        return [
            'id' => $this->user->id()->toInt(),
            'email' => $this->user->emailAddress()->toString(),
            'displayName' => $this->user->displayName(),
            'status' => $this->user->status()->toString(),
            'roles' => $roles,
        ];
    }
}
