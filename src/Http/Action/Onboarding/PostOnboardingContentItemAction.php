<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\AuthorOnboardingContentService;

/**
 * POST /api/v1/admin/onboarding/content/{training|policies|forms} (4.4).
 *
 * Body: `{ "slug", "title", "summary"?, "accessGroup"? }` — summary for
 * modules, access group (required) for forms. Creates the item with an empty
 * draft, version 1. 201.
 */
final class PostOnboardingContentItemAction
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
            return $this->responder->success($this->authoring->createItem(
                AdminActorFactory::fromRequest($request),
                OnboardingContentRequest::itemType($arguments),
                OnboardingSelfServiceResponder::body($request),
            )->toArray(), 201);
        });
    }
}
