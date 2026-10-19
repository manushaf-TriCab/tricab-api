<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr\Support;

use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UserPublicId;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/** The identity users the NPR workflow looks up (managers, owners, signatories). */
final class NprUserDirectory implements UserRepository
{
    /** @var array<int, User> */
    private array $users = [];

    /** @param list<string> $permissions */
    public function add(int $id, string $email, string $name, array $permissions, bool $active = true): self
    {
        $this->users[$id] = new User(
            UserId::fromInt($id),
            UserPublicId::fromString(sprintf('00000000-0000-4000-8000-%012d', $id)),
            EmailAddress::fromString($email),
            $name,
            $active ? UserStatus::active() : UserStatus::disabled(),
            [RoleCode::fromString('EMPLOYEE')],
            array_map(static fn (string $code): PermissionCode => PermissionCode::fromString($code), $permissions),
        );

        return $this;
    }

    public function findById(UserId $userId): ?User
    {
        return $this->users[$userId->toInt()] ?? null;
    }

    public function findByEmail(EmailAddress $emailAddress): ?User
    {
        foreach ($this->users as $user) {
            if ($user->emailAddress()->equals($emailAddress)) {
                return $user;
            }
        }

        return null;
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?User
    {
        return null;
    }
}
