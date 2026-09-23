<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\ReadOnboardingContentService;

/**
 * GET /api/v1/admin/onboarding/content/{type}/{itemPublicId}/versions/{versionPublicId} (4.4).
 *
 * The authored body — **answer key included**. Gated on
 * `ONBOARDING.CONTENT.MANAGE` and served nowhere else.
 */
final class GetOnboardingContentVersionAction
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
            unset($request);
            $found = $this->content->version(
                OnboardingContentRequest::itemType($arguments),
                $arguments['itemPublicId'] ?? '',
                $arguments['versionPublicId'] ?? '',
            );

            return $this->responder->success(OnboardingContentRequest::version($found['item'], $found['version']));
        });
    }
}
