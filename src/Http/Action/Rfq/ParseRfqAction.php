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
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\RfqParserFailure;

/**
 * POST /api/v1/rfqs/parse — Match Text (parity plan E2.2). Body `{text}`;
 * answers 200 with `rfq.parsed.v1`: the lines and the sealed parse that
 * `POST /api/v1/rfqs` turns into a draft.
 *
 * Stores nothing, as the source's Match Text stores nothing (D3). Gated on
 * `RFQ.DRAFT.CREATE`, since its only use is to start an RFQ. A parser that is
 * down answers 503 `RFQ_PARSER_UNAVAILABLE`.
 */
final class ParseRfqAction
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
        $text = is_array($body) ? ($body['text'] ?? null) : null;
        if (!is_string($text)) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'Send the pasted lines as `text`.', 422);
        }

        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            $parsed = $this->review->parse($this->actors->forUser($user), $user->emailAddress, $text, $id);
        } catch (InvalidRfqValue|RfqActionForbidden|RfqParserFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success($parsed->toArray());
    }
}
