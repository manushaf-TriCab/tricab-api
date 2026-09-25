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
 * POST /api/v1/admin/onboarding/content/{type}/{itemPublicId}/versions (4.4).
 *
 * Starts the next version as a copy of the live one. No body. 201, or 409
 * `ONBOARDING_CONTENT_DRAFT_EXISTS`.
 */
final class PostOnboardingContentDraftAction
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
            $version = $this->authoring->createDraft(
                AdminActorFactory::fromRequest($request),
                OnboardingContentRequest::itemType($arguments),
                $arguments['itemPublicId'] ?? '',
            );
            $item = $this->content->requireItem(OnboardingContentRequest::itemType($arguments), $arguments['itemPublicId'] ?? '');

            return $this->responder->success(OnboardingContentRequest::version($item, $version), 201);
        });
    }
}
