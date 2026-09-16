<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\LogNote;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\LogNoteErrorMapper;
use TriCab\Api\Http\Request\Admin\RequestFields;
use TriCab\Api\Http\Request\JsonRequestBody;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\LogNote\Application\Service\SummariseLogNoteService;

final class SummariseLogNoteAction
{
    public function __construct(
        private readonly SummariseLogNoteService $summariseLogNote,
        private readonly JsonResponder $responder,
        private readonly LogNoteErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $body = JsonRequestBody::requireObject($request);
            $rawText = RequestFields::requireString($body, 'text');
            $result = $this->summariseLogNote->execute($rawText);

            return $this->responder->success(LogNoteDtoSerializer::summarised($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
