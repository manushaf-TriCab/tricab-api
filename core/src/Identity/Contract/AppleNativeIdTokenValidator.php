<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Application\DTO\VerifiedAppleIdToken;

/** Validates native Sign in with Apple identity tokens (bundle ID audience). */
interface AppleNativeIdTokenValidator
{
    public function validateNative(string $idToken): VerifiedAppleIdToken;
}
