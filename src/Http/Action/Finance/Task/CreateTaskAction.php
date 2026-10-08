<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskService;

/** POST /api/v1/finance/task/tasks (route 12): 201 with the task and its first history row. Gated on `FINANCE.TASK.TASKS.CREATE`. */
final class CreateTaskAction
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
        unset($response, $args);
        $actor = $this->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->create($actor, $this->bodyOf($request)), 201);
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
