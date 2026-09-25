<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\InviteOnboardingPersonService;

/**
 * POST /api/v1/admin/onboarding/people/{personPublicId}/invitation (4.1).
 *
 * No body. Sends the invitation email — which carries no code — and answers
 * with the person, `invitedAtUtc` now set. A second send within a minute is
 * refused (`ONBOARDING_INVITATION_TOO_SOON`): that is a double click.
 */
final class PostOnboardingPersonInvitationAction
{
    public function __construct(
        private readonly InviteOnboardingPersonService $invitations,
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

            return $this->responder->success($this->invitations->invite(
                AdminActorFactory::fromRequest($request),
                $arguments['personPublicId'] ?? '',
            )->toArray());
        });
    }
}
