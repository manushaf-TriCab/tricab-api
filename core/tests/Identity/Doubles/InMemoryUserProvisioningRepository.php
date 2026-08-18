<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use TriCab\Core\Identity\Contract\UserProvisioningRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class InMemoryUserProvisioningRepository implements UserProvisioningRepository
{
    private readonly InMemoryUserRepository $inner;

    private int $nextId = 1;

    private readonly UuidV4Generator $uuids;

    public function __construct(?InMemoryUserRepository $inner = null)
    {
        $this->inner = $inner ?? new InMemoryUserRepository();
        $this->uuids = new UuidV4Generator();
    }

    public function findByEmail(EmailAddress $emailAddress): ?User
    {
        return $this->inner->findByEmail($emailAddress);
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?User
    {
        return $this->inner->findByExternalIdentity($identity);
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

        $this->inner->save($user, $identity);

        return $user;
    }

    public function linkExternalIdentity(User $user, ExternalIdentity $identity): void
    {
        $this->inner->save($user, $identity);
    }

    public function inner(): InMemoryUserRepository
    {
        return $this->inner;
    }
}
