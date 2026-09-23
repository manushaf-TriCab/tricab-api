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
 * POST /api/v1/admin/onboarding/content/{type}/{itemPublicId}/versions/{versionPublicId}/publish (4.4).
 *
 * Body: `{ "rowVersion", "supersedesWorkInProgress" }` — the flag is required,
 * not defaulted: it decides whether people part-way through the old version are
 * moved, and the author has to have said which.
 */
final class PostOnboardingContentPublishAction
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
            $supersedes = $body['supersedesWorkInProgress'] ?? null;
            if (!is_bool($supersedes)) {
                throw \TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue::for('supersedesWorkInProgress', '(missing — say true or false)');
            }
            $result = $this->authoring->publish(
                AdminActorFactory::fromRequest($request),
                OnboardingContentRequest::itemType($arguments),
                $arguments['itemPublicId'] ?? '',
                $arguments['versionPublicId'] ?? '',
                OnboardingContentRequest::rowVersion($body),
                $supersedes,
            );
            $item = $this->content->requireItem(OnboardingContentRequest::itemType($arguments), $arguments['itemPublicId'] ?? '');

            return $this->responder->success(OnboardingContentRequest::version($item, $result['version']) + [
                'result' => ['retiredVersionNumber' => $result['retiredVersionNumber'], 'reissuedCount' => $result['reissuedCount']],
            ]);
        });
    }
}
