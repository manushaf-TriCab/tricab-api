<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskFileService;

/** DELETE /api/v1/finance/task/tasks/{id}/files/{fid}: takes a file off a task. Gated on `FINANCE.TASK.TASKS.UPDATE_OWN`, then the edit rule. */
final class DeleteTaskFileAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskFileService $service,
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
            $this->service->remove($actor, (string) ($args['id'] ?? ''), (string) ($args['fid'] ?? ''));

            return $this->ok($this->responder, ['deleted' => true]);
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
