<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Result of requesting a guest OTP. Plaintext OTP is for infrastructure email sending only.
 */
final readonly class RequestedGuestOtp
{
    public function __construct(
        public string $emailAddress,
        public string $purpose,
        public string $plaintextOtp,
        public string $challengePublicId,
        public string $expiresAtUtc,
    ) {
    }
}
