<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * The PHP ↔ Python parser boundary, version 1. Plan 1.1 ("version Python
 * request/response DTOs") and 1.7.
 *
 * The parser (`core/python/rfq_parser`, Phase 3) runs as a private persistent
 * process on the App Service instance, like the Product Finder on
 * 127.0.0.1:8001, and is reached only from PHP. It is **not** an authority on
 * anything: it receives no actor, no role and no scope, and nothing it returns
 * is trusted until PHP validates it. PHP owns authorization, identity and the
 * public API (plan, target architecture).
 *
 * Every request and response carries {@see VERSION}. A response with any other
 * version is rejected, not interpreted: the two sides deploy in one zip, so a
 * mismatch is a packaging fault that should fail loudly.
 */
final class ParserContract
{
    public const VERSION = 'rfq-parser.v1';

    /** Request header naming the contract version. Must equal {@see VERSION}. */
    public const HEADER_CONTRACT = 'X-Rfq-Contract';

    /** Carried from the public request into every parser, worker and provider log line. */
    public const HEADER_CORRELATION_ID = 'X-Correlation-Id';

    /**
     * Milliseconds the parser may spend before answering `timeout`. The parser
     * stops work at the deadline rather than finishing into a closed socket —
     * a result nobody receives is cost with no output.
     */
    public const HEADER_DEADLINE_MS = 'X-Rfq-Deadline-Ms';

    /**
     * `Authorization: Bearer <RFQ_PARSER_INTERNAL_TOKEN>`. Loopback binding keeps
     * the parser off the network; the token keeps other processes on the same
     * instance from using it. Never logged, never sent to the browser.
     */
    public const HEADER_AUTHORIZATION = 'Authorization';

    public const PATH_PARSE = '/v1/parse';
    /** One document through the model reader, for a reader job (plan 3.5; `ReadRequest`). */
    public const PATH_READ = '/v1/read';
    /** The source's `quote_readiness` over a draft (`QuoteReadiness`), until plan 5.3 ports it. */
    public const PATH_READINESS = '/v1/readiness';
    /** The source's product-code autocomplete over the matcher's catalogue (parity plan E3.3). */
    public const PATH_PRODUCT_SEARCH = '/v1/products/search';
    /** One screenshot through vision OCR, answered as text (parity plan E4.3; `ScreenshotText`). */
    public const PATH_OCR = '/v1/ocr';
    /** A draft's rows priced as the source editor does (`PricedRows`, parity plan E5). */
    public const PATH_PRICE = '/v1/price';
    /** The product summary and the cells of both quote CSVs over priced rows (`QuoteExport`, parity plan E7.1). */
    public const PATH_EXPORT = '/v1/export';
    /** The catalogue's style per product code, for the customer default margin lookup. */
    public const PATH_ALIFLEX = '/v1/aliflex';
    public const PATH_SYNC_PLAN = '/v1/sync-plan';
    public const PATH_PRODUCT_STYLES = '/v1/products/styles';
    /** The correction-memory key for each approved line, for Save Review (parity plan E5.5). */
    public const PATH_CORRECTION_KEYS = '/v1/corrections/keys';
    public const PATH_HEALTH = '/v1/health';
    public const PATH_READY = '/v1/ready';
}
