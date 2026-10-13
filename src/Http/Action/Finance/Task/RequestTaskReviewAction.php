<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskReviewRequestNotifier;
use TriCab\Core\Finance\Task\Service\TaskService;

/**
 * POST /api/v1/finance/task/tasks/{id}/request-review: Send for review (13 Oct 2026). `{status: sent|suppressed|failed}`;
 * 404 for a task the person may not see. Gated on `FINANCE.TASK.TASKS.UPDATE_OWN`; the notifier checks the rest.
 */
final class RequestTaskReviewAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskService $service,
        private readonly TaskReviewRequestNotifier $reviewRequests,
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
            $task = $this->service->get($actor, (string) ($args['id'] ?? ''));

            return $this->ok($this->responder, $this->reviewRequests->request($actor, $task));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
