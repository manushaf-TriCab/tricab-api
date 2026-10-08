<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskAiService;

/** POST /api/v1/finance/task/ai/statement-areas (route 40): 18 to 24 suggested areas through `statement_areas`, the names already held removed. Gated on `FINANCE.TASK.SETUP.MANAGE`. */
final class TaskAiStatementAreasAction
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
        try {
            return $this->ok($this->responder, $this->service->statementAreas($this->bodyOf($request)));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
