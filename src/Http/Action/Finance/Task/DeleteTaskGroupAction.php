<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskBoardService;

/** DELETE /api/v1/finance/task/groups/{id}: the group goes, its tasks stay in the project ungrouped (13 Oct 2026). Gated on `FINANCE.TASK.SETUP.MANAGE`. */
final class DeleteTaskGroupAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskBoardService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $request);
        try {
            return $this->ok($this->responder, $this->service->deleteGroup((string) ($args['id'] ?? '')));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
