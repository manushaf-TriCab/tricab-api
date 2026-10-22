<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Application\DTO\ActAs;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * A session together with the target user it switches into. Also the result
 * of starting a session.
 */
final readonly class ActAsResolution
{
    public function __construct(
        public ActAsSession $session,
        public AuthenticatedUser $target,
    ) {
    }
}
