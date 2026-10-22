<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsSession;
use TriCab\Core\Identity\Application\Handler\EndActAsHandler;

final class EndActAsAction
{
    public function __construct(
        private readonly EndActAsHandler $endActAs,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $sessionId = RouteParams::require($request, 'id');
            $actor = AdminActorFactory::fromRequest($request);

            /** @var ActAsSession $ended */
            $ended = $this->transaction->run(fn (): ActAsSession => $this->endActAs->handle($actor, $sessionId));

            return $this->responder->success([
                'sessionId' => $ended->id,
                'endedAt' => $ended->endedAt?->format(DATE_ATOM),
                'endReason' => $ended->endReason,
            ]);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
