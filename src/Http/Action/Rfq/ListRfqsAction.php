<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\DTO\RfqQueueQuery;
use TriCab\Core\Rfq\Application\Service\ReadRfqQueueService;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;

/**
 * GET /api/v1/rfqs — the queue, filtered on the server. Contract `rfq.queue.v1`.
 *
 * Filters narrow what the caller may already see; nothing in the query string
 * widens it. Gated on `RFQ.DRAFT.READ`.
 */
final class ListRfqsAction
{
    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly ReadRfqQueueService $queue,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        try {
            $query = RfqQueueQuery::fromQueryParams($request->getQueryParams());
        } catch (InvalidRfqValue $invalid) {
            return $this->responder->error('RFQ_QUERY_INVALID', $invalid->getMessage(), 400);
        }

        $page = $this->queue->page($this->actors->forUser($user), $query);

        return $this->responder->success($page->toArray());
    }
}
