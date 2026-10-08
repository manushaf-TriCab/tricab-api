<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Odoo;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;
use TriCab\Api\Finance\Odoo\AzureSqlOdooRecordStore;
use TriCab\Api\Finance\Odoo\AzureSqlOdooSyncRuns;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Shared\Persistence\SqlApplicationTransaction;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Doubles\StubOdooClient;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Shared\Odoo\OdooError;
use TriCab\Core\Finance\Shared\Odoo\OdooRecord;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordRow;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncConfig;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRun;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncService;

/**
 * The two Odoo tables over `create-finance-odoo-schema.sql`, against a real local SQL
 * Server ({@see LocalFinanceSqlServer}); skipped without one. This is where the portal's
 * 25-second ORDER BY is shown not to come back (the query plan), "two runs never overlap"
 * lives (the filtered unique index), and an all-or-nothing sync is shown over real rows.
 */
#[Group('local-sql-server')]
final class AzureSqlOdooTest extends TestCase
{
    private PDO $pdo;

    private PdoSqlConnection $connection;

    private AzureSqlOdooRecordStore $records;

    private AzureSqlOdooSyncRuns $runs;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->records = new AzureSqlOdooRecordStore($this->connection);
        $this->runs = new AzureSqlOdooSyncRuns($this->connection);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    private static function row(int $id, string $name, ?string $code = null, bool $active = true, ?string $written = '2026-10-01 01:02:03', string $list = 'vendors'): OdooRecordRow
    {
        return new OdooRecordRow($list, $id, $name, $code, 2, $active, json_encode(['name' => $name, 'ref' => $code ?? ''], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $written === null ? null : self::at($written));
    }

    /**
     * @param list<OdooRecord> $records
     * @return list<int>
     */
    private static function ids(array $records): array
    {
        return array_map(static fn (OdooRecord $record): int => $record->odooId, $records);
    }

    private function scalar(string $sql): mixed
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return $statement->fetchColumn();
    }

    public function test_a_record_round_trips_with_unicode_and_six_digit_times(): void
    {
        $row = new OdooRecordRow('vendors', 7, 'Café Müller 株式会社', 'V-7', 2, true, '{"name":"Café Müller 株式会社"}', new DateTimeImmutable('2026-10-01 01:02:03.123456', new DateTimeZone('UTC')));
        $this->records->insert($row, new DateTimeImmutable('2026-10-07 03:00:00.654321', new DateTimeZone('UTC')));

        $record = $this->records->list('vendors', false, null)[0];

        self::assertSame('Café Müller 株式会社', $record->payload['name']);
        self::assertSame('2026-10-01 01:02:03.123456', $record->writeDate?->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-10-07 03:00:00.654321', $record->syncedAt->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-10-01 01:02:03.123456', $this->records->maxWriteDate('vendors')?->format('Y-m-d H:i:s.u'));
        self::assertNull($this->records->maxWriteDate('accounts'));
    }

    public function test_existing_insert_update_remove_and_counts(): void
    {
        $now = self::at('2026-10-07 03:00:00');
        $this->records->insert(self::row(1, 'One'), $now);
        $this->records->insert(self::row(2, 'Two'), $now);
        $this->records->insert(self::row(3, 'Three', null, false), $now);
        $this->records->insert(self::row(1, 'Other list', null, true, null, 'accounts'), $now);

        self::assertSame([1 => false, 2 => false, 3 => false], self::sortedByKey($this->records->existing('vendors', null)));
        self::assertSame([2 => false], $this->records->existing('vendors', [2, 99]));
        self::assertSame(['companies' => 0, 'vendors' => 3, 'cost-centres' => 0, 'accounts' => 1], $this->records->counts());

        $this->records->markRemoved('vendors', [2], self::at('2026-10-08 03:00:00'));
        self::assertSame([1 => false, 2 => true, 3 => false], self::sortedByKey($this->records->existing('vendors', null)));
        self::assertSame(2, $this->records->counts()['vendors'], 'a removed record is not live');
        self::assertSame([1, 3], self::ids($this->records->list('vendors', true, null)));

        $this->records->update(self::row(2, 'Two again'), self::at('2026-10-09 03:00:00'));
        self::assertSame([2 => false], $this->records->existing('vendors', [2]), 'an update brings a removed record back');
        self::assertSame('Two again', $this->records->list('vendors', false, null)[1]->payload['name'] ?? $this->nameOf(2));
    }

    /**
     * `existing()` promises no order (SQL Server reads whichever index it likes).
     *
     * @param array<int, bool> $found
     * @return array<int, bool>
     */
    private static function sortedByKey(array $found): array
    {
        ksort($found);

        return $found;
    }

    private function nameOf(int $id): string
    {
        foreach ($this->records->list('vendors', true, null) as $record) {
            if ($record->odooId === $id) {
                $name = $record->payload['name'] ?? '';

                return is_string($name) ? $name : '';
            }
        }

        return '';
    }

    public function test_the_list_is_in_name_order_whatever_the_case_then_by_id(): void
    {
        $now = self::at('2026-10-07 03:00:00');
        foreach ([[1, 'Zeta Supplies'], [2, 'alpha traders'], [3, 'Beta Freight'], [4, 'ALPHA TRADERS'], [5, 'Émile & Co'], [6, '5 Star Cleaning']] as [$id, $name]) {
            $this->records->insert(self::row($id, $name), $now);
        }

        $names = array_map(static fn (OdooRecord $r): string => is_string($r->payload['name'] ?? null) ? $r->payload['name'] : '', $this->records->list('vendors', false, null));

        self::assertSame(['5 Star Cleaning', 'alpha traders', 'ALPHA TRADERS', 'Beta Freight', 'Émile & Co', 'Zeta Supplies'], $names, 'the database collation orders it: case-insensitive, equal names by Odoo id');
    }

    public function test_the_list_filters_live_active_and_updated_since(): void
    {
        $this->records->insert(self::row(1, 'Live'), self::at('2026-10-07 03:00:00'));
        $this->records->insert(self::row(2, 'Archived', null, false), self::at('2026-10-07 03:00:00'));
        $this->records->insert(self::row(3, 'Removed'), self::at('2026-10-07 03:00:00'));
        $this->records->markRemoved('vendors', [3], self::at('2026-10-08 03:00:00'));

        self::assertSame([1], self::ids($this->records->list('vendors', false, null)));
        self::assertSame([2, 1], self::ids($this->records->list('vendors', true, null)), 'Archived before Live: by name');
        self::assertSame([3], self::ids($this->records->list('vendors', false, self::at('2026-10-08 00:00:00'))), 'since a time: whatever was synced then, removed included');
        self::assertEqualsCanonicalizing([1, 2, 3], self::ids($this->records->list('vendors', false, self::at('2026-10-07 03:00:00'))));
        $removed = $this->records->list('vendors', false, self::at('2026-10-08 00:00:00'))[0];
        self::assertTrue($removed->isDeleted);
    }

    public function test_more_ids_than_one_statement_can_carry_are_handled_in_chunks(): void
    {
        $now = self::at('2026-10-07 03:00:00');
        $this->pdo->beginTransaction();
        for ($id = 1; $id <= 2500; $id++) {
            $this->records->insert(self::row($id, sprintf('Vendor %05d', $id)), $now);
        }
        $this->pdo->commit();

        self::assertCount(2500, $this->records->existing('vendors', range(1, 2500)));
        self::assertCount(2500, $this->records->list('vendors', false, null));
        $this->records->markRemoved('vendors', range(1, 2500), self::at('2026-10-08 00:00:00'));
        self::assertSame(0, $this->records->counts()['vendors']);
    }

    public function test_the_ordering_query_never_sorts_the_payload_column(): void
    {
        $now = self::at('2026-10-07 03:00:00');
        for ($id = 1; $id <= 300; $id++) {
            $this->records->insert(self::row($id, sprintf('Vendor %05d', ($id * 7919) % 1000)), $now);
        }

        // The step-one query of AzureSqlOdooRecordStore::list(), as written there.
        $plan = $this->plan("SELECT [odoo_id], [is_active], [write_date], [deleted_at], [synced_at] FROM [finance].[odoo_records] WHERE [list_key] = N'vendors' AND [deleted_at] IS NULL AND [is_active] = 1 ORDER BY [name], [odoo_id]");

        self::assertStringNotContainsString('data_json', $plan->asXML() ?: '', 'the ordering query does not even mention the payload column');
        self::assertSame([], $this->sortsCarrying($plan, 'data_json'));
        $plan->registerXPathNamespace('p', 'http://schemas.microsoft.com/sqlserver/2004/07/showplan');
        self::assertSame([], $plan->xpath('//p:RelOp[@PhysicalOp="Sort" or @PhysicalOp="Top N Sort"]') ?: [], 'the covering name index gives the order: no Sort at all');

        // The control: sorting the payload does put it in a Sort, so the detector is not blind.
        $control = $this->plan('SELECT [odoo_id], [data_json] FROM [finance].[odoo_records] ORDER BY [data_json]');
        self::assertNotSame([], $this->sortsCarrying($control, 'data_json'), 'the check can see a sort that carries the payload column');
    }

    /**
     * @return list<string> the physical sort operators whose output list names the column
     */
    private function sortsCarrying(SimpleXMLElement $plan, string $column): array
    {
        $plan->registerXPathNamespace('p', 'http://schemas.microsoft.com/sqlserver/2004/07/showplan');
        $found = [];
        foreach ($plan->xpath('//p:RelOp[@PhysicalOp="Sort" or @PhysicalOp="Top N Sort"]') ?: [] as $sort) {
            $sort->registerXPathNamespace('p', 'http://schemas.microsoft.com/sqlserver/2004/07/showplan');
            $columns = $sort->xpath('./p:OutputList/p:ColumnReference/@Column') ?: [];
            foreach ($columns as $attribute) {
                if (trim((string) $attribute, '[]') === $column) {
                    $found[] = (string) $sort['PhysicalOp'];
                }
            }
        }

        return $found;
    }

    private function plan(string $query): SimpleXMLElement
    {
        // pdo_sqlsrv prepares statements by default, and a prepared statement under
        // SHOWPLAN_XML returns no plan; a direct query does.
        $this->pdo->setAttribute(PDO::SQLSRV_ATTR_DIRECT_QUERY, true);
        $this->pdo->exec('SET SHOWPLAN_XML ON');
        try {
            $statement = $this->pdo->query($query);
            self::assertInstanceOf(PDOStatement::class, $statement);
            $xml = (string) $statement->fetchColumn();
        } finally {
            $this->pdo->exec('SET SHOWPLAN_XML OFF');
            $this->pdo->setAttribute(PDO::SQLSRV_ATTR_DIRECT_QUERY, false);
        }
        $plan = simplexml_load_string($xml);
        self::assertInstanceOf(SimpleXMLElement::class, $plan, 'SQL Server returned a plan');

        return $plan;
    }

    public function test_only_one_run_may_be_running_and_a_late_finish_does_not_revive_an_abandoned_one(): void
    {
        $t = self::at('2026-10-07 03:00:00');
        self::assertTrue($this->runs->start(str_repeat('a', 32), 'full', 'schedule', null, $t));
        self::assertFalse($this->runs->start(str_repeat('b', 32), 'incremental', 'admin', 7, $t->modify('+1 minute')), 'a second run does not start while one is running');
        self::assertNull($this->runs->find(str_repeat('b', 32)));
        self::assertEquals($t, $this->runs->oldestRunningStart());

        try {
            $this->pdo->exec("INSERT INTO [finance].[odoo_sync_runs] ([id], [mode], [triggered_by], [status], [started_at]) VALUES (N'" . str_repeat('c', 32) . "', N'full', N'admin', N'running', SYSUTCDATETIME())");
            self::fail('The database must refuse a second running row, whoever writes it.');
        } catch (PDOException $refused) {
            self::assertStringContainsString('UX_finance_odoo_sync_runs_one_running', $refused->getMessage());
        }

        self::assertSame(0, $this->runs->abandonStale($t->modify('-15 minutes'), $t->modify('+20 minutes')), 'not stale: it started after the cutoff');
        self::assertSame(1, $this->runs->abandonStale($t->modify('+5 minutes'), $t->modify('+20 minutes')));
        self::assertSame('abandoned', $this->runs->find(str_repeat('a', 32))?->status);
        self::assertFalse($this->runs->finish(str_repeat('a', 32), 'succeeded', [], null, $t->modify('+21 minutes')), 'a late finish is ignored');
        self::assertSame('abandoned', $this->runs->find(str_repeat('a', 32))?->status);
        self::assertTrue($this->runs->start(str_repeat('d', 32), 'full', 'schedule', null, $t->modify('+22 minutes')), 'once abandoned, another may start');
        self::assertNull($this->runs->oldestRunningStart() === null ? 'x' : null);
    }

    public function test_runs_are_recorded_and_read_back_newest_first(): void
    {
        $t = self::at('2026-10-07 03:00:00');
        foreach ([['1', 'succeeded'], ['2', 'failed'], ['3', 'succeeded']] as [$n, $status]) {
            $id = str_repeat($n, 32);
            self::assertTrue($this->runs->start($id, $n === '3' ? 'incremental' : 'full', 'admin', 9, $t->modify("+$n hours")));
            self::assertTrue($this->runs->finish($id, $status, $status === 'succeeded' ? ['vendors' => ['fetched' => 5, 'added' => 5, 'updated' => 0, 'removed' => 0]] : [], $status === 'failed' ? 'Odoo res.partner.search_read returned HTTP 500' : null, $t->modify("+$n hours +1 minute")));
        }

        self::assertSame([str_repeat('3', 32), str_repeat('2', 32), str_repeat('1', 32)], array_map(static fn (OdooSyncRun $r): string => $r->id, $this->runs->recent(10)));
        self::assertSame(str_repeat('3', 32), $this->runs->lastSuccess()?->id);
        self::assertSame(str_repeat('1', 32), $this->runs->lastSuccess('full')?->id);
        $failed = $this->runs->find(str_repeat('2', 32));
        self::assertSame('Odoo res.partner.search_read returned HTTP 500', $failed?->error);
        self::assertSame(9, $failed->actorUserId);
        self::assertSame(5, $this->runs->find(str_repeat('1', 32))?->counts['vendors']['added']);
        self::assertCount(2, $this->runs->recent(2));
    }

    public function test_the_tables_refuse_a_bad_mode_trigger_or_status(): void
    {
        foreach ([
            "VALUES (N'e1', N'auto', N'admin', N'succeeded')",
            "VALUES (N'e2', N'full', N'cron', N'succeeded')",
            "VALUES (N'e3', N'full', N'admin', N'overdue')",
        ] as $values) {
            try {
                $this->pdo->exec('INSERT INTO [finance].[odoo_sync_runs] ([id], [mode], [triggered_by], [status]) ' . $values);
                self::fail("$values must be refused");
            } catch (PDOException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_a_sync_over_the_real_tables_is_all_or_nothing(): void
    {
        $odoo = new StubOdooClient();
        $odoo->put('res.company', ['id' => 2, 'name' => 'Example AU', 'active' => true, 'write_date' => '2026-09-01 00:00:00']);
        $odoo->put('res.partner', ['id' => 1, 'name' => 'Zeta', 'ref' => 'V-1', 'active' => true, 'write_date' => '2026-10-01 00:00:00']);
        $odoo->put('account.account', ['id' => 100, 'code' => '200100', 'name' => 'Creditors', 'company_ids' => [2], 'active' => true, 'write_date' => '2026-09-06 00:00:00']);
        $clock = FixedClock::utc('2026-10-07 03:00:00');
        $sync = new OdooSyncService($odoo, $this->records, $this->runs, new SqlApplicationTransaction($this->connection), $clock, new OdooSyncConfig([2], ''));

        $first = $sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_SCHEDULE);
        self::assertSame('succeeded', $first->status, (string) $first->error);
        self::assertSame(['companies' => 1, 'vendors' => 1, 'cost-centres' => 0, 'accounts' => 1], $this->records->counts());

        $odoo->put('res.partner', ['id' => 2, 'name' => 'Written then rolled back', 'ref' => 'V-2', 'active' => true, 'write_date' => '2026-10-06 00:00:00']);
        $odoo->failures['account.account'] = new OdooError('Odoo account.account.search_read returned HTTP 500');
        $clock->advance('+1 day');
        $second = $sync->run(OdooSyncRun::MODE_FULL, OdooSyncRun::TRIGGER_ADMIN, 7);

        self::assertSame('failed', $second->status);
        self::assertSame(1, $this->records->counts()['vendors'], 'the vendor written before the failing list is not in the table');
        self::assertEquals(1, $this->scalar("SELECT COUNT(*) FROM [finance].[odoo_records] WHERE [list_key] = N'vendors'"));
        self::assertSame('failed', $this->runs->find($second->id)?->status, 'the failure itself is recorded, outside the rolled-back transaction');
        self::assertSame($first->id, $this->runs->lastSuccess()?->id);
    }
}
