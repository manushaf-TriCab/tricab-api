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
use TriCab\Core\Rfq\Application\Service\RfqReaderService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;

/**
 * POST /api/v1/rfqs/{publicId}/reads/{readId} — a person's decision on a
 * background read (`resolve_reader_job`). Body `{action: "accept"|"dismiss",
 * expectedRevision}`. Accepting splices the proposal's rows in for that file,
 * wholesale; dismissing keeps the rows the rep has, and is how a failed read
 * is acknowledged. Answers with the RFQ (`rfq.detail.v1`).
 *
 * Gated on `RFQ.DRAFT.EDIT` and the policy's `edit`. Accepting against an old
 * revision is 409 `RFQ_REVISION_CONFLICT`.
 */
final class ResolveRfqReadAction
{
    use RfqWriteFailures;

    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqReaderService $reader,
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
        $body = $request->getParsedBody();
        $action = is_array($body) ? ($body['action'] ?? null) : null;
        $expected = is_array($body) ? ($body['expectedRevision'] ?? null) : null;
        if (!is_string($action) || !($expected === null || (is_int($expected) && $expected >= 1))) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'Send {action: "accept" or "dismiss", expectedRevision}.', 422);
        }

        $actor = $this->actors->forUser($user);
        $publicId = $args['publicId'] ?? '';
        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            $revision = $this->reader->resolve($actor, $user->emailAddress, $publicId, $args['readId'] ?? '', $action, $expected, $id);
        } catch (InvalidRfqValue|RfqActionForbidden|RfqRevisionConflict|RfqDraftNotEditable $failure) {
            return $this->failure($this->responder, $failure);
        }
        $detail = $revision === null ? null : $this->queue->detail($actor, $publicId);
        if ($detail === null) {
            return $this->responder->error('RFQ_NOT_FOUND', 'RFQ or read not found.', 404);
        }

        return $this->responder->success($detail->toArray());
    }
}
