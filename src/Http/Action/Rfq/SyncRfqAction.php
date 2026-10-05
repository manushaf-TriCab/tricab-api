<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\DTO\SyncOutcome;
use TriCab\Core\Rfq\Application\Service\ReadRfqQueueService;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqSyncService;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncBlocked;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncNeedsReconciliation;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncUnavailable;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\RfqParserFailure;

/**
 * Sync to 4js (parity plan E7.3): `POST /api/v1/rfqs/{publicId}/sync`
 * `{expectedRevision}`, gated on `RFQ.QUOTE.SYNC`.
 *
 * The body carries only the revision the person holds. The plan is made from
 * the **saved** revision on the server, so the editor saves first (Update Draft
 * or Save as Draft) and then asks to sync that revision; nothing the browser
 * sends is quoted.
 *
 * Answers:
 *
 *  - **200** `{result: rfq.sync-result.v1, rfq: rfq.detail.v1}` when every line landed;
 *  - **502** `RFQ_SYNC_FAILED` or `RFQ_SYNC_UNCERTAIN`, with the same `result` and `rfq`,
 *    when 4js was asked and the quote is partial, refused or unknown. The attempt is
 *    recorded either way; an uncertain one holds the RFQ for a person;
 *  - **503** `RFQ_SYNC_DISABLED` / `RFQ_SYNC_UNAVAILABLE`: switched off or nothing connected, nothing sent;
 *  - **422** `RFQ_SYNC_BLOCKED`, **409** `RFQ_SYNC_PRICES_CHANGED`, `RFQ_REVISION_CONFLICT`,
 *    `RFQ_NOT_EDITABLE` (archived, already in 4js, a sync or a read in flight) and
 *    `RFQ_SYNC_NEEDS_RECONCILIATION`: nothing was sent.
 *
 * It never answers 2xx for anything but a quote fully in 4js.
 */
final class SyncRfqAction
{
    use RfqWriteFailures;

    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqSyncService $sync,
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
        $expected = is_array($body) ? ($body['expectedRevision'] ?? null) : null;
        if (!is_int($expected) || $expected < 1) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'expectedRevision must be the saved revision to sync.', 422);
        }

        $actor = $this->actors->forUser($user);
        $publicId = $args['publicId'] ?? '';
        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            $outcome = $this->sync->sync($actor, $user->emailAddress, $publicId, $expected, $id);
        } catch (RfqSyncUnavailable|RfqActionForbidden|RfqRevisionConflict|RfqDraftNotEditable|RfqSyncBlocked|RfqSyncNeedsReconciliation|RfqStoreUnavailable|RfqParserFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
        if ($outcome === null) {
            return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
        }

        $detail = $this->queue->detail($actor, $publicId);
        $payload = [
            'result' => $outcome->toArray($actor->can(Module::PERMISSION_PRICING_VIEW)),
            'rfq' => $detail?->toArray($actor->can(Module::PERMISSION_PRICING_VIEW)),
        ];
        if ($outcome->isSuccess()) {
            return $this->responder->success($payload);
        }

        return $this->responder->payload([
            'success' => false,
            'error' => [
                'code' => $outcome->outcome === SyncOutcome::UNCERTAIN ? 'RFQ_SYNC_UNCERTAIN' : 'RFQ_SYNC_FAILED',
                'message' => $outcome->message,
            ],
            'data' => $payload,
        ], 502);
    }
}
