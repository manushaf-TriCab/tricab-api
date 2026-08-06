<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use RuntimeException;
use TriCab\Core\Identity\Contract\GuestSessionRepository;
use TriCab\Core\Identity\Domain\Entity\GuestSession;
use TriCab\Core\Identity\Domain\ValueObject\GuestSessionId;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;

final class FailingGuestSessionRepository implements GuestSessionRepository
{
    public function save(GuestSession $session): void
    {
        throw new RuntimeException('database unavailable');
    }

    public function findById(GuestSessionId $id): ?GuestSession
    {
        return null;
    }

    public function findByPublicId(UuidPublicId $publicId): ?GuestSession
    {
        return null;
    }

    public function findBySessionTokenHash(SessionTokenHash $sessionTokenHash): ?GuestSession
    {
        return null;
    }

    public function revokeActiveSessionsForUser(UserId $userId): void
    {
    }
}
