<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Passkey;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Identity\Application\Service\ManageUserPasskeysService;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class ListPasskeysAction
{
    public function __construct(
        private readonly ManageUserPasskeysService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('AUTHENTICATION_REQUIRED', 'Sign in to manage passkeys.', 401);
        }

        return $this->responder->success(['items' => $this->service->list(UserId::fromInt($user->userId))]);
    }
}
