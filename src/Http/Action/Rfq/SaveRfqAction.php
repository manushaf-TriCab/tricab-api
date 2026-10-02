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
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Storage\QuoteHeaderEdit;

/**
 * PATCH /api/v1/rfqs/{publicId} — save a review (plan 3.7).
 *
 * Body `{expectedRevision, header?, lines: [{lineUid, comment, approved?}]}`:
 * the Quote Details as filled in (left out, the stored header is kept), and
 * every line to keep with what the person decided. A line left out is
 * deleted. Answers with the RFQ as `GET` would (`rfq.detail.v1`), at its new
 * revision.
 *
 * Gated on `RFQ.DRAFT.EDIT`, and on the policy's `edit` for this RFQ. A save
 * based on an old revision is 409 `RFQ_REVISION_CONFLICT` with the current
 * revision, and nothing of it is written.
 */
final class SaveRfqAction
{
    use RfqWriteFailures;

    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqReviewService $review,
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
        $input = self::input($request->getParsedBody());
        if (is_string($input)) {
            return $this->responder->error('RFQ_INPUT_INVALID', $input, 422);
        }

        $actor = $this->actors->forUser($user);
        $publicId = $args['publicId'] ?? '';
        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            $header = $input['header'] === null ? null : QuoteHeaderEdit::fromInput($input['header']);
            $revision = $this->review->save($actor, $user->emailAddress, $publicId, $input['expectedRevision'], $input['lines'], $id, $header);
        } catch (InvalidRfqValue|RfqActionForbidden|RfqStoreUnavailable|RfqRevisionConflict|RfqDraftNotEditable|RfqLookupUnavailable $failure) {
            return $this->failure($this->responder, $failure);
        }
        $detail = $revision === null ? null : $this->queue->detail($actor, $publicId);
        if ($detail === null) {
            return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
        }

        return $this->responder->success($detail->toArray($actor->can(\TriCab\Core\Rfq\Module::PERMISSION_PRICING_VIEW)));
    }

    /**
     * @return array{expectedRevision: int, header: mixed, lines: list<array{lineUid: string, approved: ?bool, comment: string}>}|string
     *         the save, or what is wrong with it
     */
    private static function input(mixed $body): array|string
    {
        if (!is_array($body)) {
            return 'Send the save as a JSON object.';
        }
        $expected = $body['expectedRevision'] ?? null;
        if (!is_int($expected) || $expected < 1) {
            return 'expectedRevision must be the revision this save was based on.';
        }
        $lines = self::lineDecisions($body['lines'] ?? null);
        if (is_string($lines)) {
            return $lines;
        }

        return ['expectedRevision' => $expected, 'header' => $body['header'] ?? null, 'lines' => $lines];
    }
}
