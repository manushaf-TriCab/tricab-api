<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use TriCab\Core\Identity\Application\DTO\ValidatedGuestToken;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;

/**
 * Result of composite Bearer validation (Entra or guest).
 */
final readonly class BearerAuthResult
{
    public function __construct(
        public VerifiedIdentity $identity,
        public ?ValidatedGuestToken $guestToken = null,
    ) {
    }
}
