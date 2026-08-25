<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

final readonly class PasskeyAssertionResult
{
    public function __construct(
        public string $credentialId,
        public int $signCount,
        public bool $userVerified,
        public ?string $userHandleHex,
    ) {
    }
}
