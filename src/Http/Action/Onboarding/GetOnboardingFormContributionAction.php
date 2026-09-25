<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Onboarding\Application\Service\ContributeToFormService;

/**
 * GET /api/v1/admin/onboarding/queue/{assignmentPublicId}/contribution (5.4): the form, and the parts HR and the manager have filled, for HR to fill its own.
 */
final class GetOnboardingFormContributionAction
{
    public function __construct(
        private readonly ContributeToFormService $contributions,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $arguments
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $arguments = []): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, function () use ($arguments): ResponseInterface {
            $view = $this->contributions->viewAsHr($arguments['assignmentPublicId'] ?? '');

            return $this->responder->success($view);
        });
    }

}
