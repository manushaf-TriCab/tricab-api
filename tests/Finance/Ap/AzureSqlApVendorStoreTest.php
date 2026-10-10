<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap\AzureSqlApAuditStore;
use TriCab\Api\Finance\Ap\AzureSqlApInvoiceStore;
use TriCab\Api\Finance\Ap\AzureSqlApReferenceStore;
use TriCab\Api\Finance\Ap\AzureSqlApVendorStore;
use TriCab\Api\Finance\Odoo\AzureSqlOdooRecordStore;
use TriCab\Api\Finance\Odoo\AzureSqlOdooSyncRuns;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ap\Domain\ApReferenceRow;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Ap\Service\ApVendorService;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordRow;

/**
 * The vendor store over `finance.odoo_records`, `finance.odoo_sync_runs` and the invoice tables, against a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one.
 */
#[Group('local-sql-server')]
final class AzureSqlApVendorStoreTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlOdooRecordStore $records;

    private AzureSqlOdooSyncRuns $runs;

    private AzureSqlApInvoiceStore $invoices;

    private AzureSqlApReferenceStore $references;

    private AzureSqlApVendorStore $store;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->records = new AzureSqlOdooRecordStore($connection);
        $this->runs = new AzureSqlOdooSyncRuns($connection);
        $this->invoices = new AzureSqlApInvoiceStore($connection);
        $this->references = new AzureSqlApReferenceStore($connection);
        $this->store = new AzureSqlApVendorStore(
            $this->records,
            $this->runs,
            $this->invoices,
            $this->references,
            new ApTime(FixedClock::utc('2026-10-09 01:02:03.456789'), 'Australia/Melbourne'),
        );
    }

    private static function at(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    private static function id(string $name): string
    {
        return substr(md5($name), 0, 32);
    }

    private function vendor(int $id, string $name, string $ref = '', bool $active = true, string $companyName = '', string $list = 'vendors'): void
    {
        $payload = json_encode(['name' => $name, 'ref' => $ref, 'email' => 'x@' . $id . '.example', 'vat' => '', 'company_name' => $companyName], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->records->insert(new OdooRecordRow($list, $id, $name, $ref === '' ? null : $ref, null, $active, $payload, self::at('2026-10-01 00:00:00')), self::at('2026-10-02 00:00:00'));
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    public function test_vendors_are_the_vendors_list_not_removed_archived_flagged_ordered_by_name_then_id(): void
    {
        $this->vendor(5, 'beta Co');
        $this->vendor(3, 'Alpha Co');
        $this->vendor(2, 'Alpha Co', active: false);
        $this->vendor(9, 'Gone Ltd');
        $this->vendor(7, 'A cost centre', list: 'cost-centres');
        $this->records->markRemoved('vendors', [9], self::at('2026-10-03 00:00:00'));

        $vendors = $this->store->vendors();

        self::assertSame([2, 3, 5], array_map(static fn ($vendor): int => $vendor->odooId, $vendors), 'case-insensitive by name, then Odoo id');
        self::assertSame([false, true, true], array_map(static fn ($vendor): bool => $vendor->active, $vendors));
        self::assertSame('x@3.example', $vendors[1]->email);
    }

    public function test_nothing_synced_is_an_empty_list_and_no_time(): void
    {
        self::assertSame([], $this->store->vendors());
        self::assertNull($this->store->vendorsSyncedAt());
    }

    public function test_synced_at_is_when_the_last_successful_run_finished(): void
    {
        self::assertTrue($this->runs->start('a', 'full', 'schedule', null, self::at('2026-10-05 00:00:00')));
        $this->runs->finish('a', 'succeeded', [], null, self::at('2026-10-05 00:10:00'));
        self::assertTrue($this->runs->start('b', 'incremental', 'schedule', null, self::at('2026-10-06 00:00:00')));
        $this->runs->finish('b', 'failed', [], 'boom', self::at('2026-10-06 00:10:00'));
        self::assertTrue($this->runs->start('c', 'incremental', 'schedule', null, self::at('2026-10-07 00:00:00')));

        self::assertEquals(self::at('2026-10-05 00:10:00'), $this->store->vendorsSyncedAt(), 'a failed or running run does not count');

        $this->runs->finish('c', 'succeeded', [], null, self::at('2026-10-07 00:10:00'));
        self::assertEquals(self::at('2026-10-07 00:10:00'), $this->store->vendorsSyncedAt());
    }

    public function test_counts_and_the_one_write_go_through_the_invoice_tables(): void
    {
        $this->invoices->insert(ApInvoiceFixtures::invoice(self::id('a'), odooVendorId: 2, vendorMatch: 'matched'));
        $this->invoices->insert(ApInvoiceFixtures::invoice(self::id('b'), odooVendorId: 2, vendorMatch: 'matched'));
        $this->invoices->insert(ApInvoiceFixtures::invoice(self::id('c'), odooVendorId: 2, vendorMatch: 'matched', deletedAt: self::at('2026-10-08 00:00:00')));
        $this->invoices->insert(ApInvoiceFixtures::invoice(self::id('d')));
        self::assertSame([2 => 2], $this->store->invoiceCountsByVendor());

        $this->store->setInvoiceVendor(self::id('d'), new ApVendorMatch(9, 'V9', 'Civic', ApVendorMatch::CONFIRMED, 'Chosen by x'), 41);
        $row = $this->invoices->find(self::id('d'));
        self::assertSame([9, 'V9', 'Civic', 'confirmed', 'Chosen by x', 41], [$row?->odooVendorId, $row?->vendorRef, $row?->odooVendorName, $row?->vendorMatch, $row?->vendorMatchNote, $row?->vendorMatchByUserId]);

        $this->store->setInvoiceVendor(self::id('d'), new ApVendorMatch(null, null, null, ApVendorMatch::NEW, 'n'));
        self::assertNull($this->invoices->find(self::id('d'))?->vendorMatchByUserId, 'an automatic write records no person');
    }

    public function test_active_companies_are_the_active_named_ones_with_their_former_names(): void
    {
        foreach ([['TriCab (Australia) Pty Ltd', 'Old Tri; Older', true], ['Dormant Ltd', '', false]] as $index => [$name, $former, $active]) {
            $this->references->insert(new ApReferenceRow('companies', self::id($name), ['code' => '', 'name' => $name, 'sites' => '', 'former_names' => $former], $active, self::at('2026-10-0' . ($index + 1) . ' 00:00:00')));
        }

        self::assertSame([['name' => 'TriCab (Australia) Pty Ltd', 'former_names' => 'Old Tri; Older']], $this->store->activeCompanies());
    }

    public function test_the_vendor_service_runs_a_whole_cycle_on_the_real_tables(): void
    {
        $service = new ApVendorService($this->store);
        self::assertNull($service->autoMatch(ApVendorFixtures::invoice('x', vendor: 'Zend Limited')), 'nothing synced: untried');

        $this->vendor(2, 'Zend Limited', 'V000002');
        $this->invoices->insert(ApInvoiceFixtures::invoice(self::id('a'), vendor: 'Zend Limited'));
        $result = $service->run([ApVendorFixtures::invoice(self::id('a'), vendor: 'Zend Limited')]);

        self::assertSame(['checked' => 1, 'matched' => 1, 'unmatched' => 0], $result);
        self::assertSame(2, $this->invoices->find(self::id('a'))?->odooVendorId);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[invoice_invoices] WHERE [vendor_match] = 'matched'"));
        self::assertSame([2 => 1], $this->store->invoiceCountsByVendor());
    }
}
