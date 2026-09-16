<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\LogNote;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Auth\AdminMutationTransaction;
use TriCab\Api\Http\Auth\LogNoteErrorMapper;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Request\LogNote\CreateLogNoteRequest;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\LogNote\Application\DTO\LogNoteDetails;
use TriCab\Core\LogNote\Application\Service\CreateLogNoteService;

final class CreateLogNoteAction
{
    public function __construct(
        private readonly CreateLogNoteService $createLogNote,
        private readonly AdminMutationTransaction $transaction,
        private readonly JsonResponder $responder,
        private readonly LogNoteErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $payload = CreateLogNoteRequest::fromArray($body);
            $actor = AdminActorFactory::fromRequest($request);

            /** @var LogNoteDetails $created */
            $created = $this->transaction->run(
                fn (): LogNoteDetails => $this->createLogNote->execute($actor, $payload),
            );

            return $this->responder->success(LogNoteDtoSerializer::details($created), 201);
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
