<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Hr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Hr\Employees\Directory\EmployeeDirectoryService;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * GET /api/v1/hr/employees/sync-runs: the last 20 sync runs and a summary.
 * Gated on `HR.EMPLOYEES.SYNC`.
 */
final class ListHrSyncRunsAction
{
    use HrActionSupport;

    public function __construct(
        private readonly EmployeeDirectoryService $employees,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        if ($this->user($request) === null) {
            return $this->unauthenticated($this->responder);
        }

        return $this->responder->success($this->employees->syncRuns());
    }
}
