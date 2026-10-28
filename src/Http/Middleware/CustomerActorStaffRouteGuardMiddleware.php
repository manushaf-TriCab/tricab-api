<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Middleware;

use DI\Container;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Routing\RouteContext;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Service\StaffEligibilityPolicy;

/**
 * Admits only eligible staff identities (StaffEligibilityPolicy) to the global
 * administration surface (/api/v1/admin/*), whatever permissions their roles
 * carry. Customer roles hold codes such as CUSTOMER.READ and PRODUCT.READ for
 * the customer portal; on admin routes those same codes would mean "every
 * customer" and "every product", so the role, not the permission, decides.
 *
 * A customer-oriented role disqualifies the identity even alongside staff
 * roles. Roles are re-read on every request, so a role change takes effect
 * immediately and no cached customer context is consulted.
 *
 * Registered as global middleware inside ActAsMiddleware, so a switched request
 * is judged on the target user; ActAsMiddleware has already judged the caller.
 * Unauthenticated or unresolvable requests pass through untouched for the
 * route middleware to reject as before.
 */
final class CustomerActorStaffRouteGuardMiddleware implements MiddlewareInterface
{
    public const ERROR_CUSTOMER_ACTOR = 'CUSTOMER_ACTOR_FORBIDDEN';
    public const ERROR_NOT_STAFF = 'STAFF_ROLE_REQUIRED';

    private const STAFF_PATH_PREFIX = '/api/v1/admin';

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute(RouteContext::ROUTE);
        $isStaffRoute = self::isStaffPath($request->getUri()->getPath())
            || ($route instanceof RouteInterface && self::isStaffPath($route->getPattern()));
        if (!$isStaffRoute) {
            return $handler->handle($request);
        }

        $user = $this->resolveUser($request);
        if ($user === null) {
            return $handler->handle($request);
        }

        return match (StaffEligibilityPolicy::evaluate($user->roles)) {
            StaffEligibilityPolicy::ELIGIBLE => $handler->handle(
                $request->withAttribute(AuthenticatedUserAttribute::KEY, $user),
            ),
            StaffEligibilityPolicy::CUSTOMER_ACTOR => $this->get(JsonResponder::class)->error(
                self::ERROR_CUSTOMER_ACTOR,
                'Customer accounts cannot use staff administration endpoints.',
                403,
            ),
            default => $this->get(JsonResponder::class)->error(
                self::ERROR_NOT_STAFF,
                'Staff administration requires a TriCab staff account.',
                403,
            ),
        };
    }

    /**
     * Decoded the same way the router decodes it, so percent-encoded segments
     * cannot reach an admin route while slipping past this check.
     */
    public static function isStaffPath(string $path): bool
    {
        $normalized = strtolower(rtrim(rawurldecode($path), '/'));

        return $normalized === self::STAFF_PATH_PREFIX
            || str_starts_with($normalized, self::STAFF_PATH_PREFIX . '/');
    }

    private function resolveUser(ServerRequestInterface $request): ?AuthenticatedUser
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
            return $this->get(ResolveAuthenticatedUserHandler::class)->handle($verified);
        } catch (UserNotRegistered|IdentityConflict|IdentityAmbiguous|AuthenticationNotAllowed) {
            return null;
        }
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
