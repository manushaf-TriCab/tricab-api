<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Already-verified external identity supplied by the API/auth adapter.
 * Does not contain or validate JWT tokens.
 */
final readonly class VerifiedIdentity
{
    public function __construct(
        public string $provider,
        public string $subjectId,
        public string $tenantId,
    ) {
    }
}
