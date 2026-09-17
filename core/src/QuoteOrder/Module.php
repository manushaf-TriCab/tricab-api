<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder;

/**
 * Quotes & Orders — legacy MySQL history (read) + future create (write gated).
 *
 * Permissions: QUOTE.READ for search/detail. QUOTE.CREATE exists in Identity catalog
 * but is not wired to any HTTP write route yet (Phase 4B infrastructure only).
 * Authoritative issued quotes remain on MySQL tc — not Azure SQL.
 */
final class Module
{
    public const NAME = 'QuoteOrder';

    /** Module entry — search and view quotes/orders. */
    public const PERMISSION_READ = 'QUOTE.READ';

    /** Future create/save — do not authorize writes with QUOTE.READ. */
    public const PERMISSION_CREATE = 'QUOTE.CREATE';

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_READ,
        self::PERMISSION_CREATE,
    ];
}
