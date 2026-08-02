<?php

declare(strict_types=1);

namespace TriCab\Core\Identity\Contract;

use TriCab\Core\Identity\Application\DTO\ValidatedGuestToken;

/**
 * Validates a guest access token (crypto + session binding).
 * Implementations live in the API layer.
 */
interface GuestAccessTokenValidator
{
    public function validate(string $accessToken): ValidatedGuestToken;
}
