<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Service\TaskMembersService;

/** GET /api/v1/finance/task/members (source route 6). Gated on `FINANCE.TASK.TASKS.READ`. */
final class ListTaskMembersAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskMembersService $members,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response, $request);

        return $this->ok($this->responder, $this->members->list());
    }
}
