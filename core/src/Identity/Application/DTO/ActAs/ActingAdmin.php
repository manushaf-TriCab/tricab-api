<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\ActAs;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * The real administrator behind a switched request. Attached next to the
 * target AuthenticatedUser so downstream code can still tell who is acting.
 */
final readonly class ActingAdmin
{
    public function __construct(
        public AuthenticatedUser $admin,
        public string $sessionId,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
