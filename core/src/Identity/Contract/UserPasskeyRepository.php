<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\Entity\UserPasskey;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Identity\Domain\ValueObject\WebAuthnUserHandle;

interface UserPasskeyRepository
{
    public function save(UserPasskey $passkey): void;

    public function findByCredentialId(string $credentialId): ?UserPasskey;

    public function findByPublicId(UuidPublicId $publicId): ?UserPasskey;

    /** @return list<UserPasskey> */
    public function listActiveByUserId(UserId $userId): array;

    public function findHandleByUserId(UserId $userId): ?WebAuthnUserHandle;

    public function saveHandle(UserId $userId, WebAuthnUserHandle $handle): void;

    public function findUserIdByHandle(WebAuthnUserHandle $handle): ?UserId;
}
