<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParseRequest;
use TriCab\Core\Rfq\Parser\ParseResponse;
use TriCab\Core\Rfq\Parser\PricedRows;
use TriCab\Core\Rfq\Parser\QuoteReadiness;
use TriCab\Core\Rfq\Parser\ReadRequest;
use TriCab\Core\Rfq\Parser\ReadResponse;
use TriCab\Core\Rfq\Parser\ScreenshotText;
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

    /**
     * One screenshot through vision OCR: the table's text, for the rep to
     * review before it is matched (parity plan E4.3). Creates no lines.
     * Off, rejected or throttled providers are typed failures.
     *
     * @throws RfqParserFailure
     */
    public function readScreenshot(CorrelationId $correlationId, string $fileName, string $contentType, string $bytes): ScreenshotText;

    /**
     * The rows priced as the source editor prices them, in the order given
     * (parity plan E5). With `$reprice` the Cost API is asked and the price
     * converted to `$currency`; without it only margin, sell price and line
     * total are worked out again from the cost the rows hold. A row whose
     * price could not be had comes back with its pricing `status` saying so:
     * that is an answer, not a failure.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, int|float|string> $defaultMargins customer default margin by product code (or `cost`)
     *
     * @throws RfqParserFailure
     */
    public function priceRows(CorrelationId $correlationId, array $rows, string $region, string $currency, array $defaultMargins, bool $reprice): PricedRows;

    /**
     * The catalogue's style (upper case) for each product code it knows.
     *
     * @param list<string> $codes
     *
     * @return array<string, string> code => style
     *
     * @throws RfqParserFailure
     */
    public function productStyles(CorrelationId $correlationId, array $codes): array;

    /**
     * The correction-memory key of each approved line Save Review may learn
     * from (parity plan E5.5): the source's `correction_key` of the text, and
     * the code if the catalogue carries it. Lines with neither are left out.
     *
     * @param list<array{text: string, productCode: string}> $lines
     *
     * @return list<array{index: int, key: string, productCode: string}>
     *
     * @throws RfqParserFailure
     */
    public function correctionKeys(CorrelationId $correlationId, array $lines): array;

    /** The process is up. Cheap; for `/health`-style probes. */
    public function isAlive(): bool;

    /** Catalogue and rules are loaded and a parse would succeed. For readiness. */
    public function isReady(): bool;
}
