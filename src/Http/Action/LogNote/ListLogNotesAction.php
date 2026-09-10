<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\LogNote;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use TriCab\Api\Http\Auth\LogNoteErrorMapper;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Request\LogNote\LogNoteListQuery;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\LogNote\Application\Service\ListLogNotesService;

final class ListLogNotesAction
{
    public function __construct(
        private readonly ListLogNotesService $listLogNotes,
        private readonly JsonResponder $responder,
        private readonly LogNoteErrorMapper $errors,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        try {
            $criteria = LogNoteListQuery::fromQueryParams(QueryParams::normalize($request->getQueryParams()));
            $result = $this->listLogNotes->execute($criteria);

            return $this->responder->success(LogNoteDtoSerializer::listResult($result));
        } catch (Throwable $exception) {
            return $this->errors->map($exception);
        }
    }
}
