<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Domain\ValueObject\AuthenticationAssurance;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Domain\Service\ProtectedIdentityCatalog;

final class RequirePlatformAdministratorMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly JsonResponder $responder) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser || !in_array(ProtectedIdentityCatalog::ROLE_ADMINISTRATOR, $user->roles, true)) {
            return $this->responder->error('PLATFORM_ADMINISTRATOR_REQUIRED', 'Platform administrator role is required.', 403);
        }
        $verified = $request->getAttribute(VerifiedIdentityAttribute::KEY);
        $assurance = $verified instanceof VerifiedIdentity
            ? AuthenticationAssurance::forProvider($verified->provider)
            : AuthenticationAssurance::low();
        if (!$assurance->satisfies(AuthenticationAssurance::substantial())) {
            return $this->responder->error(
                'INSUFFICIENT_AUTHENTICATION_ASSURANCE',
                'This action requires a passkey or Microsoft sign-in.',
                403,
            );
        }
        return $handler->handle($request);
    }
}
