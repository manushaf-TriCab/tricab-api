<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Safe result after requesting and emailing a guest OTP.
 * Never contains the plaintext OTP.
 */
final readonly class SentGuestOtp
{
    public function __construct(
        public string $challengePublicId,
        public string $maskedEmailAddress,
        public string $expiresAtUtc,
    ) {
    }
}
