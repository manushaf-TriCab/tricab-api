<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use TriCab\Core\Identity\Contract\PasskeyRegistrationSessionRepository;
use TriCab\Core\Identity\Domain\Entity\PasskeyRegistrationSession;

final class InMemoryPasskeyRegistrationSessionRepository implements PasskeyRegistrationSessionRepository
{
    /** @var array<int, PasskeyRegistrationSession> */
    private array $sessions = [];
    private int $nextId = 1;

    public function save(PasskeyRegistrationSession $session): void
    {
        if ($session->id() === null) {
            $session->assignId($this->nextId);
            ++$this->nextId;
        }
        $this->sessions[$session->id() ?? 0] = $session;
    }

    public function findBySessionTokenHash(string $sessionTokenHash): ?PasskeyRegistrationSession
    {
        foreach ($this->sessions as $session) {
            if (hash_equals($session->sessionTokenHash(), $sessionTokenHash)) {
                return $session;
            }
        }

        return null;
    }
}
