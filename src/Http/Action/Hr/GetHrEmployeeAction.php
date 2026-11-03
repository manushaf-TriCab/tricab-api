<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Hr;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Hr\Employees\Directory\EmployeeDirectoryService;
use TriCab\Api\Hr\Employees\Directory\EmployeeNotFound;
use TriCab\Api\Hr\Employees\Directory\HrPermissions;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkService;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Response\JsonResponder;

/**
 * GET /api/v1/hr/employees/{code}: one employee with the manager chain and
 * direct reports. Gated on `HR.EMPLOYEES.READ`; the `restricted` block is added
 * (and the read audited) only for a caller who also holds
 * `HR.EMPLOYEES.RESTRICTED.READ`, and is otherwise absent, not null.
 *
 * `onboarding` (phase 5) is the linked onboarding record and its completion, or
 * null when none is linked. It is absent for a caller without
 * `ONBOARDING.PEOPLE.MANAGE`, who could not open that record anyway.
 */
final class GetHrEmployeeAction
{
    use HrActionSupport;

    public function __construct(
        private readonly EmployeeDirectoryService $employees,
        private readonly OnboardingLinkService $onboardingLinks,
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
            $detail = $this->employees->detail(trim($arguments['code'] ?? ''), $actor);
            if ($this->holds($user, OnboardingLinkService::PERMISSION)) {
                $code = $detail['code'] ?? null;
                $detail['onboarding'] = is_string($code) ? $this->onboardingLinks->forEmployee($code) : null;
            }

            return $this->responder->success($detail);
        } catch (EmployeeNotFound $missing) {
            return $this->responder->error('HR_EMPLOYEE_NOT_FOUND', $missing->getMessage(), 404);
        }
    }
}
