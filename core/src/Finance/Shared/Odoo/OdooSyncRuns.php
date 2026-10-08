<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Odoo;

use DateTimeImmutable;

/**
 * `finance.odoo_sync_runs`: the record of each run, and the guarantee that two never
 * overlap (the table allows one row in status `running`).
 */
interface OdooSyncRuns
{
    /**
     * Marks every run still `running` and started before `$cutoff` as `abandoned` (a
     * run that died without finishing) and returns how many.
     */
    public function abandonStale(DateTimeImmutable $cutoff, DateTimeImmutable $at): int;

    /**
     * Starts a run if none is running. False means one is (nothing was written).
     */
    public function start(string $id, string $mode, string $triggeredBy, ?int $actorUserId, DateTimeImmutable $at): bool;

    /**
     * Ends the run, only while it is still `running`: a run another runner abandoned is
     * not brought back by a late finish. Returns whether it was recorded.
     *
     * @param array<string, array{fetched: int, added: int, updated: int, removed: int}> $counts
     */
    public function finish(string $id, string $status, array $counts, ?string $error, DateTimeImmutable $at): bool;

    public function find(string $id): ?OdooSyncRun;

    /** The newest run that succeeded, optionally of one mode. */
    public function lastSuccess(?string $mode = null): ?OdooSyncRun;

    /**
     * Newest first.
     *
     * @return list<OdooSyncRun>
     */
    public function recent(int $limit): array;

    /** When the oldest run still `running` started, or null: the read cursor must not pass it. */
    public function oldestRunningStart(): ?DateTimeImmutable;
}
