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
 * PATCH /api/v1/rfqs/{publicId}/assignment — Owner (parity plan E6.2).
 *
 * Body `{expectedRevision, assigneeId: string|null}`: the person's identity
 * PublicId, or null to unassign. Gated on `RFQ.DRAFT.ASSIGN`. An id that is
 * not an active user is 422. Answers the fresh `rfq.summary.v1`.
 */
final class AssignRfqAction
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

        return $this->write($request, $response, $this->actors, $this->responder, fn (RfqActor $actor, AuthenticatedUser $user, int $expected, array $body) => $this->workflow->assign($actor, $user->emailAddress, $publicId, $expected, self::assigneeId($body)));
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function assigneeId(array $body): ?string
    {
        if (!array_key_exists('assigneeId', $body)) {
            throw new \TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue('assigneeId is required: a person\'s id, or null to unassign.');
        }
        $id = $body['assigneeId'];
        if ($id !== null && (!is_string($id) || trim($id) === '')) {
            throw new \TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue('assigneeId must be a person\'s id, or null to unassign.');
        }

        return $id;
    }
}
