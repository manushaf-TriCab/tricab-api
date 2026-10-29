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
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\ActingAdminAttribute;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsActionWrite;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsResolution;
use TriCab\Core\Identity\Application\DTO\ActAs\ActingAdmin;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\ActAsSessionInvalid;
use TriCab\Core\Identity\Application\Exception\IdentityAmbiguous;
use TriCab\Core\Identity\Application\Exception\IdentityConflict;
use TriCab\Core\Identity\Application\Exception\UserNotRegistered;
use TriCab\Core\Identity\Application\Handler\ResolveActAsSessionHandler;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Contract\ActAsSessionRepository;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\Service\StaffEligibilityPolicy;
use TriCab\Core\Shared\Time\Clock;

/**
 * Lets a platform administrator use the API as another user while an act-as
 * session is open (X-Act-As-Session header).
 *
 * Registered as global middleware, inside routing and outside every route
 * middleware, so that by the time RequirePermissionMiddleware runs the target
 * user is already attached and the target permissions are the ones checked.
 * Sign-in assurance still comes from the real VerifiedIdentity, which is the
 * administrator own.
 *
 * Every state-changing request made while switched writes one row to the act-as
 * action log. A failure to write it is logged and never fails the request.
 */
final class ActAsMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-Act-As-Session';

    /** The act-as endpoints ignore the header so an administrator can always exit. */
    private const ACT_AS_PATH = '/api/v1/admin/act-as';

    private const LOGGED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $sessionId = trim($request->getHeaderLine(self::HEADER));
        if ($sessionId === '' || $this->isActAsPath($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        // Unauthenticated requests are left to fail downstream as they always did.
        if (!$request->getAttribute(VerifiedIdentityAttribute::KEY) instanceof VerifiedIdentity) {
            return $handler->handle($request);
        }

        // The caller is judged here and the target by the staff route guard, so
        // a session opened before the administrator gained a customer role
        // stops working on the next request.
        $caller = $this->resolveCaller($request);
        if (
            $caller === null
            || !StaffEligibilityPolicy::isEligible($caller->roles)
            || !RequirePlatformAdministratorMiddleware::permits($caller, $request)
        ) {
            return $this->invalid();
        }

        try {
            $resolution = $this->get(ResolveActAsSessionHandler::class)->handle($caller, $sessionId);
        } catch (ActAsSessionInvalid $exception) {
            error_log($exception->getMessage());

            return $this->invalid();
        }

        $started = microtime(true);
        $logged = in_array(strtoupper($request->getMethod()), self::LOGGED_METHODS, true);

        try {
            $response = $handler->handle(
                $request
                    ->withAttribute(AuthenticatedUserAttribute::KEY, $resolution->target)
                    ->withAttribute(ActingAdminAttribute::KEY, new ActingAdmin(
                        $caller,
                        $resolution->session->id,
                        $resolution->session->expiresAt,
                    )),
            );
        } catch (Throwable $exception) {
            // A write that throws may still have changed something, so it is
            // logged as a 500 before the error middleware turns it into one.
            if ($logged) {
                $this->logAction($request, 500, $resolution, self::elapsedMs($started));
            }

            throw $exception;
        }

        if ($logged) {
            $this->logAction($request, $response->getStatusCode(), $resolution, self::elapsedMs($started));
        }

        return $response;
    }

    private static function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    private function isActAsPath(string $path): bool
    {
        return $path === self::ACT_AS_PATH || str_starts_with($path, self::ACT_AS_PATH . '/');
    }

    private function resolveCaller(ServerRequestInterface $request): ?AuthenticatedUser
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

    private function logAction(
        ServerRequestInterface $request,
        int $statusCode,
        ActAsResolution $resolution,
        int $durationMs,
    ): void {
        try {
            $route = $request->getAttribute(RouteContext::ROUTE);

            $this->get(ActAsSessionRepository::class)->appendAction(new ActAsActionWrite(
                sessionId: $resolution->session->id,
                adminUserId: $resolution->session->adminUserId,
                targetUserId: $resolution->session->targetUserId,
                method: strtoupper($request->getMethod()),
                routePattern: $route instanceof RouteInterface ? $route->getPattern() : '',
                path: $request->getUri()->getPath(),
                statusCode: $statusCode,
                durationMs: $durationMs,
                requestId: AdminActorFactory::correlationId($request),
                occurredAt: $this->get(Clock::class)->now(),
            ));
        } catch (Throwable $exception) {
            error_log('Act-as action could not be logged: ' . $exception::class . ': ' . $exception->getMessage());
        }
    }

    private function invalid(): ResponseInterface
    {
        return $this->get(JsonResponder::class)->error(
            'act_as_invalid',
            'The act-as session is not valid.',
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
