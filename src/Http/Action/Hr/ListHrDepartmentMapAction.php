<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Hr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Hr\Employees\Directory\DepartmentMapService;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * GET /api/v1/hr/department-map: every raw department and factory value seen in
 * payroll, mapped or not, with employee counts. Gated on `HR.EMPLOYEES.READ`.
 */
final class ListHrDepartmentMapAction
{
    use HrActionSupport;

    public function __construct(
        private readonly DepartmentMapService $map,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);
        if ($this->user($request) === null) {
            return $this->unauthenticated($this->responder);
        }

        return $this->responder->success($this->map->list());
    }
}
