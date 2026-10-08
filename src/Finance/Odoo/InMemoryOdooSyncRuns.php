<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Odoo;

use DateTimeImmutable;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRun;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRuns;

/** The run record held in memory: the tests', and a database-less run's. At most one run is `running`, as the table allows. */
final class InMemoryOdooSyncRuns implements OdooSyncRuns
{
    /** @var array<string, OdooSyncRun> keyed by id, in the order started */
    private array $runs = [];

    public function abandonStale(DateTimeImmutable $cutoff, DateTimeImmutable $at): int
    {
        $abandoned = 0;
        foreach ($this->runs as $id => $run) {
            if ($run->status === OdooSyncRun::STATUS_RUNNING && $run->startedAt < $cutoff) {
                $this->runs[$id] = new OdooSyncRun($run->id, $run->mode, $run->triggeredBy, OdooSyncRun::STATUS_ABANDONED, $run->counts, $run->error, $run->startedAt, $at, $run->actorUserId);
                ++$abandoned;
            }
        }

        return $abandoned;
    }

    public function start(string $id, string $mode, string $triggeredBy, ?int $actorUserId, DateTimeImmutable $at): bool
    {
        foreach ($this->runs as $run) {
            if ($run->status === OdooSyncRun::STATUS_RUNNING) {
                return false;
            }
        }
        $this->runs[$id] = new OdooSyncRun($id, $mode, $triggeredBy, OdooSyncRun::STATUS_RUNNING, [], null, $at, null, $actorUserId);

        return true;
    }

    public function finish(string $id, string $status, array $counts, ?string $error, DateTimeImmutable $at): bool
    {
        $run = $this->runs[$id] ?? null;
        if ($run === null || $run->status !== OdooSyncRun::STATUS_RUNNING) {
            return false;
        }
        $this->runs[$id] = new OdooSyncRun($id, $run->mode, $run->triggeredBy, $status, $counts, $error, $run->startedAt, $at, $run->actorUserId);

        return true;
    }

    public function find(string $id): ?OdooSyncRun
    {
        return $this->runs[$id] ?? null;
    }

    public function lastSuccess(?string $mode = null): ?OdooSyncRun
    {
        foreach (array_reverse($this->runs) as $run) {
            if ($run->status === OdooSyncRun::STATUS_SUCCEEDED && ($mode === null || $run->mode === $mode)) {
                return $run;
            }
        }

        return null;
    }

    public function recent(int $limit): array
    {
        return array_slice(array_values(array_reverse($this->runs)), 0, max(0, $limit));
    }

    public function oldestRunningStart(): ?DateTimeImmutable
    {
        $oldest = null;
        foreach ($this->runs as $run) {
            if ($run->status === OdooSyncRun::STATUS_RUNNING && ($oldest === null || $run->startedAt < $oldest)) {
                $oldest = $run->startedAt;
            }
        }

        return $oldest;
    }
}
