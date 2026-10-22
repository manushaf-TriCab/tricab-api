<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use TriCab\Core\Identity\Application\DTO\ActAs\ActingAdmin;

/**
 * Typed request-attribute key for the real administrator behind a switched
 * request, attached by ActAsMiddleware next to the target AuthenticatedUser.
 */
final class ActingAdminAttribute
{
    public const KEY = ActingAdmin::class;
}
