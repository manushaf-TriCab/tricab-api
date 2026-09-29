<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Action\Rfq;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Http\Response\JsonResponder;
use TriCab\Api\Identity\Authentication\AuthenticatedUserAttribute;
use TriCab\Core\Identity\Application\DTO\AuthenticatedUser;
use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\RfqParserFailure;

/**
 * GET /api/v1/rfqs/lookups/products?q= — the grid's product-code autocomplete
 * (parity plan E3.3), over the catalogue the parser matches against.
 *
 * As in the source it answers from two characters, 20 at most, as
 * `{items: [{code, style, description}]}`. Gated on `RFQ.DRAFT.EDIT`, like
 * the other header lookups. A parser that is down answers 503.
 */
final class SearchRfqProductsAction
{
    use RfqWriteFailures;

    private const MIN_QUERY = 2;
    private const LIMIT = 20;

    public function __construct(
        private readonly RfqParserClient $parser,
        private readonly JsonResponder $responder,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($response);

        if (!$request->getAttribute(AuthenticatedUserAttribute::KEY) instanceof AuthenticatedUser) {
            return $this->responder->error('UNAUTHENTICATED', 'Authentication is required.', 401);
        }
        $q = $request->getQueryParams()['q'] ?? '';
        $q = is_string($q) ? mb_substr(trim($q), 0, 100) : '';
        if (mb_strlen($q) < self::MIN_QUERY) {
            return $this->responder->success(['items' => []]);
        }
        $id = CorrelationId::fromHeaderOrGenerate($request->getHeaderLine(ParserContract::HEADER_CORRELATION_ID));
        try {
            return $this->responder->success(['items' => $this->parser->searchProducts($id, $q, self::LIMIT)]);
        } catch (RfqParserFailure $failure) {
            return $this->failure($this->responder, $failure);
        }
    }
}
