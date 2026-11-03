<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\DTO\RfqAnalyticsPeriod;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqAnalyticsService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;

/**
 * GET /api/v1/rfqs/analytics — contract `rfq.analytics.v1`: the Analytics
 * tab's statistics for one window of Melbourne days (`days` = 7/30/90,
 * `from` + `to`, or `period=all`). People are named; no email appears.
 */
final class GetRfqAnalyticsAction
{
    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqAnalyticsService $analytics,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response, $args);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        try {
            $period = RfqAnalyticsPeriod::fromQueryParams($request->getQueryParams(), $this->analytics->now());
        } catch (InvalidRfqValue $invalid) {
            return $this->responder->error('RFQ_QUERY_INVALID', $invalid->getMessage(), 400);
        }

        $result = $this->analytics->analytics($this->actors->forUser($user), $period);
        if ($result === null) {
            return $this->responder->error('FORBIDDEN', 'You do not have permission to read RFQ analytics.', 403);
        }

        return $this->responder->success($result);
    }
}
