<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Hr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Hr\Employees\Directory\EmployeeDirectoryService;
use TriCab\Api\Hr\Employees\Directory\EmployeeNotFound;
use TriCab\Api\Hr\Employees\Directory\HrPermissions;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * GET /api/v1/hr/employees/{code}: one employee with the manager chain and
 * direct reports. Gated on `HR.EMPLOYEES.READ`; the `restricted` block is added
 * (and the read audited) only for a caller who also holds
 * `HR.EMPLOYEES.RESTRICTED.READ`, and is otherwise absent, not null.
 */
final class GetHrEmployeeAction
{
    use HrActionSupport;

    public function __construct(
        private readonly EmployeeDirectoryService $employees,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $arguments = [],
    ): ResponseInterface {
        unset($response);
        $user = $this->user($request);
        if ($user === null) {
            return $this->unauthenticated($this->responder);
        }

        $actor = $this->holds($user, HrPermissions::EMPLOYEES_RESTRICTED_READ)
            ? AdminActorFactory::fromRequest($request)
            : null;

        try {
            return $this->responder->success($this->employees->detail(trim($arguments['code'] ?? ''), $actor));
        } catch (EmployeeNotFound $missing) {
            return $this->responder->error('HR_EMPLOYEE_NOT_FOUND', $missing->getMessage(), 404);
        }
    }
}
