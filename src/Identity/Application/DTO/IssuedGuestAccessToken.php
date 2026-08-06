<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

use DateTimeImmutable;

/**
 * Plaintext guest JWT returned once from the issuer. Never persist or log.
 */
final readonly class IssuedGuestAccessToken
{
    public function __construct(
        public string $token,
        public DateTimeImmutable $expiresAtUtc,
        public string $sessionPublicId,
        public string $jwtId,
    ) {
    }
}
