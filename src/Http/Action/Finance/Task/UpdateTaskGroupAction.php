<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskBoardService;

/** PATCH /api/v1/finance/task/groups/{id}: rename, recolour or reorder a group (13 Oct 2026). Gated on `FINANCE.TASK.SETUP.MANAGE`. */
final class UpdateTaskGroupAction
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
        unset($response);
        try {
            return $this->ok($this->responder, $this->service->updateGroup((string) ($args['id'] ?? ''), $this->bodyOf($request)));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
