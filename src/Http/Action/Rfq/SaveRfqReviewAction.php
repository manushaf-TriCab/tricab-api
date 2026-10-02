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
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\RfqParserFailure;

/**
 * POST /api/v1/rfqs/{publicId}/review — Save Review (parity plan E5.5).
 *
 * Body `{expectedRevision}`: the revision as saved is logged as a review
 * session, and every approved line teaches the correction memory its code.
 * Answers `rfq.review.v1` ({@see \TriCab\Core\Rfq\Application\DTO\SavedReview}).
 *
 * Gated on `RFQ.PRICING.VIEW` (managers: what is learned changes every rep's
 * matching), and on the policy's `edit` for this RFQ. Any revision but the
 * saved one is 409 `RFQ_REVISION_CONFLICT`: save first.
 */
final class SaveRfqReviewAction
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
        $expected = is_array($body) ? ($body['expectedRevision'] ?? null) : null;
        if (!is_int($expected) || $expected < 1) {
            return $this->responder->error('RFQ_INPUT_INVALID', 'expectedRevision must be the saved revision to log.', 422);
        }

        $actor = $this->actors->forUser($user);
        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            $saved = $this->review->saveReview($actor, $user->emailAddress, $args['publicId'] ?? '', $expected, $id);
        } catch (RfqActionForbidden|RfqRevisionConflict|RfqStoreUnavailable|RfqParserFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
        if ($saved === null) {
            return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
        }

        return $this->responder->success($saved->toArray());
    }
}
