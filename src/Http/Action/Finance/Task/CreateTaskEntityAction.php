<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskSetupService;

/** POST /api/v1/finance/task/entities (route 36): 201; 409 on a name already in use (decision Q4). Gated on `FINANCE.TASK.SETUP.MANAGE`. */
final class CreateTaskEntityAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskSetupService $service,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);
        try {
            return $this->ok($this->responder, $this->service->createEntity($this->bodyOf($request)), 201);
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
