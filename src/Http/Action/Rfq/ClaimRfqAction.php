<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqWorkflowService;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;

/**
 * POST /api/v1/rfqs/{publicId}/claim — Claim draft (parity plan E6.1).
 *
 * Body `{expectedRevision}`. Gated on `RFQ.DRAFT.CLAIM`; only an unassigned,
 * unarchived RFQ. Answers the fresh `rfq.summary.v1`.
 */
final class ClaimRfqAction
{
    use RfqWorkflowWrites;

    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqWorkflowService $workflow,
        private readonly JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $publicId = $args['publicId'] ?? '';

        return $this->write($request, $response, $this->actors, $this->responder, fn (RfqActor $actor, AuthenticatedUser $user, int $expected, array $body) => $this->workflow->claim($actor, $user->emailAddress, $user->displayName, $publicId, $expected));
    }
}
