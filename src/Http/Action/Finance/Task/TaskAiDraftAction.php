<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskAiFailed;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskDraftService;

/**
 * POST /api/v1/finance/task/ai/draft (Tri-Task "Create with AI"): `{text}` in; a draft out,
 * `{project, tasks, warnings, injection_suspected, model}`, and **nothing written**. The page creates it through
 * the ordinary project and task endpoints once the person has edited it. 422 for no text or text over 12,000
 * characters; 503 when AI is not set up; 502 "The AI draft failed: ..." when the model cannot answer.
 * Gated on `FINANCE.TASK.TASKS.CREATE`; a new project is offered only to someone holding `FINANCE.TASK.SETUP.MANAGE`.
 */
final class TaskAiDraftAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskDraftService $service,
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
            return $this->ok($this->responder, $this->service->draft($actor, $this->bodyOf($request)));
        } catch (TaskFailure $failure) {
            if ($failure instanceof TaskAiFailed) {
                // The purpose and the reason only, never the person's text.
                error_log('Tri-Task AI draft (task_create_draft) failed: ' . $failure->getMessage() . ($failure->getPrevious() !== null ? ' [' . $failure->getPrevious()::class . ']' : ''));
            }

            return $this->failure($this->responder, $failure);
        }
    }
}
