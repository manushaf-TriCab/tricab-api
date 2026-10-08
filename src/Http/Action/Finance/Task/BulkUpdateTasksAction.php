<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskService;

/** POST /api/v1/finance/task/tasks/bulk (route 14): `{updates: [{id, ...fields}]}` or a bare list, at most 200, applied together or not at all. Gated on `FINANCE.TASK.TASKS.UPDATE_OWN` (decision D14), each row checked as an update. */
final class BulkUpdateTasksAction
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
            return $this->ok($this->responder, $this->service->bulkUpdate($actor, $this->bulkRows($request)));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }

    /** The rows: `{updates: [...]}` or a bare list, as the source read them. */
    private function bulkRows(ServerRequestInterface $request): mixed
    {
        $body = $this->rawBodyOf($request);
        if (is_array($body) && !array_is_list($body)) {
            return $body['updates'] ?? null;
        }

        return $body;
    }
}
