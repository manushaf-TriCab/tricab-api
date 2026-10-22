<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Assistant\Application\AssistantCatalogueVisibility;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\ValueObject\ExternalProvider;

/**
 * Resolves optional assistant catalogue access without turning the public
 * assistant route into an authenticated-only endpoint. Any missing or
 * insufficient identity fails closed to public catalogue visibility.
 */
final readonly class AssistantCatalogueVisibilityResolver
{
    private const STAFF_PERMISSION = 'PRODUCT.READ';

    public function __construct(
        private ResolveAuthenticatedUserHandler $resolveAuthenticatedUser,
    ) {
    }

    public function resolve(ServerRequestInterface $request): AssistantCatalogueVisibility
    {
        $identity = $request->getAttribute(VerifiedIdentityAttribute::KEY);
        if (
            !$identity instanceof VerifiedIdentity
            || strcasecmp($identity->provider, ExternalProvider::MICROSOFT_ENTRA) !== 0
        ) {
            return AssistantCatalogueVisibility::PUBLIC;
        }

        // A switched request carries the target user; their access is what applies.
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            try {
                $user = $this->resolveAuthenticatedUser->handle($identity);
            } catch (UserNotRegistered|IdentityConflict|IdentityAmbiguous|AuthenticationNotAllowed) {
                return AssistantCatalogueVisibility::PUBLIC;
            }
        }

        foreach ($user->permissions as $permission) {
            if (strcasecmp($permission, self::STAFF_PERMISSION) === 0) {
                return AssistantCatalogueVisibility::STAFF;
            }
        }

        return AssistantCatalogueVisibility::PUBLIC;
    }
}
