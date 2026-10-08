<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskAiService;

/** POST /api/v1/finance/task/ai/reminders (route 42): one reminder for a task, to its preparer and reviewer together, each person once; with nobody assigned an empty answer and no call. Gated on `FINANCE.TASK.TASKS.READ`, as the source. */
final class TaskAiRemindersAction
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
        $actor = $this->actorOf($request);
        if ($actor === null) {
            return $this->unauthenticated($this->responder);
        }
        try {
            return $this->ok($this->responder, $this->service->reminders($actor, $this->bodyOf($request)));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
