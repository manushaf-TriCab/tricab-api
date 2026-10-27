<?php

declare(strict_types=1);

namespace TriCab\Core\CableRoutes;

/**
 * The fixed lists the store and the API agree on (docs/cable-routes/CONTRACT.md, sections 3 and 5).
 */
final class Vocabulary
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> In the order the run endpoint lists them. */
    public const TRACE_STATUSES = ['to_rod', 'to_purchased_conductor', 'stops_early', 'untraceable'];

    /** @var list<string> */
    public const UNTRACEABLE_REASONS = ['no_orders', 'cutting_only'];

    /** @var array<string, string> Check kind to label, in display order. */
    public const CHECK_KINDS = [
        'family_route_mismatch' => 'Route differs from the poster family',
        'machine_not_on_poster' => 'Machine in Dynamics but not on the poster',
        'poster_machine_unused' => 'Poster machine with no Dynamics routings',
        'routed_to_retired_machine' => 'Routed to a machine that is not used',
        'outside_poster_limit' => 'Outside poster limit, 2023',
        'stops_early' => 'Chain stops before copper rod or purchased conductor',
        'untraceable' => 'No production orders to trace',
    ];

    /** Check kinds that describe a finished-item code rather than one item, so an item page shows them by code. */
    public const CODE_LEVEL_CHECK_KINDS = ['family_route_mismatch', 'stops_early', 'untraceable'];

    public const ITEMS_DEFAULT_PAGE_SIZE = 50;
    public const ITEMS_MAX_PAGE_SIZE = 200;
    public const CHECKS_DEFAULT_PAGE_SIZE = 100;
    public const CHECKS_MAX_PAGE_SIZE = 500;
    public const TOP_INPUTS = 15;
}
