<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskMailService;

/** GET /api/v1/finance/task/tasks/{id}/mail (route 46): what was sent about a task, newest first, from the shared ledger (no bodies); 404 for a task the caller may not see. Gated on `FINANCE.TASK.TASKS.READ`. */
final class ListTaskMailAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskMailService $service,
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
            return $this->ok($this->responder, $this->service->history($actor, $id));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
