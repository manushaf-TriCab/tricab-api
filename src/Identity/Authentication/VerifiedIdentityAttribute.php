<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;

/**
 * Typed request-attribute key for an already-verified external identity.
 */
final class VerifiedIdentityAttribute
{
    public const KEY = VerifiedIdentity::class;
}
