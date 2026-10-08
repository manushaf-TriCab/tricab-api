<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskAiService;

/** POST /api/v1/finance/task/ai/task-emails (route 41): `{taskIds}`, one to twenty; one draft per task the caller may see, to its preparer; a failure stops the batch. Gated on `FINANCE.TASK.MAIL.SEND`. */
final class TaskAiTaskEmailsAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskAiService $service,
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
        try {
            return $this->ok($this->responder, $this->service->taskEmails($actor, $this->bodyOf($request)));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
