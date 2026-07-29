<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\EntraTokenValidationFailed;
use TriCab\Api\Identity\Authentication\EntraTokenValidationNotConfigured;
use TriCab\Api\Identity\Authentication\EntraTokenValidator;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;

/**
 * Resolves Bearer tokens via EntraTokenValidator when development auth did not attach an identity.
 * Does not contain business rules; only authentication adapter orchestration.
 */
final class BearerTokenAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly EntraTokenValidator $entraTokenValidator,
        private readonly JsonResponder $responder,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getAttribute(VerifiedIdentityAttribute::KEY) instanceof VerifiedIdentity) {
            return $handler->handle($request);
        }

        $header = trim($request->getHeaderLine('Authorization'));

        if ($header === '') {
            return $handler->handle($request);
        }

        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return $this->responder->error(
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        }

        try {
            $verifiedIdentity = $this->entraTokenValidator->validate($matches[1]);
        } catch (EntraTokenValidationNotConfigured $exception) {
            return $this->responder->error(
                'ENTRA_NOT_CONFIGURED',
                $exception->getMessage(),
                501,
            );
        } catch (EntraTokenValidationFailed $exception) {
            return $this->responder->error(
                'UNAUTHENTICATED',
                $exception->getMessage(),
                401,
            );
        }

        return $handler->handle(
            $request->withAttribute(VerifiedIdentityAttribute::KEY, $verifiedIdentity),
        );
    }
}
