<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\ReadOnboardingItemService;

/**
 * GET /api/v1/onboarding/{training|policies|forms}/{assignmentPublicId}
 *
 * One action for the three item types, because they differ only in which method
 * of {@see ReadOnboardingItemService} they call and share every property that
 * matters: the person is the session's, the leaver read window applies, and a
 * miss is the same 404 whatever the reason. Three near-identical classes would
 * be three places for one of those to drift.
 *
 * Which type is being asked for comes from the route, through the `itemType`
 * argument the route registration supplies — not from the request, and not
 * guessed from the id. A client cannot ask for a policy "as training" and see
 * what happens.
 */
final class GetOnboardingItemAction
{
    public function __construct(
        private readonly ReadOnboardingItemService $items,
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
            $assignmentPublicId = $arguments['assignmentPublicId'] ?? '';
            $itemType = $arguments['itemType'] ?? '';

            $detail = match ($itemType) {
                'policy' => $this->items->policy($user->userId, $assignmentPublicId)->toArray(),
                'form' => $this->items->form($user->userId, $assignmentPublicId)->toArray(),
                default => $this->items->training($user->userId, $assignmentPublicId)->toArray(),
            };

            return $this->responder->success($detail);
        });
    }
}
