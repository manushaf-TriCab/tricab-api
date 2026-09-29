<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ParseResponse;
use TriCab\Core\Rfq\Parser\QuoteReadiness;
use TriCab\Core\Rfq\Parser\ReadRequest;
use TriCab\Core\Rfq\Parser\ReadResponse;
use TriCab\Core\Rfq\Parser\RfqParserFailure;

/**
 * Reaches the private RFQ parser process. Contract `rfq-parser.v1`
 * ({@see \TriCab\Core\Rfq\Parser\ParserContract}).
 *
 * Unlike `ProductFinderClient`, this **throws**. The finder can answer a
 * customer with "sizing unavailable" and carry on; an RFQ parse that silently
 * returned nothing would read as "this schedule has no lines", which is the
 * failure invariant 3 exists to prevent. Every failure is a typed
 * {@see RfqParserFailure} and the caller decides: show it, or enqueue.
 *
 * Implementations send the contract, correlation id and deadline headers,
 * authenticate with the internal token, set their socket timeout to the
 * deadline plus `RfqServiceLimits::TRANSPORT_GRACE_MS`, and never log the
 * request body or the token.
 */
interface RfqParserClient
{
    /**
     * @throws RfqParserFailure
     */
    public function parse(ParseRequest $request): ParseResponse;

    /**
     * One document through the model reader, for a reader job (plan 3.5).
     * A read that produced nothing is a failure, never an empty response.
     *
     * @throws RfqParserFailure
     */
    public function read(ReadRequest $request): ReadResponse;

    /**
     * The source's readiness rule over rows and header, exactly as stored.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $quoteDetails
     *
     * @throws RfqParserFailure
     */
    public function readiness(CorrelationId $correlationId, array $rows, array $quoteDetails): QuoteReadiness;

    /**
     * Product codes for the grid's autocomplete: the source's `search_products`
     * (code prefix, then wildcard, then contains, then every word in code, style
     * or description), in catalogue order.
     *
     * @return list<array{code: string, style: string, description: string}>
     *
     * @throws RfqParserFailure
     */
    public function searchProducts(CorrelationId $correlationId, string $query, int $limit): array;

    /** The process is up. Cheap; for `/health`-style probes. */
    public function isAlive(): bool;

    /** Catalogue and rules are loaded and a parse would succeed. For readiness. */
    public function isReady(): bool;
}
