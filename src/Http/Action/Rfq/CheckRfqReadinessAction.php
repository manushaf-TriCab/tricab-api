<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\QuoteHeaderEdit;

/**
 * Readiness of the editor's lines as they stand, saved or not (parity plan
 * E3.5): the Status pills, the reasons under each line and the sync
 * blockers. Answers `rfq.readiness.v1`. Nothing is written.
 *
 *  - `POST /api/v1/rfqs/readiness` `{parseToken, header, lines}` — an unsaved
 *    match (`parseToken` null for lines typed in). Gated on `RFQ.DRAFT.CREATE`.
 *  - `POST /api/v1/rfqs/{publicId}/readiness` `{header?, lines?}` — a draft,
 *    with the edits not yet saved; left out, as saved. Gated on
 *    `RFQ.DRAFT.READ`: a reader sees the same pills.
 *
 * The body is the save's, so the editor asks about exactly what a save
 * would write.
 */
final class CheckRfqReadinessAction
{
    use RfqWriteFailures;

    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqReviewService $review,
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
        if (!is_array($body)) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'Send the lines as a JSON object.', 422);
        }
        $publicId = $args['publicId'] ?? null;
        $lines = null;
        if ($publicId === null || array_key_exists('lines', $body)) {
            $lines = self::lineDecisions($body['lines'] ?? null);
            if (is_string($lines)) {
                return $this->responder->error('RFQ_INPUT_INVALID', $lines, 422);
            }
        }

        $actor = $this->actors->forUser($user);
        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            if ($publicId === null) {
                $token = $body['parseToken'] ?? null;
                if ($token !== null && (!is_string($token) || $token === '')) {
                    return $this->responder->error('RFQ_INPUT_INVALID', 'parseToken must be the match, or null for lines typed in.', 422);
                }
                $view = $this->review->previewNew($actor, $user->emailAddress, $token, QuoteHeaderEdit::fromInput($body['header'] ?? null), $lines ?? [], $id);
            } else {
                $header = array_key_exists('header', $body) ? QuoteHeaderEdit::fromInput($body['header']) : null;
                $view = $this->review->previewDraft($actor, $user->emailAddress, $publicId, $header, $lines, $id);
                if ($view === null) {
                    return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
                }
            }
        } catch (InvalidRfqValue|RfqActionForbidden|RfqStoreUnavailable|RfqParserFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success($view->toArray());
    }
}
