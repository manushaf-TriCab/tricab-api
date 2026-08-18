<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\Entity\ExternalIdentity;
use TriCab\Core\Identity\Domain\Entity\User;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface UserRepository
{
    public function findById(UserId $userId): ?User;

    public function findByEmail(EmailAddress $emailAddress): ?User;

    public function findByExternalIdentity(ExternalIdentity $identity): ?User;
}
