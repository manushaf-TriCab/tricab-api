<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ParseResponse;
use TriCab\Core\Rfq\Parser\RfqParserFailure;

/**
 * The parser client until `core/python/rfq_parser` is packaged (plan 3.1).
 *
 * Refuses every parse as `unavailable` rather than returning an empty result,
 * for the reason {@see RfqParserClient} gives: "no lines" must never be the
 * answer to "the parser is not there".
 */
final class UndeployedRfqParserClient implements RfqParserClient
{
    public function parse(ParseRequest $request): ParseResponse
    {
        throw RfqParserFailure::of(
            RfqParserFailure::UNAVAILABLE,
            'The RFQ parser is not deployed on this platform yet.',
            $request->correlationId,
        );
    }

    public function isAlive(): bool
    {
        return false;
    }

    public function isReady(): bool
    {
        return false;
    }
}
