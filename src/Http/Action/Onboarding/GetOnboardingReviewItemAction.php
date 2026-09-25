<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\Service\ReadReviewItemService;

/**
 * GET /api/v1/admin/onboarding/queue/{assignmentPublicId} — one item as a
 * reviewer opens it (4.3). Readable after it has left the queue, so the page
 * can show what was decided.
 */
final class GetOnboardingReviewItemAction
{
    public function __construct(
        private readonly ReadReviewItemService $items,
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
            return $this->responder->success(
                $this->items->read($arguments['assignmentPublicId'] ?? '')->toArray($user->userId),
            );
        });
    }
}
