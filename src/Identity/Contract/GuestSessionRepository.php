<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\Entity\GuestSession;
use TriCab\Core\Identity\Domain\ValueObject\GuestSessionId;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

interface GuestSessionRepository
{
    public function save(GuestSession $session): void;

    public function findById(GuestSessionId $id): ?GuestSession;

    public function findByPublicId(UuidPublicId $publicId): ?GuestSession;

    public function findBySessionTokenHash(SessionTokenHash $sessionTokenHash): ?GuestSession;

    /**
     * Revokes all active sessions for the user.
     */
    public function revokeActiveSessionsForUser(UserId $userId): void;
}
