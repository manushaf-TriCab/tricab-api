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
 * PATCH /api/v1/rfqs/{publicId}/workflow — Save workflow (parity plan E6.2).
 *
 * Body `{expectedRevision, status, priority, reason}`. Gated on
 * `RFQ.DRAFT.WORKFLOW`; the transition rules are `WorkflowTransitionPolicy`'s
 * (a blocked status needs a reason; only a manager changes priority).
 * Answers the fresh `rfq.summary.v1`.
 */
final class UpdateRfqWorkflowAction
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

        return $this->write($request, $response, $this->actors, $this->responder, fn (RfqActor $actor, AuthenticatedUser $user, int $expected, array $body) => $this->workflow->updateWorkflow($actor, $user->emailAddress, $publicId, $expected, self::textField($body, 'status'), self::textField($body, 'priority'), self::textField($body, 'reason')));
    }
}
