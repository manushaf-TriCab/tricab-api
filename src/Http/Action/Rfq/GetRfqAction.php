<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\Service\ReadRfqQueueService;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;

/**
 * GET /api/v1/rfqs/{publicId} — one RFQ. Contract `rfq.detail.v1`.
 *
 * Out of scope and nonexistent both answer 404 `RFQ_NOT_FOUND`, so an id from
 * another branch tells the caller nothing. Gated on `RFQ.DRAFT.READ`.
 */
final class GetRfqAction
{
    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly ReadRfqQueueService $queue,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }

        $detail = $this->queue->detail($this->actors->forUser($user), $args['publicId'] ?? '');
        if ($detail === null) {
            return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
        }

        return $this->responder->success($detail->toArray());
    }
}
