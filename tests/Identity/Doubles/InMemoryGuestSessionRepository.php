<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Identity\Doubles;

use TriCab\Core\Identity\Contract\GuestSessionRepository;
use TriCab\Core\Identity\Domain\Entity\GuestSession;
use TriCab\Core\Identity\Domain\ValueObject\GuestSessionId;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

final class InMemoryGuestSessionRepository implements GuestSessionRepository
{
    /** @var array<int, GuestSession> */
    private array $sessions = [];

    private int $nextId = 1;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function save(GuestSession $session): void
    {
        if ($session->id() === null) {
            $session->assignId(GuestSessionId::fromInt($this->nextId));
            ++$this->nextId;
        }

        $id = $session->id();
        assert($id instanceof GuestSessionId);
        $this->sessions[$id->toInt()] = $session;
    }

    public function findById(GuestSessionId $id): ?GuestSession
    {
        return $this->sessions[$id->toInt()] ?? null;
    }

    public function findByPublicId(UuidPublicId $publicId): ?GuestSession
    {
        foreach ($this->sessions as $session) {
            if ($session->publicId()->equals($publicId)) {
                return $session;
            }
        }

        return null;
    }

    public function findBySessionTokenHash(SessionTokenHash $sessionTokenHash): ?GuestSession
    {
        foreach ($this->sessions as $session) {
            if ($session->sessionTokenHash()->equals($sessionTokenHash)) {
                return $session;
            }
        }

        return null;
    }

    public function revokeActiveSessionsForUser(UserId $userId): void
    {
        $now = $this->clock->now();

        foreach ($this->sessions as $session) {
            if ($session->userId()->equals($userId) && $session->isActive($now)) {
                $session->revoke($now);
            }
        }
    }

    /** @return list<GuestSession> */
    public function all(): array
    {
        return array_values($this->sessions);
    }
}
