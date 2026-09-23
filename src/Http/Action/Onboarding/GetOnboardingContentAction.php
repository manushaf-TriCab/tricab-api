<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\ReadOnboardingContentService;

/**
 * GET /api/v1/admin/onboarding/content (4.4).
 *
 * Every module, policy and form, with its live and draft version numbers. No
 * version bodies, and so no answer keys.
 */
final class GetOnboardingContentAction
{
    public function __construct(
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
            unset($request, $arguments);

            return $this->responder->success([
                'items' => array_map(
                    static fn (\TriCab\Core\Onboarding\Application\DTO\Authoring\ContentItemSummary $item): array => $item->toArray(),
                    $this->content->list(),
                ),
            ]);
        });
    }
}
