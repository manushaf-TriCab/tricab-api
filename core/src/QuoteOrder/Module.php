<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder;

/**
 * Quotes & Orders — Phase 1 read-only search and detail over legacy tc MySQL.
 * Authoritative history is NOT copied to Azure SQL.
 */
final class Module
{
    public const NAME = 'QuoteOrder';

    /** Module entry — search and view quotes/orders. */
    public const PERMISSION_READ = 'QUOTE.READ';

    /** @var list<string> */
    public const PERMISSIONS = [
        self::PERMISSION_READ,
    ];
}
