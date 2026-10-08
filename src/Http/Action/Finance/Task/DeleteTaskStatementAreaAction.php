<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskSetupService;

/** DELETE /api/v1/finance/task/statement-areas/{id} (route 34): 409 while a live task uses the name. Gated on `FINANCE.TASK.SETUP.MANAGE`. */
final class DeleteTaskStatementAreaAction
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
        unset($response);
        $id = (string) ($args['id'] ?? '');
        try {
            return $this->ok($this->responder, $this->service->deleteArea($id));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
