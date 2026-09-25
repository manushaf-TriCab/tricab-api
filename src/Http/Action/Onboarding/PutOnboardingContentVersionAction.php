<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;
use TriCab\Core\Onboarding\Application\Service\ReadOnboardingContentService;

/**
 * PUT /api/v1/admin/onboarding/content/{type}/{itemPublicId}/versions/{versionPublicId} (4.4).
 *
 * Body: `{ "rowVersion", …the whole body for the type }` — slides, pass mark and
 * questions; blocks, declaration and effective date; or fields. Refused once
 * published (409 `ONBOARDING_CONTENT_VERSION_PUBLISHED`).
 */
final class PutOnboardingContentVersionAction
{
    public function __construct(
        private readonly AuthorOnboardingContentService $authoring,
        private readonly ReadOnboardingContentService $content,
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
            $version = $this->authoring->saveDraft(
                AdminActorFactory::fromRequest($request),
                OnboardingContentRequest::itemType($arguments),
                $arguments['itemPublicId'] ?? '',
                $arguments['versionPublicId'] ?? '',
                OnboardingContentRequest::rowVersion($body),
                $body,
            );
            $item = $this->content->requireItem(OnboardingContentRequest::itemType($arguments), $arguments['itemPublicId'] ?? '');

            return $this->responder->success(OnboardingContentRequest::version($item, $version));
        });
    }
}
