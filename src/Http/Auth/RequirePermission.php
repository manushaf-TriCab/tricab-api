<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use DI\Container;
use TriCab\Api\Http\Middleware\RequirePermissionMiddleware;

/**
 * Route-registration factory helper: `($require)('USER.READ')` builds a
 * RequirePermissionMiddleware bound to the DI container.
 *
 * Deliberately does NOT resolve ResolveAuthenticatedUserHandler/JsonResponder
 * eagerly here: this factory runs once per route at app-build time (i.e. on
 * every request in tests that build a fresh app), and eager resolution would
 * force a UserRepository/SqlConnection lookup — including Azure SQL/Entra CLI
 * authentication — for routes that are never actually invoked. Resolution is
 * deferred to request time inside RequirePermissionMiddleware::process().
 */
final class RequirePermission
{
    public function __construct(private readonly Container $c)
    {
    }

    public function __invoke(string $permission): RequirePermissionMiddleware
    {
        return new RequirePermissionMiddleware($permission, $this->c);
    }
}
