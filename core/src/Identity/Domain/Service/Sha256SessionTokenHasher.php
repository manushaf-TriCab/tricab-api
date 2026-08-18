<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Contract\SessionTokenHasher;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;

/**
 * SHA-256 binary hasher for guest session tokens (not password_hash).
 */
final class Sha256SessionTokenHasher implements SessionTokenHasher
{
    public function hash(string $rawToken): SessionTokenHash
    {
        return SessionTokenHash::fromBinary(hash('sha256', $rawToken, true));
    }
}
