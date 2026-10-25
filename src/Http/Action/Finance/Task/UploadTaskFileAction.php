<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskFileService;

/** POST /api/v1/finance/task/tasks/{id}/files: adds one file (multipart part `file`) to a task, 201 with the file. Gated on `FINANCE.TASK.TASKS.UPDATE_OWN`, then the use case's own-versus-any edit rule. */
final class UploadTaskFileAction
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

            return $this->ok($this->responder, $this->service->add($actor, (string) ($args['id'] ?? ''), $name, $type, $bytes), 201);
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
