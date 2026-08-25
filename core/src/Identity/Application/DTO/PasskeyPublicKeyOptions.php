<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

final readonly class PasskeyPublicKeyOptions
{
    /**
     * @param array<string, mixed> $publicKey
     */
    public function __construct(
        public string $challengeBinary,
        public array $publicKey,
    ) {
    }
}
