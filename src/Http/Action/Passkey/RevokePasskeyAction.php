<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Passkey;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\GuestAuthEventLogger;
use TriCab\Api\Http\Auth\PasskeyAuthErrorMapper;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\Service\ManageUserPasskeysService;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class RevokePasskeyAction
{
    public function __construct(
        private readonly ManageUserPasskeysService $service,
        private readonly JsonResponder $responder,
        private readonly PasskeyAuthErrorMapper $errors,
        private readonly GuestAuthEventLogger $logger,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        try {
            $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
            if (!$user instanceof AuthenticatedUser) {
                return $this->responder->error('AUTHENTICATION_REQUIRED', 'Sign in to manage passkeys.', 401);
            }
            $publicId = $args['publicId'] ?? '';
            $this->service->revoke(UserId::fromInt($user->userId), $publicId);
            $this->logger->log('PASSKEY_REVOKED', ['route' => '/api/v1/auth/passkeys']);

            return $this->responder->success(['status' => 'revoked']);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, '/api/v1/auth/passkeys', 'PASSKEY_REVOKED');
        }
    }
}
