<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Guest session plus rotated trusted-device credential.
 * Never log access tokens or device credentials.
 */
final readonly class ExchangedTrustedDeviceSession
{
    public function __construct(
        public IssuedGuestSession $session,
        public RegisteredTrustedDevice $deviceCredential,
    ) {
    }
}
