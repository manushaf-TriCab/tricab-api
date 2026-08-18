<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO;

/**
 * Claims extracted from a cryptographically validated Apple ID token.
 */
final readonly class VerifiedAppleIdToken
{
    public function __construct(
        public string $subjectId,
        public string $emailAddress,
        public bool $emailVerified,
    ) {
    }
}
