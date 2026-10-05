<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Application\Service\ResolveRfqActorService;
use TriCab\Core\Rfq\Application\Service\RfqAliflexService;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqAliflexNotEligible;
use TriCab\Core\Rfq\Domain\Exception\RfqLookupUnavailable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqStoreUnavailable;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\RfqParserFailure;

/**
 * ALIFLEX (parity plan E7.4): `POST /api/v1/rfqs/{publicId}/aliflex`
 * `{expectedRevision}`, gated on `RFQ.DRAFT.CREATE` (it creates a draft).
 *
 * The body carries only the revision the person holds; the quote converted is the
 * saved one. Answers `rfq.aliflex-result.v1`:
 *
 *  - **201** with `publicId` of the new draft, its `label`, `counts` and `message`;
 *  - **200** with `publicId: null` when no line matches the rule, so no draft was made;
 *  - **409** `RFQ_ALIFLEX_NOT_ELIGIBLE` (not finished, or already an alternative) and
 *    `RFQ_REVISION_CONFLICT`; **404**; and the parser's typed failures.
 *
 * The source draft is never written.
 */
final class CreateRfqAliflexAction
{
    use RfqWriteFailures;

    public function __construct(
        private readonly ResolveRfqActorService $actors,
        private readonly RfqAliflexService $aliflex,
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
            return $this->responder->error('RFQ_INPUT_INVALID', 'expectedRevision must be the saved revision to convert.', 422);
        }

        $actor = $this->actors->forUser($user);
        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            $result = $this->aliflex->create($actor, $user->emailAddress, $args['publicId'] ?? '', $expected, $id);
        } catch (RfqAliflexNotEligible $refused) {
            return $this->responder->error('RFQ_ALIFLEX_NOT_ELIGIBLE', $refused->getMessage(), 409);
        } catch (InvalidRfqValue|RfqActionForbidden|RfqRevisionConflict|RfqStoreUnavailable|RfqLookupUnavailable|RfqParserFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
        if ($result === null) {
            return $this->responder->error('RFQ_NOT_FOUND', 'RFQ not found.', 404);
        }

        return $this->responder->success($result->toArray(), $result->publicId === null ? 200 : 201);
    }
}
