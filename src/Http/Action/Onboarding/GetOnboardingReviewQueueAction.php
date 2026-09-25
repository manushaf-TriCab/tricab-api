<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewQueueEntry;
use TriCab\Core\Onboarding\Application\Service\ListReviewQueueService;

/**
 * GET /api/v1/admin/onboarding/queue — the reviewer queue (4.3).
 */
final class GetOnboardingReviewQueueAction
{
    public function __construct(
        private readonly ListReviewQueueService $queue,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, function (AuthenticatedUser $user) use ($request): ResponseInterface {
            $query = QueryParams::normalize($request->getQueryParams());
            $result = $this->queue->list(
                QueryParams::int($query, 'page', 1),
                QueryParams::int($query, 'pageSize', 25),
            );
            $viewer = $user->userId;

            return $this->responder->success([
                'items' => array_map(static fn (ReviewQueueEntry $e) => $e->toArray($viewer), $result['items']),
                'pagination' => [
                    'page' => $result['page'],
                    'pageSize' => $result['pageSize'],
                    'totalItems' => $result['total'],
                    'totalPages' => (int) max(1, ceil($result['total'] / $result['pageSize'])),
                ],
            ]);
        });
    }
}
