<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;

/**
 * PUT /api/v1/admin/onboarding/content/{type}/{itemPublicId} (4.4).
 *
 * Body: `{ "rowVersion", "title", "summary"?, "isActive" }`. The title shows on
 * every version at once; `isActive: false` stops new allocations and takes
 * nothing away from anybody who holds the item.
 */
final class PutOnboardingContentItemAction
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
            $body = OnboardingSelfServiceResponder::body($request);

            return $this->responder->success($this->authoring->updateItem(
                AdminActorFactory::fromRequest($request),
                OnboardingContentRequest::itemType($arguments),
                $arguments['itemPublicId'] ?? '',
                OnboardingContentRequest::rowVersion($body),
                $body,
            )->toArray());
        });
    }
}
