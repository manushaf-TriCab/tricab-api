<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;

/**
 * Hashes opaque/session bearer tokens for persistence (SHA-256 binary).
 */
interface SessionTokenHasher
{
    public function hash(string $rawToken): SessionTokenHash;
}
