<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Admin;

use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\UserManagementErrorMapper;
use TriCab\Api\Http\Request\Admin\StartActAsRequest;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\ActAs\ActAsResolution;
use TriCab\Core\Identity\Application\Handler\StartActAsHandler;

final class StartActAsAction
{
    public function __construct(
        private readonly StartActAsHandler $startActAs,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly UserManagementErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $input = StartActAsRequest::fromArray(JsonRequestBody::requireObject($request));
            $actor = AdminActorFactory::fromRequest($request);

            /** @var ActAsResolution $started */
            $started = $this->transaction->run(fn (): ActAsResolution => $this->startActAs->handle(
                actor: $actor,
                targetPublicId: $input->userPublicId,
                reason: $input->reason,
            ));

            return $this->responder->success([
                'sessionId' => $started->session->id,
                'expiresAt' => $started->session->expiresAt->setTimezone(new DateTimeZone('Australia/Melbourne'))->format(DATE_ATOM),
                'target' => [
                    'publicId' => $started->target->publicId,
                    'displayName' => $started->target->displayName,
                    'emailAddress' => $started->target->emailAddress,
                    'roles' => $started->target->roles,
                ],
            ], 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
