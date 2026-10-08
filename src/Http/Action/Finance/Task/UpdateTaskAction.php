<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskService;

/** PATCH /api/v1/finance/task/tasks/{id} (route 13). Gated on the weaker `FINANCE.TASK.TASKS.UPDATE_OWN` (decision D14); the use case lets `UPDATE` change any task and `UPDATE_OWN` only the caller's own. 409 with `blockers` on a status change while a dependency is unfinished. */
final class UpdateTaskAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskService $service,
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
        $id = (string) ($args['id'] ?? '');
        try {
            return $this->ok($this->responder, $this->service->update($actor, $id, $this->bodyOf($request)));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
