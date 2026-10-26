<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Ap2;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;

/**
 * Builds the {@see Ap2Actor} of a request from the bearer token. Null means no signed-in person (answer 401). The
 * route's own permission has already been checked by `RequirePermission`.
 */
final class Ap2ActorResolver
{
    public function actorOf(ServerRequestInterface $request): ?Ap2Actor
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return null;
        }

        return new Ap2Actor($user->userId, $user->displayName, $user->emailAddress, $user->permissions);
    }
}
