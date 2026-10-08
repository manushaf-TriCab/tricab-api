<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskSetupService;

/** POST /api/v1/finance/task/statement-areas/bulk (route 32): `{items: [...]}` or a bare list, at most 100, skipping names that exist; 201 with the areas created. Gated on `FINANCE.TASK.SETUP.MANAGE`. */
final class BulkCreateTaskStatementAreasAction
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
            return $this->ok($this->responder, $this->service->bulkCreateAreas($this->bulkItems($request)), 201);
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }

    /** The items: `{items: [...]}` or a bare list, as the source read them. */
    private function bulkItems(ServerRequestInterface $request): mixed
    {
        $body = $this->rawBodyOf($request);
        if (is_array($body) && !array_is_list($body)) {
            return $body['items'] ?? null;
        }

        return $body;
    }
}
