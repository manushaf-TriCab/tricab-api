<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Middleware;

use DI\Container;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\IdentityNotLinked;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

/**
 * Guards admin routes behind a single required permission code.
 * Resolves the AuthenticatedUser the same way GetAuthenticatedUserAction does,
 * then attaches it to the request so downstream actions avoid re-resolving it.
 *
 * Container services are resolved lazily inside process() (request time),
 * not in the constructor: this middleware is built once per route at
 * app-bootstrap time, and eager resolution of ResolveAuthenticatedUserHandler
 * would force a UserRepository/SqlConnection lookup for every route on every
 * app build, even for requests that never touch a protected route.
 */
final class RequirePermissionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $requiredPermission,
        private readonly Container $container,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $responder = $this->get(JsonResponder::class);

        $attachedUser = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if ($attachedUser instanceof AuthenticatedUser) {
            $user = $attachedUser;
        } else {
            $verifiedIdentity = $request->getAttribute(VerifiedIdentityAttribute::KEY);

            if (!$verifiedIdentity instanceof VerifiedIdentity) {
                return $responder->error(
                    'UNAUTHENTICATED',
                    'Authentication is required.',
                    401,
                );
            }

            try {
                $user = $this->get(ResolveAuthenticatedUserHandler::class)->handle($verifiedIdentity);
            } catch (IdentityNotLinked) {
                return $responder->error(
                    'IDENTITY_NOT_LINKED',
                    'This Microsoft identity is not linked to a TriCab user.',
                    403,
                );
            } catch (AuthenticationNotAllowed $exception) {
                return $this->mapAuthenticationNotAllowed($responder, $exception);
            }
        }

        if (!$this->hasPermission($user, PermissionCode::fromString($this->requiredPermission))) {
            return $responder->error(
                'INSUFFICIENT_PERMISSION',
                'You do not have permission to perform this action.',
                403,
            );
        }

        return $handler->handle(
            $request->withAttribute(AuthenticatedUserAttribute::KEY, $user),
        );
    }

    private function hasPermission(AuthenticatedUser $user, PermissionCode $required): bool
    {
        foreach ($user->permissions as $permission) {
            if (PermissionCode::fromString($permission)->equals($required)) {
                return true;
            }
        }

        return false;
    }

    private function mapAuthenticationNotAllowed(JsonResponder $responder, AuthenticationNotAllowed $exception): ResponseInterface
    {
        $message = $exception->getMessage();

        if (str_contains($message, '"' . UserStatus::PENDING . '"')) {
            return $responder->error(
                'ACCOUNT_PENDING',
                'This TriCab account is pending activation.',
                403,
            );
        }

        return $responder->error(
            'ACCOUNT_DISABLED',
            'This TriCab account is disabled.',
            403,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function get(string $id): object
    {
        $service = $this->container->get($id);
        if (!$service instanceof $id) {
            throw new InvalidArgumentException(sprintf('Container service "%s" has unexpected type.', $id));
        }

        return $service;
    }
}
