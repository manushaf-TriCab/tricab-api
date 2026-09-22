<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\BuildOnboardingSelfSummaryService;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed;

/**
 * GET /api/v1/onboarding/me — the signed-in person's own onboarding work.
 *
 * There is deliberately no trainee identifier in the path, in the query or in
 * the body. Whose record this is follows from the authenticated session and
 * nothing else (work order §4.3): the id comes off the request attribute the
 * permission middleware attached, goes straight into the service, and the
 * response carries no subject at all. Adding a parameter here would be the bug
 * that invariant exists to prevent, and there would then be nothing between the
 * parameter and the database.
 *
 * Access follows the permission, never the session type. A pre-start candidate
 * on a guest OTP session and an employee signed in with Microsoft reach this by
 * the same `ONBOARDING.SELF.READ` check, and resolve to the same person because
 * Phase P's `IdentityResolutionService` maps both providers onto one
 * `identity.Users.UserId` (work order §4.15, Phase 3.1 and 3.2).
 */
final class GetOnboardingSelfSummaryAction
{
    public function __construct(
        private readonly BuildOnboardingSelfSummaryService $summaries,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);

        // The gate runs before this action and attaches the user, so reaching
        // here without one is a wiring fault rather than an anonymous request.
        // Answering 401 rather than dereferencing null keeps that fault from
        // reading as a 500 to whoever is looking at it.
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        try {
            $summary = $this->summaries->forUser($user->userId);
        } catch (OnboardingAccessWindowClosed $closed) {
            return $this->responder->error('ONBOARDING_ACCESS_WINDOW_CLOSED', $closed->getMessage(), 403);
        }

        return $this->responder->success($summary->toArray());
    }
}
