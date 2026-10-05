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
 * Match Accessories (parity plan E7.2): the accessories the engine proposes
 * for the editor's cable lines, to be reviewed and added one by one. Nothing
 * is stored and nothing is added by asking. Answers `rfq.accessory-candidates.v1`.
 *
 *  - `POST /api/v1/rfqs/accessories` `{parseToken?, header, lines, lineUids?}`: an unsaved match.
 *  - `POST /api/v1/rfqs/{publicId}/accessories` `{header?, lines?, lineUids?}`: a draft, with
 *    the edits not yet saved; left out, as saved.
 *
 * `lineUids` names the cable lines to ask about (the row's own Accessories
 * button); left out, every cable line. Both routes are gated on
 * `RFQ.PRICING.VIEW`: the candidates carry cost, and the source offers the
 * feature in its detailed view only. With no engine connected the answer is
 * 200 with `engineAvailable: false` and a note, as the source's is.
 */
final class MatchRfqAccessoriesAction
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
        $uids = null;
        if (array_key_exists('lineUids', $body) && $body['lineUids'] !== null) {
            $given = $body['lineUids'];
            if (!is_array($given) || !array_is_list($given) || count($given) > 2_000) {
                return $this->responder->error('RFQ_INPUT_INVALID', 'lineUids must be a list of line ids, or left out for every cable line.', 422);
            }
            $uids = [];
            foreach ($given as $uid) {
                if (!is_string($uid) || $uid === '') {
                    return $this->responder->error('RFQ_INPUT_INVALID', 'lineUids must be a list of line ids, or left out for every cable line.', 422);
                }
                $uids[] = $uid;
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
                $found = $this->review->accessoriesNew($actor, $user->emailAddress, $token, QuoteHeaderEdit::fromInput($body['header'] ?? null), $lines ?? [], $uids, $id);
            } else {
                $header = array_key_exists('header', $body) ? QuoteHeaderEdit::fromInput($body['header']) : null;
                $found = $this->review->accessoriesDraft($actor, $user->emailAddress, $publicId, $header, $lines, $uids, $id);
                if ($found === null) {
                    return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
                }
            }
        } catch (InvalidRfqValue|RfqActionForbidden|RfqStoreUnavailable|RfqParserFailure $failure) {
            return $this->failure($this->responder, $failure);
        }

        return $this->responder->success($found->toArray());
    }
}
