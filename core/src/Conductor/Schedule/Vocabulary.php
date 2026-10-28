<?php

declare(strict_types=1);

namespace TriCab\Core\Conductor\Schedule;

/**
 * The fixed lists the conductor schedule store and the API agree on (docs/conductor/SCHEDULE-CONTRACT.md, sections 4.8, 5 and 6).
 */
final class Vocabulary
{
    public const SCHEMA_VERSION = 1;

    /** @var list<string> cs_restock.status */
    public const RESTOCK_STATUSES = ['release', 'ok', 'no_use'];

    /** @var list<string> restock material filter */
    public const MATERIALS = ['plain', 'tinned'];

    /** @var list<string> cs_job.status */
    public const JOB_STATUSES = ['scheduled', 'from_stock', 'no_machine', 'no_rate'];

    /** @var list<string> cs_issue.severity, warning first */
    public const SEVERITIES = ['warning', 'info'];

    /** @var list<string> cs_stock.point, in display order */
    public const STOCK_POINTS = ['wire_plain', 'wire_tinned', 'spool', 'conductor', 'other'];

    /** @var list<string> cs_issue.kind, the closed list of section 4.8 */
    public const ISSUE_KINDS = [
        'r86_short_period', 'r86_period_mismatch', 'r86_older_ignored', 'r86_unplaced', 'inventory_date_unknown',
        'inventory_unconverted', 'kanban_assumed', 'mica_not_scheduled', 'no_build_tree', 'job_from_stock',
        'covered_by_stock', 'no_machine', 'no_rate', 'pool_fallback', 'rate_borrowed', 'qty_converted', 'qty_unit',
        'no_weight', 'calendar_pattern', 'shift_override', 'efficiency_applied', 'yield_applied', 'spool_substituted',
        'no_batch_size', 'no_feeder_batch', 'rod_cover_low', 'shutdown_in_horizon',
    ];

    /** @var list<int> allowed weeks of the machine load view */
    public const LOAD_WEEKS = [4, 8, 26];
    public const LOAD_DEFAULT_WEEKS = 4;

    public const DEFAULT_PAGE_SIZE = 50;
    public const MAX_PAGE_SIZE = 200;
}
