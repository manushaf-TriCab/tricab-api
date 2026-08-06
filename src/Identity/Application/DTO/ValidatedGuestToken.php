<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Cryptographically and session-validated guest token identity.
 * Roles/permissions are not taken from JWT claims.
 */
final readonly class ValidatedGuestToken
{
    public function __construct(
        public string $userPublicId,
        public string $guestSessionPublicId,
        public string $jwtId,
        public string $expiresAtUtc,
        public string $emailAddress,
    ) {
    }
}
