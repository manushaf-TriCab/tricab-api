<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Infrastructure;

use TriCab\Core\Rfq\Contract\RfqParserClient;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ParseResponse;
use TriCab\Core\Rfq\Parser\PricedRows;
use TriCab\Core\Rfq\Parser\QuoteReadiness;
use TriCab\Core\Rfq\Parser\ReadRequest;
use TriCab\Core\Rfq\Parser\ReadResponse;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Parser\ScreenshotText;

/**
 * The parser client wherever the parser is not switched on (`RFQ_PARSER_ENABLED`
 * with an internal token): local runs without Python, tests, and production
 * until the RFQ module ships.
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

    public function read(ReadRequest $request): ReadResponse
    {
        throw RfqParserFailure::of(
            RfqParserFailure::UNAVAILABLE,
            'The RFQ parser is not deployed on this platform yet.',
            $request->correlationId,
        );
    }

    public function readiness(CorrelationId $correlationId, array $rows, array $quoteDetails): QuoteReadiness
    {
        throw RfqParserFailure::of(
            RfqParserFailure::UNAVAILABLE,
            'The RFQ parser is not deployed on this platform yet.',
            $correlationId,
        );
    }

    public function searchProducts(CorrelationId $correlationId, string $query, int $limit): array
    {
        throw RfqParserFailure::of(
            RfqParserFailure::UNAVAILABLE,
            'The RFQ parser is not deployed on this platform yet.',
            $correlationId,
        );
    }

    public function readScreenshot(CorrelationId $correlationId, string $fileName, string $contentType, string $bytes): ScreenshotText
    {
        throw RfqParserFailure::of(
            RfqParserFailure::UNAVAILABLE,
            'The RFQ parser is not deployed on this platform yet.',
            $correlationId,
        );
    }

    public function priceRows(CorrelationId $correlationId, array $rows, string $region, string $currency, array $defaultMargins, bool $reprice): PricedRows
    {
        throw RfqParserFailure::of(
            RfqParserFailure::UNAVAILABLE,
            'The RFQ parser is not deployed on this platform yet.',
            $correlationId,
        );
    }

    public function productStyles(CorrelationId $correlationId, array $codes): array
    {
        throw RfqParserFailure::of(
            RfqParserFailure::UNAVAILABLE,
            'The RFQ parser is not deployed on this platform yet.',
            $correlationId,
        );
    }

    public function correctionKeys(CorrelationId $correlationId, array $lines): array
    {
        throw RfqParserFailure::of(
            RfqParserFailure::UNAVAILABLE,
            'The RFQ parser is not deployed on this platform yet.',
            $correlationId,
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
