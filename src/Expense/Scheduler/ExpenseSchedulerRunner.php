<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Scheduler;

use Throwable;
use TriCab\Core\Expense\Application\Service\MonthlyManagerDigestService;
use TriCab\Core\Expense\Application\Service\PurgeExpenseReceiptService;
use TriCab\Core\Shared\Time\Clock;

/**
 * Single dispatcher for the two Tri Expenses scheduled jobs.
 *
 * Safe to schedule daily: the digest is gated by Melbourne day-of-month (catch-up window) and
 * its manager+month idempotency key; the purge only touches rows past the 30-day window and is
 * a dry run unless $purgeApply. Each job takes its own run-lock, writes a last-run record and
 * emits one structured JSON log line.
 *
 * Exit codes: 0 ok (including "not due"), 1 job failure (isolated: other job still runs),
 * 5 lock held by another instance (nothing done).
 */
final class ExpenseSchedulerRunner
{
    public const JOB_DIGEST = 'digest';
    public const JOB_PURGE = 'purge';
    public const EXIT_OK = 0;
    public const EXIT_FAILED = 1;
    public const EXIT_LOCKED = 5;

    /** @param callable(array<string, mixed>):void $log */
    public function __construct(
        private readonly MonthlyManagerDigestService $digest,
        private readonly PurgeExpenseReceiptService $purge,
        private readonly ExpenseSchedulerLock $lock,
        private readonly ExpenseSchedulerRunStore $store,
        private readonly Clock $clock,
        private $log,
    ) {
    }

    /**
     * @param list<string> $jobs
     * @return array{exitCode: int, results: array<string, array<string, mixed>>}
     */
    public function run(array $jobs, bool $purgeApply = false, int $catchUpDays = 7): array
    {
        $results = [];
        $exit = self::EXIT_OK;
        foreach ($jobs as $job) {
            $result = $this->runOne($job, $purgeApply, $catchUpDays);
            $results[$job] = $result;
            $code = $result['exitCode'];
            if (is_int($code)) {
                $exit = max($exit, $code);
            }
        }

        return ['exitCode' => $exit, 'results' => $results];
    }

    /** @return array<string, mixed> */
    private function runOne(string $job, bool $purgeApply, int $catchUpDays): array
    {
        $startedAt = $this->clock->now()->format(DATE_ATOM);
        $release = $this->lock->acquire($job);
        if ($release === null) {
            $r = ['job' => $job, 'status' => 'locked', 'exitCode' => self::EXIT_LOCKED, 'startedAt' => $startedAt];
            ($this->log)(['event' => 'expense_scheduler', 'level' => 'warning'] + $r);

            return $r; // do not overwrite the holder's last-run record
        }

        try {
            $detail = match ($job) {
                self::JOB_DIGEST => $this->digestSummary($catchUpDays),
                self::JOB_PURGE => $this->purgeSummary($purgeApply),
                default => throw new \InvalidArgumentException('Unknown scheduler job: ' . $job),
            };
            $failed = $detail['failed'];
            $r = [
                'job' => $job,
                'status' => $failed > 0 ? 'failed' : 'ok',
                'exitCode' => $failed > 0 ? self::EXIT_FAILED : self::EXIT_OK,
                'startedAt' => $startedAt,
                'finishedAt' => $this->clock->now()->format(DATE_ATOM),
            ] + $detail;
        } catch (Throwable $e) {
            $r = [
                'job' => $job,
                'status' => 'failed',
                'exitCode' => self::EXIT_FAILED,
                'startedAt' => $startedAt,
                'finishedAt' => $this->clock->now()->format(DATE_ATOM),
                'failed' => 1,
                'error' => get_class($e),
            ];
        } finally {
            $release();
        }

        try {
            $this->store->record($job, $r);
        } catch (Throwable) {
            $r['healthRecordWritten'] = false;
        }
        ($this->log)(['event' => 'expense_scheduler', 'level' => $r['exitCode'] === 0 ? 'info' : 'error'] + $r);

        return $r;
    }

    /** @return array<string, mixed> */
    private function digestSummary(int $catchUpDays): array
    {
        $d = $this->digest->execute(false, null, $catchUpDays);

        return [
            'ran' => $d['ran'],
            'skippedReason' => $d['skippedReason'],
            'periodKey' => $d['periodKey'],
            'localDate' => $d['localDate'],
            'managersConsidered' => $d['managersConsidered'],
            'sent' => $d['sent'],
            'alreadySent' => $d['alreadySent'],
            'skipped' => $d['skipped'],
            'failed' => $d['failed'],
        ];
    }

    /** @return array<string, mixed> */
    private function purgeSummary(bool $apply): array
    {
        $p = $this->purge->purgeExpired(!$apply);
        $failed = count(array_filter($p['receipts'], static fn (array $row): bool => $row['action'] === 'failed'));

        return [
            'dryRun' => $p['dryRun'],
            'cutoffUtc' => $p['cutoffUtc'],
            'eligible' => $p['eligible'],
            'deleted' => $p['deleted'],
            'blocked' => $p['blocked'],
            'failed' => $failed,
        ];
    }
}
