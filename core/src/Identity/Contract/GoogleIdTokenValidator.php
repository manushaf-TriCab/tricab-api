<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Application\DTO\VerifiedGoogleIdToken;

/**
 * Validates a Google ID token (signature, audience, issuer, expiry).
 * Implementations live in the API layer.
 */
interface GoogleIdTokenValidator
{
    public function validate(string $idToken): VerifiedGoogleIdToken;
}
