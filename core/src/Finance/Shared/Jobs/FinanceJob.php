<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Jobs;

use DateTimeImmutable;

/**
 * One piece of finance's scheduled work (a daily summary, a reminder sweep, a
 * mailbox scan, the Odoo sync). Each app adds jobs in its phase; there is no WebJob per
 * job, only the one runner.
 *
 * `run` must be safe to repeat: the runner takes a claim so that a due slot runs once,
 * but a claim that dies mid-run is taken over after a while and the job runs again.
 */
interface FinanceJob
{
    /** Stable and unique: it is the claim's key and the audit row's entity id. Lower-case, `a-z0-9._-`. */
    public function name(): string;

    public function schedule(): FinanceJobSchedule;

    /**
     * Does the work. Returns facts about it (counts, never content), recorded with the run.
     * Throws to fail; the runner records the failure and goes on to the next job.
     *
     * @return array<string, scalar|null>
     */
    public function run(DateTimeImmutable $now): array;
}
