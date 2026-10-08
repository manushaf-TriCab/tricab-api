<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskAiService;

/** POST /api/v1/finance/task/ai/task-details (source route 39): a description and requirements for a task name, through the `task_details` purpose. 422 without a name; 502 "The AI draft failed: ..." when the service cannot answer. Gated on `FINANCE.TASK.TASKS.CREATE`. */
final class TaskAiDetailsAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskAiService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        try {
            return $this->ok($this->responder, $this->service->taskDetails($this->bodyOf($request)));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
