<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Application\DTO\VerifiedAppleIdToken;

/**
 * Validates an Apple ID token (signature, audience, issuer, expiry, nonce).
 * Implementations live in the API layer.
 */
interface AppleIdTokenValidator
{
    public function validate(string $idToken, string $expectedNonce): VerifiedAppleIdToken;
}
