<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Onboarding\Application\Service\ContributeToFormService;

/**
 * GET /api/v1/onboarding/team/forms (5.4): forms of the starters whose profile
 * names the signed-in person as manager, that have a manager part. Empty for
 * everybody who manages nobody.
 */
final class GetOnboardingManagerFormsAction
{
    public function __construct(
        private readonly ContributeToFormService $contributions,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, function () use ($request): ResponseInterface {
            return $this->responder->success(['items' => $this->contributions->formsForManager(AdminActorFactory::fromRequest($request))]);
        });
    }
}
