<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;

/**
 * DELETE /api/v1/admin/onboarding/content/{type}/{itemPublicId}.
 *
 * Body: `{ "rowVersion" }`. Deletes an item nobody has ever been given, with
 * its versions. Once anybody has held a version it is 409
 * `ONBOARDING_CONTENT_IN_USE`, and the remedy is to stop offering it.
 */
final class DeleteOnboardingContentItemAction
{
    public function __construct(
        private readonly AuthorOnboardingContentService $authoring,
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
            $this->authoring->deleteItem(
                AdminActorFactory::fromRequest($request),
                OnboardingContentRequest::itemType($arguments),
                $arguments['itemPublicId'] ?? '',
                OnboardingContentRequest::rowVersion(OnboardingSelfServiceResponder::body($request)),
            );

            return $this->responder->success(['deleted' => true]);
        });
    }
}
