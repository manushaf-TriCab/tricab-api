<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\QuoteHeaderEdit;

/**
 * POST /api/v1/rfqs — Save as Draft (parity plan E2). Body
 * `{parseToken, header, lines}`: the sealed parse from
 * `POST /api/v1/rfqs/parse` (null for lines typed in with no match), the
 * Quote Details as filled in, and every line to keep, in order, with lines
 * added in the grid. Answers 201 with `rfq.created.v1`.
 *
 * Gated on `RFQ.DRAFT.CREATE`. The rows are the ones the seal holds, never
 * rows the browser sent; a seal that is someone else's, or over 12 hours
 * old, is 422 and creates nothing.
 */
final class CreateRfqAction
{
    use RfqWriteFailures;

    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqReviewService $review,
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
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'Send the draft as a JSON object.', 422);
        }
        $token = $body['parseToken'] ?? null;
        if ($token !== null && (!is_string($token) || $token === '')) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'parseToken must be the match Match Text returned, or null for lines typed in.', 422);
        }
        $lines = self::lineDecisions($body['lines'] ?? null);
        if (is_string($lines)) {
            return $this->responder->error('RFQ_INPUT_INVALID', $lines, 422);
        }

        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            $header = QuoteHeaderEdit::fromInput($body['header'] ?? null);
            $created = $this->review->create($this->actors->forUser($user), $user->emailAddress, $token, $header, $lines, $id);
        } catch (InvalidRfqValue|RfqActionForbidden|RfqStoreUnavailable|RfqParserFailure|RfqLookupUnavailable $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success($created->toArray(), 201);
    }
}
