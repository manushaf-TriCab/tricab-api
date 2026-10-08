<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Contract\ArScanStore;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArScanRun;

/** Scan runs held in memory, with the one-running-scan-per-mailbox claim the unique index gives the SQL. */
final class InMemoryArScanStore implements ArScanStore
{
    /** @var array<string, ArScanRun> */
    private array $runs = [];

    public function begin(ArScanRun $run, DateTimeImmutable $now): void
    {
        foreach ($this->runs as $id => $other) {
            if ($other->mailbox !== $run->mailbox || $other->status !== ArScanRun::RUNNING) {
                continue;
            }
            if ($other->claimExpiresAt !== null && $other->claimExpiresAt > $now) {
                throw new ArConflict('A scan of this mailbox is already running');
            }
            $this->runs[$id] = $other->with(status: ArScanRun::FAILED, finishedAt: $now, errors: 'The scan stopped without finishing', claimExpiresAt: null);
        }
        $this->runs[$run->id] = $run;
    }

    public function find(string $id): ?ArScanRun
    {
        return $this->runs[$id] ?? null;
    }

    public function bump(string $id, array $increments, DateTimeImmutable $claimUntil): void
    {
        $run = $this->runs[$id] ?? null;
        if ($run === null) {
            return;
        }
        $this->runs[$id] = $run->with(
            totalFetched: $run->totalFetched + ($increments['total_fetched'] ?? 0),
            newRecords: $run->newRecords + ($increments['new_records'] ?? 0),
            skippedRecords: $run->skippedRecords + ($increments['skipped_records'] ?? 0),
            failedEnrichments: $run->failedEnrichments + ($increments['failed_enrichments'] ?? 0),
            totalMessages: $run->totalMessages + ($increments['total_messages'] ?? 0),
            processedMessages: $run->processedMessages + ($increments['processed_messages'] ?? 0),
            claimExpiresAt: $run->status === ArScanRun::RUNNING ? $claimUntil : $run->claimExpiresAt,
        );
    }

    public function finish(string $id, string $status, DateTimeImmutable $at, ?DateTimeImmutable $watermark, ArScanRun $counters): void
    {
        $run = $this->runs[$id] ?? null;
        if ($run === null) {
            return;
        }
        $this->runs[$id] = $run->with(
            status: $status,
            finishedAt: $at,
            watermark: $watermark,
            totalFetched: $counters->totalFetched,
            newRecords: $counters->newRecords,
            skippedRecords: $counters->skippedRecords,
            failedEnrichments: $counters->failedEnrichments,
            errors: $counters->errors,
            claimExpiresAt: null,
        );
    }

    public function latestWatermark(string $mailbox): ?DateTimeImmutable
    {
        $best = null;
        foreach ($this->runs as $run) {
            if ($run->mailbox === $mailbox && $run->status === ArScanRun::COMPLETED && $run->watermark !== null && ($best === null || $run->watermark > $best)) {
                $best = $run->watermark;
            }
        }

        return $best;
    }

    public function overlapping(string $from, string $to, int $limit = 10): array
    {
        $found = array_values(array_filter(
            $this->runs,
            static fn (ArScanRun $r): bool => $r->fromDate !== null && $r->toDate !== null
                && in_array($r->status, [ArScanRun::COMPLETED, ArScanRun::RUNNING], true)
                && $r->fromDate <= $to && $r->toDate >= $from,
        ));
        usort($found, static fn (ArScanRun $a, ArScanRun $b): int => $b->startedAt <=> $a->startedAt);

        return array_slice($found, 0, $limit);
    }

    public function recent(int $limit): array
    {
        $all = array_values($this->runs);
        usort($all, static fn (ArScanRun $a, ArScanRun $b): int => [$b->startedAt, $b->id] <=> [$a->startedAt, $a->id]);

        return array_slice($all, 0, $limit);
    }
}
