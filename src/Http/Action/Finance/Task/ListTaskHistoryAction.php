<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Finance\Task;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Service\TaskService;

/** GET /api/v1/finance/task/history (route 18): every history row, no limit, `?to_status=` to narrow. Gated on `FINANCE.TASK.ANALYTICS.READ`. */
final class ListTaskHistoryAction
{
    use TaskActionSupport;

    public function __construct(
        private readonly TaskService $service,
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
            return $this->ok($this->responder, $this->service->historyByStatus($this->queryText($request, 'to_status')));
        } catch (TaskFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }

    private function queryText(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
