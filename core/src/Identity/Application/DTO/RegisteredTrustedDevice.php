<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Trusted-device credential returned once on register/rotate.
 * Never log $credential.
 */
final readonly class RegisteredTrustedDevice
{
    public function __construct(
        public string $publicId,
        public string $credential,
        public string $expiresAtUtc,
        public int $credentialVersion,
    ) {
    }

    /**
     * @return array{publicId: string, credential: string, expiresAtUtc: string, credentialVersion: int}
     */
    public function toArray(): array
    {
        return [
            'publicId' => $this->publicId,
            'credential' => $this->credential,
            'expiresAtUtc' => $this->expiresAtUtc,
            'credentialVersion' => $this->credentialVersion,
        ];
    }
}
