<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskService;

/** GET /api/v1/finance/task/tasks (source route 10): every live task the caller may see, or the Bin with `?deleted=1`; no row cap. A caller without `READ_ALL` sees only the tasks they prepare or review. Gated on `FINANCE.TASK.TASKS.READ`. */
final class ListTasksAction
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
            return $this->ok($this->responder, $this->service->list($actor, $this->flag($request, 'deleted')));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
