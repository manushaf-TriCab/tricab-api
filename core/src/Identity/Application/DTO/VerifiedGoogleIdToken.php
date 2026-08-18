<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Claims extracted from a cryptographically validated Google ID token.
 * Never trust browser-supplied email outside this DTO.
 */
final readonly class VerifiedGoogleIdToken
{
    public function __construct(
        public string $subjectId,
        public string $emailAddress,
        public bool $emailVerified,
    ) {
    }
}
