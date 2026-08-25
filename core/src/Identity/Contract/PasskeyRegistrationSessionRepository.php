<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\Entity\PasskeyRegistrationSession;

interface PasskeyRegistrationSessionRepository
{
    public function save(PasskeyRegistrationSession $session): void;

    public function findBySessionTokenHash(string $sessionTokenHash): ?PasskeyRegistrationSession;
}
