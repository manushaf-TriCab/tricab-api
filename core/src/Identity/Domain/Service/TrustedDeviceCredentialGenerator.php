<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Domain\Service;

use TriCab\Core\Identity\Contract\SessionTokenHasher;
use TriCab\Core\Identity\Domain\ValueObject\SessionTokenHash;

/**
 * Generates opaque trusted-device credentials (base64url, no padding) and hashes them.
 */
final class TrustedDeviceCredentialGenerator
{
    public function __construct(
        private readonly SessionTokenHasher $hasher = new Sha256SessionTokenHasher(),
    ) {
    }

    public function generateRaw(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function hash(string $rawCredential): SessionTokenHash
    {
        return $this->hasher->hash($rawCredential);
    }
}
