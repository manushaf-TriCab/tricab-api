<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\ReadOnboardingPersonService;

/**
 * GET /api/v1/admin/onboarding/people/{personPublicId} — one person's
 * allocation screen (4.2).
 */
final class GetOnboardingPersonAction
{
    public function __construct(
        private readonly ReadOnboardingPersonService $people,
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

        return $this->responder->run($request, function (AuthenticatedUser $user) use ($arguments): ResponseInterface {
            unset($user);

            return $this->responder->success($this->people->read($arguments['personPublicId'] ?? '')->toArray());
        });
    }
}
