<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\Service;

use TriCab\Core\Identity\Application\Exception\GuestSessionNotFound;
use TriCab\Core\Identity\Contract\GuestSessionRepository;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;
use TriCab\Core\Identity\Domain\ValueObject\UuidPublicId;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * Revokes a guest session. Idempotent when already revoked.
 */
final class RevokeGuestSessionService
{
    public function __construct(
        private readonly GuestSessionRepository $sessions,
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function revokeByTokenHash(SessionTokenHash $sessionTokenHash): void
    {
        $session = $this->sessions->findBySessionTokenHash($sessionTokenHash);
        if ($session === null) {
            throw GuestSessionNotFound::create();
        }

        $session->revoke($this->clock->now());
        $this->sessions->save($session);
    }

    public function revokeBySessionPublicId(UuidPublicId $sessionPublicId): void
    {
        $session = $this->sessions->findByPublicId($sessionPublicId);
        if ($session === null) {
            throw GuestSessionNotFound::create();
        }

        $session->revoke($this->clock->now());
        $this->sessions->save($session);
    }
}
