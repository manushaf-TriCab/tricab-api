<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\EntraJwtValidationDebugLogger;
use TriCab\Api\Identity\Authentication\VerifiedIdentityAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\DTO\VerifiedIdentity;
use TriCab\Core\Identity\Application\Exception\IdentityNotLinked;
use TriCab\Core\Identity\Application\Handler\ResolveAuthenticatedUserHandler;
use TriCab\Core\Identity\Domain\Exception\AuthenticationNotAllowed;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;

final class GetAuthenticatedUserAction
{
    public function __construct(
        private readonly ResolveAuthenticatedUserHandler $handler,
        private readonly JsonResponder $responder,
        private readonly EntraJwtValidationDebugLogger $debugLogger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        $verifiedIdentity = $request->getAttribute(VerifiedIdentityAttribute::KEY);

        if (!$verifiedIdentity instanceof VerifiedIdentity) {
            $this->debugLogger->log([
                'path' => $request->getUri()->getPath(),
                'authorization_header_present' => trim($request->getHeaderLine('Authorization')) !== '',
                'bearer_token_detected' => preg_match('/^Bearer\s+\S+/i', trim($request->getHeaderLine('Authorization'))) === 1,
                'token_parsed' => false,
                'jwt_validation_stage_reached' => false,
                'validation_failure_reason' => 'no VerifiedIdentity attached before /api/v1/me',
                'http_response_code' => 401,
            ]);

            return $this->responder->error(
                'UNAUTHENTICATED',
                'Authentication is required.',
                401,
            );
        }

        try {
            $user = $this->handler->handle($verifiedIdentity);
        } catch (IdentityNotLinked) {
            return $this->responder->error(
                'IDENTITY_NOT_LINKED',
                'This Microsoft identity is not linked to a TriCab user.',
                403,
            );
        } catch (AuthenticationNotAllowed $exception) {
            return $this->mapAuthenticationNotAllowed($exception);
        }

        return $this->responder->success($this->serialize($user));
    }

    /**
     * @return array{
     *     userId: int,
     *     publicId: string,
     *     emailAddress: string,
     *     displayName: string,
     *     status: string,
     *     roles: list<string>,
     *     permissions: list<string>
     * }
     */
    private function serialize(AuthenticatedUser $user): array
    {
        return [
            'userId' => $user->userId,
            'publicId' => $user->publicId,
            'emailAddress' => $user->emailAddress,
            'displayName' => $user->displayName,
            'status' => $user->status,
            'roles' => $user->roles,
            'permissions' => $user->permissions,
        ];
    }

    private function mapAuthenticationNotAllowed(AuthenticationNotAllowed $exception): ResponseInterface
    {
        $message = $exception->getMessage();

        if (str_contains($message, '"' . UserStatus::PENDING . '"')) {
            return $this->responder->error(
                'ACCOUNT_PENDING',
                'This TriCab account is pending activation.',
                403,
            );
        }

        return $this->responder->error(
            'ACCOUNT_DISABLED',
            'This TriCab account is disabled.',
            403,
        );
    }
}
