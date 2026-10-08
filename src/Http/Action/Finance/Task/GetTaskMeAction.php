<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Service\TaskMembersService;

/**
 * GET /api/v1/finance/task/me (source route 5): the person's id, name, email, time zone,
 * `accessRole` and `permissions` as the portal's strings (decision D19). Gated on
 * `FINANCE.TASK.ACCESS`: a person with the app but no `TASKS.READ` gets an honest, empty
 * permission list rather than a 403, so the pages can say what is missing.
 */
final class GetTaskMeAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskMembersService $members,
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

        return $this->responder->success($this->members->me($actor));
    }
}
