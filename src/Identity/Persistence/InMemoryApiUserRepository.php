<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
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
 * Development/test UserRepository + provisioning store.
 * Seeds one Entra employee and allows guest user registration.
 */
final class InMemoryApiUserRepository implements UserRepository, UserProvisioningRepository
{
    /** @var array<int, User> */
    private array $usersById = [];

    /** @var array<string, int> */
    private array $userIdsByEmail = [];

    /** @var array<string, int> */
    private array $userIdsByExternalIdentity = [];

    private int $nextId = 2;

    private readonly UuidV4Generator $uuids;

    public function __construct()
    {
        $this->uuids = new UuidV4Generator();

        $employee = new User(
            UserId::fromInt(1),
            UserPublicId::fromString('550e8400-e29b-41d4-a716-446655440000'),
            EmailAddress::fromString('thilanh@tricab.com'),
            'Thilan Lakshitha',
            UserStatus::active(),
            [RoleCode::fromString('EMPLOYEE')],
            [PermissionCode::fromString('account.login')],
        );

        $identity = new ExternalIdentity(
            ExternalProvider::fromString('microsoft-entra'),
            ExternalSubjectId::fromString('development-thilan'),
            ExternalTenantId::fromString('development-tenant'),
        );

        $this->persist($employee, $identity);
    }

    public function findById(UserId $userId): ?User
    {
        return $this->usersById[$userId->toInt()] ?? null;
    }

    public function findByEmail(EmailAddress $emailAddress): ?User
    {
        $userId = $this->userIdsByEmail[$emailAddress->toString()] ?? null;

        return $userId === null ? null : ($this->usersById[$userId] ?? null);
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?User
    {
        $userId = $this->userIdsByExternalIdentity[$this->externalIdentityKey($identity)] ?? null;

        return $userId === null ? null : ($this->usersById[$userId] ?? null);
    }

    /**
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
    ): User {
        $user = new User(
            UserId::fromInt($this->nextId),
            UserPublicId::fromString($this->uuids->generate()->toString()),
            $emailAddress,
            $displayName,
            $status,
            $roles,
            $permissions,
        );
        ++$this->nextId;

        $this->persist($user, $identity);

        return $user;
    }

    public function linkExternalIdentity(User $user, ExternalIdentity $identity): void
    {
        $this->persist($user, $identity);
    }

    private function persist(User $user, ?ExternalIdentity $externalIdentity = null): void
    {
        $userId = $user->id()->toInt();
        $this->usersById[$userId] = $user;
        $this->userIdsByEmail[$user->emailAddress()->toString()] = $userId;

        if ($externalIdentity !== null) {
            $this->userIdsByExternalIdentity[$this->externalIdentityKey($externalIdentity)] = $userId;
        }
    }

    private function externalIdentityKey(ExternalIdentity $identity): string
    {
        return implode('|', [
            $identity->provider()->toString(),
            $identity->subjectId()->toString(),
            $identity->tenantId()->toString(),
        ]);
    }
}
