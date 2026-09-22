<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Onboarding\Application\Service\ListOnboardingPeopleService;

/**
 * GET /api/v1/admin/onboarding/people — the HR roster (4.2).
 */
final class GetOnboardingPeopleAction
{
    public function __construct(
        private readonly ListOnboardingPeopleService $people,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, function (AuthenticatedUser $user) use ($request): ResponseInterface {
            unset($user);
            $query = QueryParams::normalize($request->getQueryParams());
            $result = $this->people->list(
                QueryParams::int($query, 'page', 1),
                QueryParams::int($query, 'pageSize', 25),
                QueryParams::stringOrNull($query, 'search'),
            );

            return $this->responder->success([
                'items' => array_map(static fn (OnboardingPersonSummary $p) => $p->toArray(), $result['items']),
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
