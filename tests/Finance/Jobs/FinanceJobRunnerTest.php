<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Jobs;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Jobs\InMemoryFinanceJobClaims;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;
use TriCab\Core\Finance\Shared\Jobs\FinanceJob;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobClaims;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobOutcome;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRunner;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;

/**
 * The runner's rules: nothing due, a due job once, a failure recorded without stopping
 * the others, and two overlapping runners running a due slot once.
 */
final class FinanceJobRunnerTest extends TestCase
{
    private InMemoryFinanceJobClaims $claims;

    private InMemoryFinanceAuditLog $audit;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->claims = new InMemoryFinanceJobClaims();
        $this->audit = new InMemoryFinanceAuditLog();
        // 06:30 on 7 Oct 2026 in Melbourne (AEDT, UTC+11) is 19:30 UTC on the 6th.
        $this->now = new DateTimeImmutable('2026-10-06 19:30:00', new DateTimeZone('UTC'));
    }

    /**
     * @param list<FinanceJob> $jobs
     */
    private function runner(array $jobs, ?FinanceJobClaims $claims = null, ?FinanceAuditLog $audit = null): FinanceJobRunner
    {
        return new FinanceJobRunner(
            new FinanceJobRegistry($jobs),
            $claims ?? $this->claims,
            $audit ?? $this->audit,
            fn (): DateTimeImmutable => $this->now,
        );
    }

    /**
     * @param list<FinanceJobOutcome> $outcomes
     * @return array<string, string>
     */
    private static function statuses(array $outcomes): array
    {
        $out = [];
        foreach ($outcomes as $outcome) {
            $out[$outcome->job] = $outcome->status;
        }

        return $out;
    }

    public function test_with_no_jobs_registered_a_pass_does_nothing(): void
    {
        self::assertSame([], $this->runner([])->runDue());
        self::assertSame([], $this->audit->events);
    }

    public function test_a_job_that_is_not_due_is_not_run_claimed_or_recorded(): void
    {
        $job = new ScriptedFinanceJob('daily-summary', FinanceJobSchedule::dailyAt('07:00'), static fn (): array => ['sent' => 1]);

        $outcomes = $this->runner([$job])->runDue();

        self::assertSame(['daily-summary' => FinanceJobOutcome::NOT_DUE], self::statuses($outcomes));
        self::assertSame(0, $job->runs);
        self::assertSame([], $this->audit->events);
        self::assertNull($this->claims->row('daily-summary', 'anything'));
    }

    public function test_a_due_job_runs_once_and_is_recorded_on_the_claim_and_in_the_audit_log(): void
    {
        $job = new ScriptedFinanceJob('daily-summary', FinanceJobSchedule::dailyAt('06:00'), static fn (): array => ['sent' => 3, 'skipped' => 1]);
        $runner = $this->runner([$job]);

        $first = $runner->runDue();
        $second = $runner->runDue();

        self::assertSame(['daily-summary' => FinanceJobOutcome::RAN], self::statuses($first));
        self::assertSame(['daily-summary' => FinanceJobOutcome::ALREADY_CLAIMED], self::statuses($second));
        self::assertSame(1, $job->runs);

        $slot = $first[0]->slot;
        self::assertSame('D2026-10-07@06:00@Australia/Melbourne', $slot);
        self::assertSame(['status' => 'succeeded', 'error' => null, 'summary' => ['sent' => 3, 'skipped' => 1]], $this->claims->row('daily-summary', (string) $slot));

        self::assertCount(1, $this->audit->events);
        $event = $this->audit->events[0];
        self::assertSame('finance.job.succeeded', $event->action);
        self::assertSame(['job', 'daily-summary', 'FINANCE', null], [$event->entityType, $event->entityId, $event->application, $event->actorUserId]);
        self::assertSame($slot, $event->details['slot']);
        self::assertSame(['sent' => 3, 'skipped' => 1], $event->details['summary']);
        self::assertArrayHasKey('durationMs', $event->details);
    }

    public function test_the_next_days_slot_runs_again(): void
    {
        $job = new ScriptedFinanceJob('daily-summary', FinanceJobSchedule::dailyAt('06:00'), static fn (): array => []);
        $runner = $this->runner([$job]);
        $runner->runDue();

        $this->now = $this->now->modify('+1 day');
        $runner->runDue();

        self::assertSame(2, $job->runs);
    }

    public function test_a_failing_job_is_recorded_and_the_next_job_still_runs(): void
    {
        $failing = new ScriptedFinanceJob('a-failing', FinanceJobSchedule::dailyAt('06:00'), static function (): array {
            throw new RuntimeException(str_repeat('x', 700));
        });
        $fine = new ScriptedFinanceJob('b-fine', FinanceJobSchedule::dailyAt('06:00'), static fn (): array => ['ok' => true]);
        $alsoFine = new ScriptedFinanceJob('c-fine', FinanceJobSchedule::everyMinutes(15), static fn (): array => []);

        $outcomes = $this->runner([$alsoFine, $fine, $failing])->runDue();

        self::assertSame(
            ['a-failing' => FinanceJobOutcome::FAILED, 'b-fine' => FinanceJobOutcome::RAN, 'c-fine' => FinanceJobOutcome::RAN],
            self::statuses($outcomes),
            'jobs run in name order whatever order they were registered in',
        );
        self::assertSame([1, 1, 1], [$failing->runs, $fine->runs, $alsoFine->runs]);

        $failedSlot = (string) $outcomes[0]->slot;
        $row = $this->claims->row('a-failing', $failedSlot);
        self::assertSame('failed', $row['status'] ?? null);
        self::assertSame(500, strlen((string) ($row['error'] ?? '')));
        self::assertSame(
            ['finance.job.failed', 'finance.job.succeeded', 'finance.job.succeeded'],
            array_map(static fn (FinanceAuditEvent $e): string => $e->action, $this->audit->events),
        );
        $error = $this->audit->events[0]->details['error'] ?? null;
        self::assertIsString($error);
        self::assertSame(500, strlen($error));
    }

    public function test_a_failed_slot_is_not_retried_in_the_same_slot(): void
    {
        $failing = new ScriptedFinanceJob('flaky', FinanceJobSchedule::dailyAt('06:00'), static function (): array {
            throw new RuntimeException('no');
        });
        $runner = $this->runner([$failing]);

        $runner->runDue();
        $again = $runner->runDue();

        self::assertSame(1, $failing->runs);
        self::assertSame(FinanceJobOutcome::ALREADY_CLAIMED, $again[0]->status);
    }

    public function test_two_overlapping_runners_run_a_due_job_once(): void
    {
        // A slot swap runs two instances for a while. Here the second one starts a pass while the first is
        // in the middle of the job; both share the database, which is where the claim lives.
        /** @var FinanceJobRunner|null $second */
        $second = null;
        $job = new ScriptedFinanceJob('daily-summary', FinanceJobSchedule::dailyAt('06:00'), static function () use (&$second): array {
            if ($second instanceof FinanceJobRunner) {
                $second->runDue();
            }

            return ['sent' => 1];
        });
        $first = $this->runner([$job]);
        $second = $this->runner([$job], $this->claims, $this->audit);

        $outcomes = $first->runDue();

        self::assertSame(['daily-summary' => FinanceJobOutcome::RAN], self::statuses($outcomes));
        self::assertSame(1, $job->runs, 'the job body ran once although two runners passed over it');
        self::assertCount(1, $this->audit->events);
    }

    public function test_a_runner_that_died_holding_a_claim_is_taken_over_after_the_stale_period(): void
    {
        $job = new ScriptedFinanceJob('daily-summary', FinanceJobSchedule::dailyAt('06:00'), static fn (): array => ['sent' => 1]);
        $slot = (string) FinanceJobSchedule::dailyAt('06:00')->dueSlot($this->now);

        // A dead runner's claim: taken, never finished.
        $deadToken = $this->claims->claim('daily-summary', $slot, $this->now, FinanceJobRunner::STALE_AFTER_SECONDS);
        self::assertNotNull($deadToken);

        // Within the stale period it still holds the slot.
        $this->now = $this->now->modify('+29 minutes');
        self::assertSame(FinanceJobOutcome::ALREADY_CLAIMED, $this->runner([$job])->runDue()[0]->status);
        self::assertSame(0, $job->runs);

        // After it, the next runner takes over and runs the job.
        $this->now = $this->now->modify('+2 minutes');
        self::assertSame(FinanceJobOutcome::RAN, $this->runner([$job])->runDue()[0]->status);
        self::assertSame(1, $job->runs);

        // The dead runner coming back cannot overwrite the new owner's result.
        $this->claims->finish('daily-summary', $slot, $deadToken, false, 'late', [], $this->now);
        self::assertSame('succeeded', $this->claims->row('daily-summary', $slot)['status'] ?? null);
    }

    public function test_a_claim_that_cannot_be_taken_does_not_stop_the_other_jobs(): void
    {
        $claims = new class ($this->claims) implements FinanceJobClaims {
            public function __construct(private readonly InMemoryFinanceJobClaims $inner)
            {
            }

            public function claim(string $job, string $slot, DateTimeImmutable $now, int $staleAfterSeconds): ?string
            {
                if ($job === 'a-unlucky') {
                    throw new RuntimeException('database hiccup');
                }

                return $this->inner->claim($job, $slot, $now, $staleAfterSeconds);
            }

            public function finish(string $job, string $slot, string $token, bool $succeeded, ?string $error, array $summary, DateTimeImmutable $at): void
            {
                $this->inner->finish($job, $slot, $token, $succeeded, $error, $summary, $at);
            }
        };
        $unlucky = new ScriptedFinanceJob('a-unlucky', FinanceJobSchedule::dailyAt('06:00'), static fn (): array => []);
        $fine = new ScriptedFinanceJob('b-fine', FinanceJobSchedule::dailyAt('06:00'), static fn (): array => []);

        $outcomes = $this->runner([$unlucky, $fine], $claims)->runDue();

        self::assertSame(['a-unlucky' => FinanceJobOutcome::CLAIM_ERROR, 'b-fine' => FinanceJobOutcome::RAN], self::statuses($outcomes));
        self::assertSame([0, 1], [$unlucky->runs, $fine->runs]);
    }

    public function test_an_audit_row_that_cannot_be_written_does_not_stop_the_other_jobs(): void
    {
        $audit = new class implements FinanceAuditLog {
            public function record(FinanceAuditEvent $event): void
            {
                throw new RuntimeException('audit table down');
            }
        };
        $one = new ScriptedFinanceJob('a-one', FinanceJobSchedule::dailyAt('06:00'), static fn (): array => []);
        $two = new ScriptedFinanceJob('b-two', FinanceJobSchedule::dailyAt('06:00'), static fn (): array => []);

        $outcomes = $this->runner([$one, $two], null, $audit)->runDue();

        self::assertSame(['a-one' => FinanceJobOutcome::RAN, 'b-two' => FinanceJobOutcome::RAN], self::statuses($outcomes));
    }

    public function test_the_registry_refuses_a_duplicate_or_malformed_job_name(): void
    {
        $make = static fn (string $name): FinanceJob => new ScriptedFinanceJob($name, FinanceJobSchedule::everyMinutes(5), static fn (): array => []);

        foreach ([[$make('same'), $make('same')], [$make('Has Space')], [$make('')]] as $jobs) {
            try {
                new FinanceJobRegistry($jobs);
                self::fail('Accepted a bad registry.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_the_runner_in_the_container_has_no_jobs_in_phase_one(): void
    {
        $container = \TriCab\Api\Tests\TestAppFactory::create()->getContainer();
        $runner = $container->get(FinanceJobRunner::class);

        self::assertInstanceOf(FinanceJobRunner::class, $runner);
        self::assertSame([], $runner->runDue());
    }

    /** Documents the type the tests above rely on. */
    public function test_a_scripted_job_is_a_finance_job(): void
    {
        self::assertInstanceOf(FinanceJob::class, new ScriptedFinanceJob('x', FinanceJobSchedule::everyMinutes(1), static fn (): array => []));
        self::assertInstanceOf(Closure::class, static fn () => null);
    }
}
