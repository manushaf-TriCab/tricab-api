<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\DTO\RfqPerson;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqWorkflowService;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;

/**
 * GET /api/v1/rfqs/lookups/people?q= — the owner picker (parity plan E6.2):
 * `{items: [{id, name}]}`, up to 10 active users whose name contains `q` or
 * whose email starts with it. Gated on `RFQ.DRAFT.ASSIGN`. The email is
 * matched, never returned.
 */
final class SearchRfqPeopleAction
{
    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqWorkflowService $workflow,
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
        $q = $request->getQueryParams()['q'] ?? '';
        try {
            $people = $this->workflow->people($this->actors->forUser($user), is_string($q) ? $q : '');
        } catch (RfqActionForbidden $failure) {
            return $this->responder->error('RFQ_ACTION_FORBIDDEN', $failure->getMessage(), 403);
        }

        return $this->responder->success([
            'items' => array_map(static fn (RfqPerson $p): array => $p->toOption(), $people),
        ]);
    }
}
