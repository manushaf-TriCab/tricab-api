<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Onboarding;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Auth\AdminActorFactory;
use TriCab\Api\Http\Request\Admin\QueryParams;
use TriCab\Core\Onboarding\Application\Service\ReadCompletionFeedService;

/**
 * GET /api/v1/integrations/onboarding/completions?cursor=&limit= (Phase 6).
 *
 * The completion feed for Skill Matrix, which polls it from VM 109. Defined
 * and tested, not deployed: nothing holds `ONBOARDING.COMPLETIONS.READ` yet, and
 * the service identity that will is an owner decision. Contract:
 * `docs/onboarding-completion-feed.md`.
 */
final class GetOnboardingCompletionFeedAction
{
    public function __construct(
        private readonly ReadCompletionFeedService $feed,
        private readonly OnboardingSelfServiceResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        return $this->responder->run($request, function () use ($request): ResponseInterface {
            $query = QueryParams::normalize($request->getQueryParams());

            return $this->responder->success($this->feed->read(
                AdminActorFactory::fromRequest($request),
                QueryParams::stringOrNull($query, 'cursor'),
                QueryParams::intOrNull($query, 'limit'),
            )->toArray());
        });
    }
}
