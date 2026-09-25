<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\CreateOnboardingPersonService;

/**
 * POST /api/v1/admin/onboarding/people (4.1).
 *
 * Body: `{ "emailAddress", "displayName"?, "position"?, "department"?,
 * "branch"?, "startDate"? }`. `displayName` is needed only when no account
 * holds the address yet; an existing account keeps its own name.
 *
 * Sends nothing. Inviting is `POST …/people/{id}/invitation`.
 */
final class PostOnboardingPersonAction
{
    public function __construct(
        private readonly CreateOnboardingPersonService $people,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, function (AuthenticatedUser $user) use ($request): ResponseInterface {
            unset($user);
            $body = OnboardingSelfServiceResponder::body($request);

            $result = $this->people->create(
                AdminActorFactory::fromRequest($request),
                OnboardingProfileRequest::text($body, 'emailAddress') ?? '',
                OnboardingProfileRequest::text($body, 'displayName') ?? '',
                OnboardingProfileRequest::fields($body),
            );

            return $this->responder->success([
                ...$result['detail']->toArray(),
                'result' => ['accountCreated' => $result['accountCreated']],
            ], 201);
        });
    }
}
