<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Odoo;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TriCab\Api\Finance\Odoo\InMemoryOdooRecordStore;
use TriCab\Api\Finance\Odoo\InMemoryOdooSyncRuns;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Doubles\StubOdooClient;
use TriCab\Core\Finance\Shared\Odoo\OdooError;
use TriCab\Core\Finance\Shared\Odoo\OdooRecord;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncBusy;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncConfig;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRun;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncService;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * The sync's rules, against a stubbed Odoo and the in-memory twins: no Odoo, no key, no
 * network. Each rule is the portal's (`odoo_sync.py`: `run_sync`, `_sync_list`).
 */
final class OdooSyncServiceTest extends TestCase
{
    private StubOdooClient $odoo;

    private InMemoryOdooRecordStore $records;

    private InMemoryOdooSyncRuns $runs;

    private FixedClock $clock;

    private OdooSyncService $sync;

    protected function setUp(): void
    {
        $this->odoo = new StubOdooClient();
        $this->records = new InMemoryOdooRecordStore();
        $this->runs = new InMemoryOdooSyncRuns();
        $this->clock = FixedClock::utc('2026-10-07 03:00:00');
        $store = $this->records;
        $rollsBack = new class ($store) implements ApplicationTransaction {
            public function __construct(private readonly InMemoryOdooRecordStore $store)
            {
            }

            public function run(callable $operation): mixed
            {
                $snapshot = $this->store->snapshot();
                try {
                    return $operation();
                } catch (Throwable $failure) {
                    $this->store->restore($snapshot);
                    throw $failure;
                }
            }
        };
        $this->sync = new OdooSyncService($this->odoo, $this->records, $this->runs, $rollsBack, $this->clock, new OdooSyncConfig([2, 4], '2026-11-01'));

        $this->odoo->put('res.company', ['id' => 2, 'name' => 'Example AU Pty Ltd', 'currency_id' => [2, 'AUD'], 'country_id' => [13, 'Australia'], 'vat' => '11 222 333 444', 'active' => true, 'write_date' => '2026-09-01 00:00:00']);
        $this->odoo->put('res.company', ['id' => 4, 'name' => 'Example NZ Ltd', 'currency_id' => [3, 'NZD'], 'country_id' => [156, 'New Zealand'], 'vat' => false, 'active' => true, 'write_date' => '2026-09-02 00:00:00']);
        $this->odoo->put('res.partner', self::vendor(1, 'Zeta Supplies', 'V-001', '2026-10-01 01:00:00'));
        $this->odoo->put('res.partner', self::vendor(2, 'alpha traders', 'V-002', '2026-10-02 03:04:05'));
        $this->odoo->put('res.partner', self::vendor(3, 'Beta Freight', 'V-003', '2026-09-20 00:00:00'));
        $this->odoo->put('account.analytic.account', ['id' => 10, 'name' => 'Melbourne', 'code' => 'MEL', 'plan_id' => [1, 'Branch'], 'company_id' => [2, 'Example AU Pty Ltd'], 'active' => true, 'write_date' => '2026-09-05 00:00:00']);
        $this->odoo->put('account.account', ['id' => 100, 'code' => '200100', 'name' => 'Trade creditors', 'account_type' => 'liability_payable', 'company_ids' => [2, 4], 'active' => true, 'write_date' => '2026-09-06 00:00:00']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function vendor(int $id, string $name, string $ref, string $written, bool $active = true): array
    {
        return [
            'id' => $id, 'name' => $name, 'ref' => $ref, 'email' => $id === 1 ? 'billing@zeta.example' : false, 'vat' => false, 'phone' => false,
            'company_id' => [2, 'Example AU Pty Ltd'], 'is_company' => true, 'parent_id' => false, 'country_id' => [13, 'Australia'],
            'active' => $active, 'write_date' => $written,
        ];
    }

    /**
     * @param list<OdooRecord> $records
     * @return list<int>
     */
    private static function ids(array $records): array
    {
        return array_map(static fn (OdooRecord $record): int => $record->odooId, $records);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function test_a_full_sweep_copies_the_four_lists_with_the_portals_payloads(): void
    {
        $run = $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        self::assertSame(OdooSyncRun::STATUS_SUCCEEDED, $run->status, (string) $run->error);
        self::assertSame(['companies' => 2, 'vendors' => 3, 'cost-centres' => 1, 'accounts' => 1], $this->records->counts());
        self::assertSame(['fetched' => 3, 'added' => 3, 'updated' => 0, 'removed' => 0], $run->counts['vendors']);

        $vendor = $this->sync->list('vendors')[2];
        self::assertSame(
            ['name' => 'Zeta Supplies', 'ref' => 'V-001', 'email' => 'billing@zeta.example', 'vat' => '', 'phone' => '', 'company_id' => 2, 'company_name' => 'Example AU Pty Ltd', 'is_company' => true, 'parent_id' => null, 'parent_name' => '', 'country' => 'Australia'],
            $vendor->payload,
            'Odoo sends false for an empty field and [id, name] for a link',
        );
        self::assertSame(['odoo_id', 'active', 'deleted', 'write_date', 'synced_at'], array_slice(array_keys($vendor->toArray()), -5));
        self::assertSame('2026-10-01T01:00:00+00:00', $vendor->toArray()['write_date']);

        $company = $this->sync->list('companies')[0];
        self::assertSame(['name' => 'Example AU Pty Ltd', 'currency' => 'AUD', 'country' => 'Australia', 'vat' => '11 222 333 444', 'company_id' => 2], $company->payload);
        self::assertSame(['name' => 'Trade creditors', 'code' => '200100', 'account_type' => 'liability_payable', 'company_ids' => [2, 4]], $this->sync->list('accounts')[0]->payload);
    }

    public function test_each_list_asks_odoo_for_its_model_fields_and_domain(): void
    {
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        $company = $this->odoo->callsFor('res.company')[0];
        self::assertSame([['id', 'in', [2, 4]]], $company['domain'], 'only the configured companies');
        self::assertSame([['supplier_rank', '>', 0]], $this->odoo->callsFor('res.partner')[0]['domain'], 'vendors are partners with a supplier rank');
        self::assertSame([], $this->odoo->callsFor('account.analytic.account')[0]['domain']);
        self::assertContains('write_date', $this->odoo->callsFor('account.account')[0]['fields']);
        self::assertContains('active', $this->odoo->callsFor('res.partner')[0]['fields'], 'archived records must say so');
    }

    public function test_a_second_sweep_updates_and_adds_nothing(): void
    {
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);
        $this->clock->advance('+1 day');

        $run = $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        self::assertSame(['fetched' => 3, 'added' => 0, 'updated' => 3, 'removed' => 0], $run->counts['vendors']);
        self::assertSame(3, $this->records->counts()['vendors']);
    }

    public function test_only_a_full_sweep_notices_a_record_gone_from_odoo(): void
    {
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);
        $this->odoo->remove('res.partner', 3);
        $this->clock->advance('+1 hour');

        $incremental = $this->sync->run(OdooSyncRun::MODE_INCREMENTAL, OdooSyncRun::TRIGGER_ADMIN, 7);
        self::assertSame(0, $incremental->counts['vendors']['removed']);
        self::assertSame(3, $this->records->counts()['vendors'], 'an incremental pass cannot see a deletion');

        $this->clock->advance('+1 hour');
        $full = $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_ADMIN, 7);

        self::assertSame(1, $full->counts['vendors']['removed']);
        self::assertEqualsCanonicalizing([1, 2], self::ids($this->sync->list('vendors')));
        self::assertNotContains(3, self::ids($this->sync->list('vendors')), 'a removed vendor is not in the default list');
        $since = $this->sync->list('vendors', false, self::at('2026-10-07 04:30:00'));
        $gone = array_values(array_filter($since, static fn (OdooRecord $r): bool => $r->odooId === 3));
        self::assertCount(1, $gone, 'its row stays, so an invoice that points at it still resolves');
        self::assertTrue($gone[0]->isDeleted);
        self::assertSame('Beta Freight', $gone[0]->payload['name']);
    }

    public function test_a_removed_record_that_comes_back_is_live_again(): void
    {
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);
        $row = self::vendor(3, 'Beta Freight', 'V-003', '2026-09-20 00:00:00');
        $this->odoo->remove('res.partner', 3);
        $this->clock->advance('+1 hour');
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);
        self::assertSame(2, $this->records->counts()['vendors']);

        $this->odoo->put('res.partner', $row);
        $this->clock->advance('+1 hour');
        $run = $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        self::assertSame(['fetched' => 3, 'added' => 0, 'updated' => 3, 'removed' => 0], $run->counts['vendors'], 'the deleted row is updated back to life, not inserted twice');
        self::assertSame(3, $this->records->counts()['vendors']);
        self::assertContains(3, self::ids($this->sync->list('vendors')));
    }

    public function test_an_incremental_pass_reads_from_the_newest_write_date_held_inclusive(): void
    {
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);
        $this->odoo->calls = [];
        $this->odoo->put('res.partner', self::vendor(4, 'Gamma Parts', 'V-004', '2026-10-06 22:00:00'));
        $this->clock->advance('+1 hour');

        $run = $this->sync->run(OdooSyncRun::MODE_INCREMENTAL, OdooSyncRun::TRIGGER_ADMIN, 7);

        $vendors = $this->odoo->callsFor('res.partner')[0]['domain'];
        self::assertContains(['write_date', '>=', '2026-10-02 03:04:05'], $vendors, '>= and not >: write_date has one-second resolution');
        self::assertSame(['fetched' => 2, 'added' => 1, 'updated' => 1, 'removed' => 0], $run->counts['vendors'], 'the boundary record is read again, harmlessly, and the new one added');
        self::assertContains(['write_date', '>=', '2026-09-02 00:00:00'], $this->odoo->callsFor('res.company')[0]['domain'], 'each list has its own cursor');
    }

    public function test_an_incremental_pass_over_a_list_with_nothing_held_reads_it_all(): void
    {
        $run = $this->sync->run(OdooSyncRun::MODE_INCREMENTAL, OdooSyncRun::TRIGGER_ADMIN, 7);

        self::assertSame(3, $run->counts['vendors']['added']);
        foreach ($this->odoo->calls as $call) {
            foreach ($call['domain'] as $condition) {
                self::assertNotSame('write_date', $condition[0], 'no cursor yet, so no write_date condition');
            }
        }
    }

    public function test_archived_records_are_hidden_by_default_and_shown_when_asked(): void
    {
        $this->odoo->put('res.partner', self::vendor(5, 'Archived Co', 'V-005', '2026-10-03 00:00:00', false));
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        self::assertNotContains(5, self::ids($this->sync->list('vendors')));
        $all = $this->sync->list('vendors', true);
        self::assertContains(5, self::ids($all));
        $archived = array_values(array_filter($all, static fn (OdooRecord $r): bool => $r->odooId === 5))[0];
        self::assertFalse($archived->isActive);
        self::assertFalse($archived->isDeleted, 'archived is not deleted');
    }

    public function test_updated_since_returns_everything_synced_since_archived_and_removed_included(): void
    {
        $this->odoo->put('res.partner', self::vendor(5, 'Archived Co', 'V-005', '2026-10-03 00:00:00', false));
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);
        $this->clock->advance('+2 hours');
        $this->odoo->remove('res.partner', 3);
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        $first = $this->sync->list('vendors', false, self::at('2026-10-07 03:00:00'));
        $later = $this->sync->list('vendors', false, self::at('2026-10-07 05:00:00'));

        self::assertEqualsCanonicalizing([1, 2, 3, 5], self::ids($first));
        self::assertEqualsCanonicalizing([1, 2, 3, 5], self::ids($later), 'every record a full sweep touched, including the unchanged ones');
        self::assertCount(0, $this->sync->list('vendors', false, self::at('2026-10-07 06:00:00')));
    }

    public function test_the_list_is_ordered_by_name_ignoring_case_then_odoo_id(): void
    {
        $this->odoo->put('res.partner', self::vendor(9, 'ALPHA TRADERS', 'V-009', '2026-10-03 00:00:00'));
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        self::assertSame([2, 9, 3, 1], self::ids($this->sync->list('vendors')), 'alpha traders (2), ALPHA TRADERS (9), Beta Freight (3), Zeta Supplies (1)');
    }

    public function test_one_failing_list_leaves_the_copy_exactly_as_it_was(): void
    {
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);
        $before = $this->records->snapshot();
        $this->odoo->put('res.partner', self::vendor(7, 'New Vendor', 'V-007', '2026-10-06 00:00:00'));
        $this->odoo->failures['account.account'] = new OdooError('Odoo account.account.search_read returned HTTP 500: Internal Server Error');
        $this->clock->advance('+1 hour');

        $run = $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_ADMIN, 7);

        self::assertSame(OdooSyncRun::STATUS_FAILED, $run->status);
        self::assertSame('Odoo account.account.search_read returned HTTP 500: Internal Server Error', $run->error);
        self::assertSame([], $run->counts);
        self::assertEquals($before, $this->records->snapshot(), 'the vendor written before the failing list is rolled back too');
        self::assertNotContains(7, self::ids($this->sync->list('vendors')));
        self::assertSame(OdooSyncRun::STATUS_SUCCEEDED, $this->sync->lastSuccess()?->status, 'the earlier success is still the last one');
    }

    public function test_an_unexpected_failure_is_recorded_with_its_type(): void
    {
        $this->odoo->failures['res.partner'] = new RuntimeException('boom');

        $run = $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        self::assertSame(OdooSyncRun::STATUS_FAILED, $run->status);
        self::assertSame('RuntimeException: boom', $run->error);
    }

    public function test_a_record_with_no_id_fails_the_run(): void
    {
        $this->odoo->rows['res.partner'][] = ['name' => 'No id'];

        $run = $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        self::assertSame(OdooSyncRun::STATUS_FAILED, $run->status);
        self::assertSame('Odoo returned a record with no id.', $run->error);
    }

    public function test_a_run_in_progress_blocks_another_and_nothing_is_read(): void
    {
        self::assertTrue($this->runs->start('running-one', OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE, null, $this->clock->now()->modify('-5 minutes')));

        try {
            $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_ADMIN, 7);
            self::fail('A second run must not start.');
        } catch (OdooSyncBusy $busy) {
            self::assertSame('An Odoo sync is already running.', $busy->getMessage());
        }
        self::assertSame([], $this->odoo->calls);
        self::assertSame(OdooSyncRun::STATUS_RUNNING, $this->runs->find('running-one')?->status);
    }

    public function test_a_run_stuck_for_15_minutes_is_abandoned_and_the_new_one_goes_ahead(): void
    {
        $this->runs->start('stuck', OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE, null, $this->clock->now()->modify('-16 minutes'));

        $run = $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        self::assertSame(OdooSyncRun::STATUS_SUCCEEDED, $run->status);
        self::assertSame(OdooSyncRun::STATUS_ABANDONED, $this->runs->find('stuck')?->status);
        self::assertFalse($this->runs->finish('stuck', OdooSyncRun::STATUS_SUCCEEDED, [], null, $this->clock->now()), 'a late finish does not bring an abandoned run back');
        self::assertSame(OdooSyncRun::STATUS_ABANDONED, $this->runs->find('stuck')?->status);
    }

    public function test_a_run_14_minutes_old_is_still_running(): void
    {
        $this->runs->start('slow', OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE, null, $this->clock->now()->modify('-14 minutes'));

        $this->expectException(OdooSyncBusy::class);
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);
    }

    public function test_the_mode_and_the_trigger_must_be_known(): void
    {
        foreach ([['auto', OdooSyncRun::TRIGGER_ADMIN], ['', OdooSyncRun::TRIGGER_ADMIN], [OdooSyncRun::MODE_FULL, 'cron']] as [$mode, $trigger]) {
            try {
                $this->sync->run($mode, $trigger);
                self::fail("$mode/$trigger must be refused");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->runs->recent(10), 'a refused call records nothing');
    }

    public function test_the_run_records_who_started_it_and_how(): void
    {
        $run = $this->sync->run(OdooSyncRun::MODE_INCREMENTAL, OdooSyncRun::TRIGGER_ADMIN, 42);

        self::assertSame(42, $run->actorUserId);
        self::assertSame('admin', $run->toArray()['trigger']);
        self::assertNull($this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE)->actorUserId);
    }

    public function test_the_read_cursor_never_passes_a_run_still_going(): void
    {
        self::assertSame('2026-10-07T02:59:59+00:00', $this->sync->readCursor()->format('c'), 'one second ago when nothing is running');

        $this->runs->start('going', OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE, null, $this->clock->now()->modify('-10 minutes'));

        self::assertSame('2026-10-07T02:50:00+00:00', $this->sync->readCursor()->format('c'));
    }

    public function test_long_names_and_codes_are_cut_and_unicode_survives(): void
    {
        $this->odoo->put('res.partner', self::vendor(20, str_repeat('Ñ', 500), str_repeat('r', 150), '2026-10-03 00:00:00'));
        $this->odoo->put('res.partner', self::vendor(21, 'Café Müller 株式会社', 'V-21', '2026-10-03 00:00:00'));
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        $rows = $this->records->snapshot();
        self::assertSame(400, mb_strlen($rows['vendors|20']['row']->name));
        self::assertSame(100, mb_strlen((string) $rows['vendors|20']['row']->code));
        self::assertStringContainsString('Café Müller 株式会社', $rows['vendors|21']['row']->dataJson, 'stored as the characters, not \\u escapes');
        self::assertSame('Café Müller 株式会社', $this->nameOf(21));
    }

    private function nameOf(int $id): string
    {
        foreach ($this->sync->list('vendors', true) as $record) {
            if ($record->odooId === $id) {
                $name = $record->payload['name'] ?? '';

                return is_string($name) ? $name : '';
            }
        }

        return '';
    }

    public function test_an_unknown_list_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sync->list('suppliers');
    }

    public function test_the_status_reports_counts_the_last_success_recent_runs_and_the_keys_expiry(): void
    {
        $this->sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);

        $status = $this->sync->status();

        self::assertSame([2, 4], $status['company_ids']);
        self::assertSame('2026-11-01', $status['key_expires']);
        self::assertSame(25, $status['key_days_left']);
        self::assertTrue($status['key_expires_soon'], 'within 30 days');
        self::assertSame(['companies' => 2, 'vendors' => 3, 'cost-centres' => 1, 'accounts' => 1], $status['counts']);
        self::assertIsArray($status['last_success']);
        self::assertSame('succeeded', $status['last_success']['status']);
        self::assertIsArray($status['recent_runs']);
        self::assertCount(1, $status['recent_runs']);
        self::assertArrayNotHasKey('api_key', $status);
    }

    public function test_the_key_expiry_arithmetic(): void
    {
        $today = self::at('2026-10-07 23:59:59');

        self::assertSame(0, (new OdooSyncConfig([2], '2026-10-07'))->keyDaysLeft($today));
        self::assertSame(-1, (new OdooSyncConfig([2], '2026-10-06'))->keyDaysLeft($today), 'an expired key is negative');
        self::assertSame(30, (new OdooSyncConfig([2], '2026-11-06'))->keyDaysLeft($today));
        self::assertTrue((new OdooSyncConfig([2], '2026-11-06'))->keyExpiresSoon($today));
        self::assertFalse((new OdooSyncConfig([2], '2026-11-07'))->keyExpiresSoon($today));
        foreach (['', 'soon', '2026-13-45', '07/10/2026'] as $bad) {
            self::assertNull((new OdooSyncConfig([2], $bad))->keyDaysLeft($today), "'$bad' is not a date");
            self::assertFalse((new OdooSyncConfig([2], $bad))->keyExpiresSoon($today));
        }
    }
}
