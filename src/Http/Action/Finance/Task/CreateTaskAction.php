<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Service\TaskFileService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskDueDateRequestNotifier;
use TriCab\Core\Finance\Task\Service\TaskReviewRequestNotifier;

/** POST /api/v1/finance/task/tasks (route 12): 201 with the task and its first history row. Gated on `FINANCE.TASK.TASKS.CREATE`. */
final class CreateTaskAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskService $service,
        private readonly JsonResponder $responder,
        private readonly TaskDueDateRequestNotifier $dueDateRequests,
        private readonly TaskReviewRequestNotifier $reviewRequests,
        private readonly TaskFileService $files,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function uploadIds(array $body): array
    {
        $ids = $body['upload_ids'] ?? [];
        if (!is_array($ids)) {
            throw new TaskInvalid('upload_ids must be a list of upload ids');
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                throw new TaskInvalid('upload_ids must be a list of upload ids');
            }
            $out[$id] = $id;
        }

        return array_values($out);
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
            $body = $this->bodyOf($request);
            // Files picked before the first save (`upload_ids`, from POST /task-uploads): checked first, so a bad id
            // creates nothing, then put on the task once it exists.
            $uploadIds = $this->uploadIds($body);
            $this->files->claimable($actor, $uploadIds);
            $task = $this->service->create($actor, $body);
            $taskId = $task['id'] ?? null;
            if ($uploadIds !== [] && is_string($taskId)) {
                $this->files->attachUploads($actor, $taskId, $uploadIds);
                $task['file_count'] = count($uploadIds);
            }
            // The preparer hears when a due date is asked of them (12 Oct 2026); a failed notice never fails the save.
            $this->dueDateRequests->notify($actor, $task);
            $task = $this->reviewRequests->requestIfDue($actor, $task);

            return $this->ok($this->responder, $task, 201);
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
