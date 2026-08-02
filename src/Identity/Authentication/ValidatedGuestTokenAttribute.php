<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use TriCab\Core\Identity\Application\DTO\ValidatedGuestToken;

/**
 * Request attribute keys for guest Bearer validation results.
 */
final class ValidatedGuestTokenAttribute
{
    public const KEY = ValidatedGuestToken::class;

    /** Set when logout is allowed for an already-revoked guest session. */
    public const IDEMPOTENT_LOGOUT_KEY = 'guest.logout.idempotent';
}
