<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Onboarding\Application\Service\ReadOnboardingReportsService;

/**
 * GET /api/v1/admin/onboarding/overview — the HR dashboard's backing call.
 *
 * The same counts as the top of the reports screen (4.5), under
 * `ONBOARDING.PEOPLE.MANAGE`. Counts name nobody, and a holder of that
 * permission can read every one of them off the roster already, so sharing
 * the summary grants nothing new. The source's overview was three of these
 * numbers: trainees, work to review, help requests.
 *
 * Until 4.5 this answered empty collections, because a zero would have read
 * as "nothing to do" while the module stored nothing.
 */
final class GetOnboardingAdminOverviewAction
{
    public function __construct(
        private readonly ReadOnboardingReportsService $reports,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, fn (): ResponseInterface
            => $this->responder->success($this->reports->summary()->toArray()));
    }
}
