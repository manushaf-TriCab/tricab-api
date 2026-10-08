<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Jobs;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;

/**
 * Runs the finance jobs that are due, once each.
 *
 * For each job, in name order: if its schedule has a slot due now, **claim**
 * `(job, slot)`; if the claim is taken (another runner has it, or the slot already
 * ran), skip it; otherwise run it, record the outcome on the claim and in
 * `finance.audit_events`. One job's failure, a claim that cannot be taken because the
 * database hiccupped, or an audit row that cannot be written, never stops the others.
 *
 * **Two runners never run a due slot twice** because the claim is one atomic row keyed
 * by job and slot (see {@see FinanceJobClaims}); the guarantee is the database's, not
 * this class's. A runner that dies holding a claim is taken over after
 * `STALE_AFTER_SECONDS`.
 */
final class FinanceJobRunner
{
    /** A claim still running after this long belongs to a runner that died. */
    public const STALE_AFTER_SECONDS = 1800;

    private const MAX_ERROR_LENGTH = 500;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $now;

    /**
     * @param (callable(): DateTimeImmutable)|null $now
     */
    public function __construct(
        private readonly FinanceJobRegistry $jobs,
        private readonly FinanceJobClaims $claims,
        private readonly FinanceAuditLog $audit,
        ?callable $now = null,
        private readonly int $staleAfterSeconds = self::STALE_AFTER_SECONDS,
    ) {
        $this->now = Closure::fromCallable($now ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * One pass.
     *
     * @return list<FinanceJobOutcome>
     */
    public function runDue(): array
    {
        $outcomes = [];
        foreach ($this->jobs->all() as $job) {
            $outcomes[] = $this->runOne($job);
        }

        return $outcomes;
    }

    private function runOne(FinanceJob $job): FinanceJobOutcome
    {
        $name = $job->name();
        $now = ($this->now)();

        try {
            $slot = $job->schedule()->dueSlot($now);
            if ($slot === null) {
                return new FinanceJobOutcome($name, FinanceJobOutcome::NOT_DUE);
            }
            $token = $this->claims->claim($name, $slot, $now, $this->staleAfterSeconds);
        } catch (Throwable $failure) {
            error_log(sprintf('finance job %s: could not take its claim: %s', $name, $failure::class));

            return new FinanceJobOutcome($name, FinanceJobOutcome::CLAIM_ERROR, null, self::short($failure->getMessage()));
        }
        if ($token === null) {
            return new FinanceJobOutcome($name, FinanceJobOutcome::ALREADY_CLAIMED, $slot);
        }

        $started = hrtime(true);
        $summary = [];
        $error = null;
        try {
            $summary = $job->run($now);
        } catch (Throwable $failure) {
            $error = self::short($failure->getMessage() !== '' ? $failure->getMessage() : $failure::class);
            error_log(sprintf('finance job %s failed (slot %s): %s', $name, $slot, $failure::class));
        }
        $finished = ($this->now)();

        try {
            $this->claims->finish($name, $slot, $token, $error === null, $error, $summary, $finished);
        } catch (Throwable $failure) {
            error_log(sprintf('finance job %s: could not record its result: %s', $name, $failure::class));
        }
        $this->audit($name, $slot, $error, $summary, (int) ((hrtime(true) - $started) / 1_000_000), $finished);

        return new FinanceJobOutcome($name, $error === null ? FinanceJobOutcome::RAN : FinanceJobOutcome::FAILED, $slot, $error);
    }

    /**
     * @param array<string, scalar|null> $summary
     */
    private function audit(string $name, string $slot, ?string $error, array $summary, int $durationMs, DateTimeImmutable $at): void
    {
        try {
            $this->audit->record(new FinanceAuditEvent(
                $error === null ? 'finance.job.succeeded' : 'finance.job.failed',
                'job',
                $name,
                ['slot' => $slot, 'durationMs' => $durationMs] + ($error === null ? ['summary' => $summary] : ['error' => $error]),
                $at,
            ));
        } catch (Throwable $failure) {
            error_log(sprintf('finance job %s: could not write its audit row: %s', $name, $failure::class));
        }
    }

    private static function short(string $message): string
    {
        return mb_substr($message, 0, self::MAX_ERROR_LENGTH);
    }
}
