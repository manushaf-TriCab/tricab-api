<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Hr;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Hr\Employees\Directory\EmployeeDirectoryService;
use TriCab\Api\Hr\Employees\Directory\EmployeeListCriteria;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * GET /api/v1/hr/employees: the directory, paged. Gated on `HR.EMPLOYEES.READ`.
 * Filters: status (current|past|all, default current), department, factory,
 * manager (login), q. Never includes the restricted block.
 */
final class ListHrEmployeesAction
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

        $query = QueryParams::normalize($request->getQueryParams());
        try {
            $criteria = new EmployeeListCriteria(
                page: QueryParams::int($query, 'page', 1),
                pageSize: QueryParams::int($query, 'pageSize', 25),
                status: QueryParams::string($query, 'status', EmployeeListCriteria::STATUS_CURRENT),
                department: QueryParams::stringOrNull($query, 'department'),
                factory: QueryParams::stringOrNull($query, 'factory'),
                manager: QueryParams::stringOrNull($query, 'manager'),
                search: QueryParams::stringOrNull($query, 'q'),
                sortBy: QueryParams::string($query, 'sortBy', EmployeeListCriteria::SORT_NAME),
                sortDirection: QueryParams::string($query, 'sortDirection', 'asc'),
            );
        } catch (InvalidArgumentException $invalid) {
            return $this->responder->error('VALIDATION_FAILED', $invalid->getMessage(), 422);
        }

        return $this->responder->success($this->employees->list($criteria));
    }
}
