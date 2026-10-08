<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Odoo;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Jobs\InMemoryFinanceJobClaims;
use TriCab\Api\Finance\Odoo\InMemoryOdooRecordStore;
use TriCab\Api\Finance\Odoo\InMemoryOdooSyncRuns;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Doubles\StubOdooClient;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobOutcome;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRunner;
use TriCab\Core\Finance\Shared\Odoo\OdooError;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncConfig;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncJob;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRun;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncService;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * The daily job: once a day, always a full sweep, and the F1.8 runner's guarantees on top.
 */
final class OdooSyncJobTest extends TestCase
{
    private StubOdooClient $odoo;

    private InMemoryOdooRecordStore $records;

    private InMemoryOdooSyncRuns $runs;

    private FixedClock $clock;

    private OdooSyncJob $job;

    protected function setUp(): void
    {
        $this->odoo = new StubOdooClient();
        $this->records = new InMemoryOdooRecordStore();
        $this->runs = new InMemoryOdooSyncRuns();
        $this->clock = FixedClock::utc('2026-10-06 16:00:00');
        $passThrough = new class () implements ApplicationTransaction {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };
        $this->job = new OdooSyncJob(new OdooSyncService($this->odoo, $this->records, $this->runs, $passThrough, $this->clock, new OdooSyncConfig([2], '2027-01-31')));
        $this->odoo->put('res.partner', ['id' => 1, 'name' => 'Zeta Supplies', 'ref' => 'V-001', 'active' => true, 'write_date' => '2026-10-01 01:00:00']);
        $this->odoo->put('res.company', ['id' => 2, 'name' => 'Example AU Pty Ltd', 'active' => true, 'write_date' => '2026-09-01 00:00:00']);
    }

    public function test_it_runs_once_a_day_at_three_in_the_morning_melbourne_time(): void
    {
        self::assertSame('odoo.sync', $this->job->name());
        $schedule = $this->job->schedule();

        // 3 am on 7 Oct 2026 in Melbourne (AEDT, UTC+11) is 16:00 UTC the day before.
        self::assertNull($schedule->dueSlot(new DateTimeImmutable('2026-10-06 15:59:00', new DateTimeZone('UTC'))), 'before 03:00 Melbourne on the 7th there is no slot for the 7th');
        $slot = $schedule->dueSlot(new DateTimeImmutable('2026-10-06 16:00:00', new DateTimeZone('UTC')));
        self::assertNotNull($slot);
        self::assertSame($slot, $schedule->dueSlot(new DateTimeImmutable('2026-10-07 02:00:00', new DateTimeZone('UTC'))), 'the same day, the same slot');
        self::assertStringContainsString('Australia/Melbourne', (string) $slot);
    }

    public function test_it_always_sweeps_in_full_and_reports_counts_and_the_keys_days_left(): void
    {
        $facts = $this->job->run($this->clock->now());

        self::assertSame('succeeded', $facts['status']);
        self::assertSame(OdooSyncRun::MODE_FULL, $facts['mode']);
        self::assertSame(1, $facts['vendors_added']);
        self::assertSame(1, $facts['companies_added']);
        self::assertSame(0, $facts['cost_centres_fetched'], 'list keys with a dash become underscores');
        self::assertSame(117, $facts['keyDaysLeft']);
        self::assertFalse($facts['keyWarning']);
        self::assertSame(OdooSyncRun::TRIGGER_SCHEDULE, $this->runs->recent(1)[0]->triggeredBy);
        self::assertNull($this->runs->recent(1)[0]->actorUserId, 'a scheduled run has no person');
        // Facts are counts and flags, never content: FinanceJob::run() is typed to scalars.
        self::assertArrayNotHasKey('name', $facts);
    }

    public function test_a_second_pass_removes_a_record_gone_from_odoo_because_it_is_a_full_sweep(): void
    {
        $this->job->run($this->clock->now());
        $this->odoo->remove('res.partner', 1);
        $this->clock->advance('+1 day');

        $facts = $this->job->run($this->clock->now());

        self::assertSame(1, $facts['vendors_removed']);
    }

    public function test_the_key_warning_is_in_the_facts_once_the_key_is_within_30_days(): void
    {
        $passThrough = new class () implements ApplicationTransaction {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };
        $job = new OdooSyncJob(new OdooSyncService($this->odoo, $this->records, $this->runs, $passThrough, $this->clock, new OdooSyncConfig([2], '2026-11-05')));

        $facts = $job->run($this->clock->now());

        self::assertSame(30, $facts['keyDaysLeft']);
        self::assertTrue($facts['keyWarning']);
    }

    public function test_a_failed_sync_fails_the_job_with_the_reason_and_the_key_warning_so_the_audit_row_says_so(): void
    {
        $this->odoo->failures['res.partner'] = new OdooError('Odoo res.partner.search_read returned HTTP 401: Invalid API key');

        try {
            $this->job->run($this->clock->now());
            self::fail('A failed sync must fail the job.');
        } catch (RuntimeException $failure) {
            self::assertSame('The Odoo sync failed: Odoo res.partner.search_read returned HTTP 401: Invalid API key', $failure->getMessage());
        }
        self::assertSame(OdooSyncRun::STATUS_FAILED, $this->runs->recent(1)[0]->status);
    }

    public function test_a_failure_close_to_the_keys_expiry_says_so_in_its_message(): void
    {
        $passThrough = new class () implements ApplicationTransaction {
            public function run(callable $operation): mixed
            {
                return $operation();
            }
        };
        $job = new OdooSyncJob(new OdooSyncService($this->odoo, $this->records, $this->runs, $passThrough, $this->clock, new OdooSyncConfig([2], '2026-10-20')));
        $this->odoo->failures['res.partner'] = new OdooError('Odoo res.partner.search_read returned HTTP 401: Invalid API key');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('(the API key expires in 14 days)');
        $job->run($this->clock->now());
    }
    public function test_a_sync_already_running_is_skipped_quietly(): void
    {
        $this->runs->start('manual', OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_ADMIN, 7, $this->clock->now()->modify('-2 minutes'));

        $facts = $this->job->run($this->clock->now());

        self::assertSame('skipped', $facts['status']);
        self::assertSame([], $this->odoo->calls);
    }

    public function test_the_runner_runs_it_once_records_the_outcome_and_a_failure_does_not_stop_it_being_recorded(): void
    {
        $audit = new InMemoryFinanceAuditLog();
        $runner = new FinanceJobRunner(new FinanceJobRegistry([$this->job]), new InMemoryFinanceJobClaims(), $audit, fn (): DateTimeImmutable => $this->clock->now());

        $first = $runner->runDue();
        $second = $runner->runDue();

        self::assertSame(FinanceJobOutcome::RAN, $first[0]->status);
        self::assertSame(FinanceJobOutcome::ALREADY_CLAIMED, $second[0]->status, 'the slot was taken, so a second runner does nothing');
        self::assertSame('finance.job.succeeded', $audit->events[0]->action);
        self::assertSame('odoo.sync', $audit->events[0]->entityId);
        self::assertCount(1, $this->odoo->callsFor('res.partner'));

        $this->clock->advance('+1 day');
        $this->odoo->failures['res.company'] = new OdooError('Odoo res.company.search_read returned HTTP 500');
        $runner->runDue();
        self::assertSame('finance.job.failed', $audit->events[1]->action);
        $error = $audit->events[1]->details['error'] ?? null;
        self::assertIsString($error);
        self::assertStringContainsString('HTTP 500', $error);
    }
}
