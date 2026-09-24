<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Onboarding\Application\Service\ContributeToFormService;

/**
 * GET /api/v1/onboarding/team/forms/{assignmentPublicId} (5.4): one of the manager's own starters' forms. Anybody but the named manager gets 404.
 */
final class GetOnboardingManagerFormAction
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

        return $this->responder->run($request, function () use ($request, $arguments): ResponseInterface {
            $view = $this->contributions->viewAsManager(AdminActorFactory::fromRequest($request), $arguments['assignmentPublicId'] ?? '');

            return $this->responder->success($view);
        });
    }

}
