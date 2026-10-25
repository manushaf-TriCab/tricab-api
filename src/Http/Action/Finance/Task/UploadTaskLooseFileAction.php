<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskFileService;

/** POST /api/v1/finance/task/task-uploads: keeps a file (multipart part `file`) for a task not yet saved, 201 with the file; its `id` goes in `upload_ids` on `POST /tasks`. An upload never put on a task is swept after a day. Gated on `FINANCE.TASK.TASKS.CREATE`. */
final class UploadTaskLooseFileAction
{
    use TaskActionSupport;
    use TaskFileUploadSupport;

    public function __construct(
        private readonly TaskFileService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $actor = $this->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            [$name, $type, $bytes] = $this->uploadedFile($request);

            return $this->ok($this->responder, $this->service->uploadLoose($actor, $name, $type, $bytes), 201);
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
