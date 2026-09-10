<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\LogNote;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\LogNoteErrorMapper;
use TriCab\Api\Http\Request\Admin\RouteParams;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\LogNote\Application\Service\GetLogNoteService;

final class GetLogNoteAction
{
    public function __construct(
        private readonly GetLogNoteService $getLogNote,
        private readonly JsonResponder $responder,
        private readonly LogNoteErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $publicId = RouteParams::require($request, 'publicId');
            $details = $this->getLogNote->execute($publicId);

            return $this->responder->success(LogNoteDtoSerializer::details($details));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
