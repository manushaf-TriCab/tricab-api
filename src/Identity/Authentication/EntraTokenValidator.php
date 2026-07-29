<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;

interface EntraTokenValidator
{
    /**
     * Validate a raw Bearer access token and map claims to Core VerifiedIdentity.
     *
     * @throws EntraTokenValidationNotConfigured
     * @throws EntraTokenValidationFailed
     */
    public function validate(string $accessToken): VerifiedIdentity;
}
