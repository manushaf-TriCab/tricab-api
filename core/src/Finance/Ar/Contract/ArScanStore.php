<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Domain\ArScanRun;

/**
 * Scan runs (`ar_scan_runs`) and the claim that keeps two scans of one mailbox from running at once (decision D45).
 * Storage only: the watermark rule is `ArScanService`'s.
 */
interface ArScanStore
{
    /**
     * Starts a run (status `running`). A run of the same mailbox that is still `running` and whose claim has not
     * expired is an `ArConflict`; one whose claim has expired is first failed ("The scan stopped without finishing").
     */
    public function begin(ArScanRun $run, DateTimeImmutable $now): void;

    public function find(string $id): ?ArScanRun;

    /**
     * Adds to the counters (`total_fetched`, `new_records`, `skipped_records`, `failed_enrichments`, `total_messages`,
     * `processed_messages`) and moves the claim to `$claimUntil`.
     *
     * @param array<string, int> $increments
     */
    public function bump(string $id, array $increments, DateTimeImmutable $claimUntil): void;

    /** Ends a run: its status, finish time, watermark (null for a period scan or a failure), counters and errors; the claim is released. */
    public function finish(string $id, string $status, DateTimeImmutable $at, ?DateTimeImmutable $watermark, ArScanRun $counters): void;

    /** The highest watermark among the mailbox's completed runs. */
    public function latestWatermark(string $mailbox): ?DateTimeImmutable;

    /**
     * Completed or running period scans whose range overlaps [$from, $to] (`yyyy-MM-dd`), newest first.
     *
     * @return list<ArScanRun>
     */
    public function overlapping(string $from, string $to, int $limit = 10): array;

    /** @return list<ArScanRun> newest first */
    public function recent(int $limit): array;
}
