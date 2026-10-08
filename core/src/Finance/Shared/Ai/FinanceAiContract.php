<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Ai;

/**
 * `finance-ai.v1`: the loopback contract between the finance module and the
 * `finance_ai` Python service (`core/python/finance_ai/contract.py`, which holds the
 * same constants; `FinanceAiContractTest` fails if the two drift).
 *
 * Same hop as `rfq-parser.v1`: 127.0.0.1:8003, no nginx location, a bearer token
 * (`FINANCE_AI_INTERNAL_TOKEN`) and a correlation id on every call. The caller sends a
 * purpose name and data only; the prompt, schema, model and timeout belong to the
 * purpose in the service's registry (decision D10).
 */
final class FinanceAiContract
{
    public const VERSION = 'finance-ai.v1';

    public const PATH_HEALTH = '/v1/health';

    public const PATH_COMPLETE = '/v1/complete/';

    public const HEADER_CONTRACT = 'X-Finance-Ai-Contract';

    public const HEADER_CORRELATION_ID = 'X-Correlation-Id';

    public const HEADER_AUTHORIZATION = 'Authorization';

    /** The service's own pattern for a correlation id. */
    public const CORRELATION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/';

    /** A purpose name: lower-case letters, digits, '.', '_' and '-'. */
    public const PURPOSE_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,63}$/';

    public const DEFAULT_DEADLINE_MS = 60_000;

    public const MIN_DEADLINE_MS = 1_000;

    /** App Service ends a web request at about 230 s; this plus {@see self::TRANSPORT_GRACE_MS} stays under it. */
    public const MAX_DEADLINE_MS = 200_000;

    /** Added to the deadline for the socket, so the service answers `timeout` itself. */
    public const TRANSPORT_GRACE_MS = 5_000;

    /**
     * A purpose that reads a file (Tri-AR's `ar_remittance`) is sent one inside `data` as
     * `{name, content_type, data}` with `data` base64. The ceiling is the file store's
     * (`FinanceAttachmentRules::MAX_BYTES`), the same number as `registry.MAX_FILE_BYTES` in the service.
     */
    public const MAX_FILE_BYTES = 33_554_432;

    /** The file types the service accepts (`registry.FILE_TYPES`). */
    public const FILE_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/tiff'];

    private function __construct()
    {
    }
}
