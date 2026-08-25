<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Passkey;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\PasskeyAuthErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\Service\ManageUserPasskeysService;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class RenamePasskeyAction
{
    public function __construct(
        private readonly ManageUserPasskeysService $service,
        private readonly JsonResponder $responder,
        private readonly PasskeyAuthErrorMapper $errors,
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
            $body = JsonRequestBody::requireObject($request);
            $label = is_string($body['deviceLabel'] ?? null) ? $body['deviceLabel'] : 'Passkey';
            $publicId = $args['publicId'] ?? '';
            $this->service->rename(UserId::fromInt($user->userId), $publicId, $label);

            return $this->responder->success(['status' => 'renamed']);
        } catch (Throwable $exception) {
            return $this->errors->map($exception, '/api/v1/auth/passkeys', 'PASSKEY_REVOKED');
        }
    }
}
