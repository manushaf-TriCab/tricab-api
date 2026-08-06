<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * Typed request-attribute key for the resolved AuthenticatedUser, attached by
 * RequirePermissionMiddleware once a permission check has succeeded.
 */
final class AuthenticatedUserAttribute
{
    public const KEY = AuthenticatedUser::class;
}
