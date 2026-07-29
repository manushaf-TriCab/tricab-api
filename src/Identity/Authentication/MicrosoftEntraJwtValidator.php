<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;

/**
 * Production Entra JWT validator placeholder.
 * Does not accept unsigned tokens or trust unverified payloads.
 */
final class MicrosoftEntraJwtValidator implements EntraTokenValidator
{
    public function validate(string $accessToken): VerifiedIdentity
    {
        unset($accessToken);

        throw EntraTokenValidationNotConfigured::create();
    }
}
