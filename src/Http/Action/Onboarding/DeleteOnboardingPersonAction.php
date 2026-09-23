<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\DeleteOnboardingPersonService;

/**
 * DELETE /api/v1/admin/onboarding/people/{personPublicId}.
 *
 * Body: `{ "rowVersion" }`. Deletes an induction record nothing is recorded
 * against, and disables its account if onboarding made it. Responds
 * `{ deleted, accountDisabled }`; with recorded work it is 409
 * `ONBOARDING_PERSON_HAS_RECORDED_WORK`, and the remedy is a last day.
 */
final class DeleteOnboardingPersonAction
{
    public function __construct(
        private readonly DeleteOnboardingPersonService $people,
        private readonly OnboardingSelfServiceResponder $responder,
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

        return $this->responder->run($request, function (AuthenticatedUser $user) use ($request, $arguments): ResponseInterface {
            unset($user);
            $result = $this->people->delete(
                AdminActorFactory::fromRequest($request),
                $arguments['personPublicId'] ?? '',
                OnboardingContentRequest::rowVersion(OnboardingSelfServiceResponder::body($request)),
            );

            return $this->responder->success(['deleted' => true] + $result);
        });
    }
}
