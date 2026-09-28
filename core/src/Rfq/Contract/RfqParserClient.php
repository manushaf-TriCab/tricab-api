<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ParseResponse;
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

    /** The process is up. Cheap; for `/health`-style probes. */
    public function isAlive(): bool;

    /** Catalogue and rules are loaded and a parse would succeed. For readiness. */
    public function isReady(): bool;
}
