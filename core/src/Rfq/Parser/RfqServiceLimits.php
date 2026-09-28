<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Parser;

/**
 * Request limits and deadlines at the RFQ service boundaries. Plan 1.7.
 *
 * **Provisional.** The source had almost none — no page, row or byte cap on
 * the deterministic parser (`rfq-phase0/0.4-runtime-inventory.md` §2) — so
 * these are starting values chosen to be generous against known schedules
 * (175+ and 307+ line RFQs are in the plan's measurement set) and are replaced
 * with measured thresholds in plan 8.3. Where a value is inherited from the
 * source it says so.
 *
 * One limit is outside this code and must move with it: the App Service nginx
 * config (`deploy/app-service/default`) sets no `client_max_body_size`, so
 * nginx's 1 MB default applies to every request. That is the same boundary
 * that makes large saves return 413 in the source (0.4 §8). A revision save
 * larger than 1 MB needs that setting raised, deliberately, before Phase 4.
 */
final class RfqServiceLimits
{
    /** One uploaded document. */
    public const MAX_DOCUMENT_BYTES = 20 * 1024 * 1024;

    /** One image. Inherited: source `VISION_OCR_MAX_IMAGE_MB` / `RFQ_READER_MAX_IMAGE_MB` = 8. */
    public const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    /** Documents in one intake. */
    public const MAX_DOCUMENTS_PER_INTAKE = 10;

    /** Pasted text. Roughly 1,500 lines of a dense schedule. */
    public const MAX_PASTED_CHARACTERS = 200_000;

    /** Lines in one saved revision. */
    public const MAX_LINES_PER_REVISION = 2_000;

    /** A revision save body, before nginx is raised to match. */
    public const MAX_SAVE_BODY_BYTES = 8 * 1024 * 1024;

    /**
     * Deadline PHP gives the parser for an interactive parse. Under the source
     * bridge's 100 s (`RFQ_PYTHON_TIMEOUT_SECONDS`) so the parser answers
     * `timeout` before the HTTP request itself is abandoned. Anything slower
     * is enqueued as a job, never waited on.
     */
    public const INTERACTIVE_PARSE_DEADLINE_MS = 60_000;

    /** Deadline for a background read, per document. Inherited: source reader timeout 600 s. */
    public const BACKGROUND_READ_DEADLINE_MS = 600_000;

    /** PHP's own socket timeout: the deadline plus time for the parser to say so. */
    public const TRANSPORT_GRACE_MS = 5_000;

    /** Per-request model call ceiling. Inherited: source `OPENROUTER_MAX_CALLS_PER_REQUEST` floor. */
    public const MAX_MODEL_CALLS_PER_PARSE = 50;
}
