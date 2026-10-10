<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Domain\ApScanRun;

/**
 * Scan runs (`finance.invoice_scan_runs`) and the claim that keeps two scans from running at once (decision D76): ONE open
 * run (`Listing` or `Running`) for the whole app, whatever mailboxes it reads. Storage only: the rules (the window, the
 * mailboxes, the pipeline) are `ApScanService`'s, so both adapters behave the same.
 */
interface ApScanStore
{
    /**
     * Starts a run (status `Listing`, which holds the claim). An open run whose claim has not expired is an `ApConflict`; one
     * whose claim has expired is first failed (it died holding the claim), in the same unit of work.
     *
     * @throws \TriCab\Core\Finance\Ap\Domain\ApConflict
     */
    public function begin(ApScanRun $run, DateTimeImmutable $now): void;

    public function find(string $id): ?ApScanRun;

    /** The listing is done: status `Running`, `total_messages` and `errors` (the mailboxes that failed to list), the claim moved on. */
    public function markRunning(string $id, int $totalMessages, int $errors, DateTimeImmutable $claimUntil, DateTimeImmutable $at): void;

    /**
     * Adds to the counters (`processed_messages`, `invoice_emails`, `other_emails`, `duplicate_invoices`, `errors`) in one
     * statement, so two `process` calls never lose each other's count (R14), and moves an open run's claim to `$claimUntil`.
     * A run that does not exist is ignored.
     *
     * @param array<string, int> $increments
     */
    public function bump(string $id, array $increments, DateTimeImmutable $claimUntil, DateTimeImmutable $at): void;

    /** Ends a run with `Complete` or `Failed` and releases the claim (`complete` mode also closes a `Failed` run). */
    public function finish(string $id, string $status, DateTimeImmutable $at, ?int $errors = null): void;

    /**
     * Earlier runs whose period overlaps [$from, $to] (`yyyy-MM-dd`) that read something: not `Failed`, with
     * `total_messages` above 0. Newest first. A run still `Listing` or `Running` counts.
     *
     * @return list<ApScanRun>
     */
    public function overlapping(string $from, string $to, int $limit = 20): array;
}
