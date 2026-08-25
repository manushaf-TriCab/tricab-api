<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

final readonly class PasskeyAttestationResult
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        public string $credentialId,
        public string $publicKey,
        public int $signCount,
        public array $transports,
        public ?string $aaguid,
        public bool $userVerified,
    ) {
    }
}
