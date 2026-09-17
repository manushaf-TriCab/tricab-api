<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder;

/**
 * Quotes & Orders — read-only search and detail over legacy tc MySQL.
 *
 * Permissions: QUOTE.READ (existing catalog). No QUOTE.READ_ALL — staff breadth
 * uses EMPLOYEE/ADMINISTRATOR roles. Authoritative history is NOT on Azure SQL.
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
