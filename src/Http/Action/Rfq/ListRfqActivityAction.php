<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\DTO\RfqAuditEvent;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqWorkflowService;

/**
 * GET /api/v1/rfqs/{publicId}/activity — the activity timeline (parity plan
 * E6.3): `{items: [rfq.audit-event.v1]}`, newest first, at most 200. People
 * are named; no email appears.
 */
final class ListRfqActivityAction
{
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
        unset($response);

        $user = $request->getAttribute(AuthenticatedUserAttribute::KEY);
        if (!$user instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $events = $this->workflow->activity($this->actors->forUser($user), $args['publicId'] ?? '');
        if ($events === null) {
            return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
        }

        return $this->responder->success([
            'items' => array_map(static fn (RfqAuditEvent $e): array => $e->toArray(), $events),
        ]);
    }
}
