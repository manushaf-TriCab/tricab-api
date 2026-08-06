<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Doubles;

use TriCab\Core\Identity\Contract\UserRepository;
use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Minimal UserRepository double for provisioning / guest JWT unit tests.
 */
final class StubUserRepository implements UserRepository
{
    public ?User $userByEmail = null;

    public ?User $userByExternalIdentity = null;

    public function save(User $user): void
    {
        $this->userByEmail = $user;
    }

    public function findById(UserId $userId): ?User
    {
        if ($this->userByEmail !== null && $this->userByEmail->id()->equals($userId)) {
            return $this->userByEmail;
        }

        return null;
    }

    public function findByEmail(EmailAddress $emailAddress): ?User
    {
        if ($this->userByEmail === null) {
            return null;
        }

        return $this->userByEmail->emailAddress()->equals($emailAddress) ? $this->userByEmail : null;
    }

    public function findByExternalIdentity(ExternalIdentity $identity): ?User
    {
        return $this->userByExternalIdentity;
    }
}
