<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Cryptographically and session-validated guest token identity.
 * Roles/permissions are not taken from JWT claims.
 *
 * When authenticatedUser is present, API middleware can attach it for /me and
 * RequirePermission without resolving via ExternalIdentity (needed for Apple Sign-In).
 */
final readonly class ValidatedGuestToken
{
    public function __construct(
        public string $userPublicId,
        public string $guestSessionPublicId,
        public string $jwtId,
        public string $expiresAtUtc,
        public string $emailAddress,
        public ?AuthenticatedUser $authenticatedUser = null,
    ) {
    }
}
