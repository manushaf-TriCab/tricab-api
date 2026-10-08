<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Service\TaskMembersService;

/**
 * PATCH /api/v1/finance/task/members/{userId}/profile (source route 7): the job title, at
 * most 200 characters. The target must be a member (404 otherwise). Gated on
 * `FINANCE.TASK.SETUP.MANAGE`.
 */
final class PatchTaskMemberProfileAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskMembersService $members,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);
        $id = $args['userId'] ?? '';
        if (!ctype_digit($id) || strlen($id) > 18) {
            return $this->failure($this->responder, new TaskNotFound('Member not found'));
        }
        try {
            return $this->responder->success($this->members->setJobTitle((int) $id, $this->bodyOf($request)['job_title'] ?? ''));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
