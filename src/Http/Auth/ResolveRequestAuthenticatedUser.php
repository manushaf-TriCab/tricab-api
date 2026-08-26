<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;

/**
 * Resolves AuthenticatedUser for public customer routes that may only have VerifiedIdentity
 * (Entra Bearer or DEV auth). Never trusts client-supplied user/customer IDs.
 */
class ResolveRequestAuthenticatedUser
{
    public function __construct(
        private readonly ResolveAuthenticatedUserHandler $handler,
    ) {
    }

    public function fromRequest(ServerRequestInterface $request): ?AuthenticatedUser
    {
        $attached = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if ($attached instanceof AuthenticatedUser) {
            return $attached;
        }

        $verified = $request->getAttribute(VerifiedIdentityAttribute::KEY);
        if (!$verified instanceof VerifiedIdentity) {
            return null;
        }

        try {
            return $this->handler->handle($verified);
        } catch (UserNotRegistered|IdentityConflict|IdentityAmbiguous|AuthenticationNotAllowed) {
            return null;
        }
    }
}
