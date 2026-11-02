<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Odoo;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap2\InMemoryAp2SettingsStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2SupplierStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2Transactions;
use TriCab\Api\Finance\Ap2\Odoo\CachedAp2OdooLedgerSource;
use TriCab\Api\Finance\Ap2\Odoo\HttpAp2OdooLedgerSource;
use TriCab\Api\Finance\Ap2\Odoo\InMemoryAp2OdooLedgerSource;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Ap2\Ap2Fixtures;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Settings;
use TriCab\Core\Finance\Ap2\Domain\Ap2Upstream;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooVendor;
use TriCab\Core\Finance\Ap2\Service\Ap2OdooLedgerService;
use TriCab\Core\Finance\Ap2\Service\Ap2SupplierService;
use TriCab\Core\Finance\Ap2\Service\Ap2Time;
use TriCab\Core\Finance\Module;

/** Suppliers, Aged payables and Supplier statements from Odoo's payables (2 Nov 2026). */
final class Ap2OdooLedgerTest extends TestCase
{
    private InMemoryAp2OdooLedgerSource $odoo;

    private InMemoryAp2SupplierStore $suppliers;

    private Ap2OdooLedgerService $ledger;

    protected function setUp(): void
    {
        $this->odoo = new InMemoryAp2OdooLedgerSource();
        $this->suppliers = new InMemoryAp2SupplierStore();
        $settings = new InMemoryAp2SettingsStore(new Ap2Settings(fxRates: ['AUD' => 1.0, 'SGD' => 1.2, 'NZD' => 0.9]));
        $time = new Ap2Time(FixedClock::utc('2026-11-02 01:00:00'), 'Australia/Melbourne');
        $this->ledger = new Ap2OdooLedgerService($this->odoo, $this->suppliers, $settings, new InMemoryAp2Transactions(), $time);
        $this->odoo->vendors = [
            501 => new Ap2OdooVendor(501, 'Karya Sumiden', 'ap@karya.example', '+62 1', 'Manufacturing', 'AU', 60, '60 Days'),
            502 => new Ap2OdooVendor(502, 'GJourney Services', entity: 'SG', terms: null, termsName: 'End of Following Month'),
        ];
    }

    public function test_every_odoo_supplier_gets_a_tri_ap_supplier_and_odoo_fields_follow_odoo(): void
    {
        $existing = Ap2Fixtures::supplier('S001', 'Old name')->with(odooPartnerId: 502, terms: 14, hold: true, notes: 'Ours');
        $this->suppliers->insert($existing);
        $local = Ap2Fixtures::supplier('S002', 'Not in Odoo');
        $this->suppliers->insert($local);

        $all = $this->ledger->link($this->ledger->ledger());

        self::assertCount(3, $all);
        $byPartner = [];
        foreach ($all as $s) {
            $byPartner[$s->odooPartnerId ?? 0] = $s;
        }
        self::assertSame('Karya Sumiden', $byPartner[501]->name);
        self::assertSame(['ap@karya.example', '+62 1', 'Manufacturing', 'AU', 60], [$byPartner[501]->email, $byPartner[501]->phone, $byPartner[501]->industry, $byPartner[501]->entity, $byPartner[501]->terms]);
        self::assertSame($existing->id, $byPartner[502]->id, 'linked by partner id, not added twice');
        self::assertSame('GJourney Services', $byPartner[502]->name);
        self::assertSame(14, $byPartner[502]->terms, 'Odoo terms without a day count keep ours');
        self::assertTrue($byPartner[502]->hold, 'Tri-AP fields are untouched');
        self::assertSame('Ours', $byPartner[502]->notes);
        self::assertSame('Not in Odoo', $byPartner[0]->name);

        $again = $this->ledger->link($this->ledger->ledger());
        self::assertEquals($all, $again, 'a second read changes nothing');
    }

    public function test_positions_net_payments_flag_the_unmatched_ones_and_convert_to_aud(): void
    {
        $this->odoo->lines = [
            Ap2Fixtures::payable(1, 501, 1000.0, '2026-10-01', '2026-10-31'),
            Ap2Fixtures::payable(2, 501, 500.0, '2026-10-20', '2026-12-01'),
            Ap2Fixtures::payable(3, 501, -3000.0, '2026-09-01', type: 'entry'),
            Ap2Fixtures::payable(4, 501, 999.0, '2026-09-01', '2026-09-30', reconciled: true),
            Ap2Fixtures::payable(5, 502, 100.0, '2026-10-01', '2026-11-30', companyId: 1, currency: 'SGD'),
        ];
        $all = $this->ledger->link($this->ledger->ledger());
        $positions = $this->ledger->positions($this->ledger->ledger(), $all);
        $id = static fn (int $p): string => array_values(array_filter($all, static fn ($s) => $s->odooPartnerId === $p))[0]->id;

        self::assertEquals(['balance' => -1500.0, 'overdue' => -2000.0, 'openInvoices' => 2, 'unallocated' => 3000.0], $positions[$id(501)]);
        self::assertEquals(['balance' => 120.0, 'overdue' => 0.0, 'openInvoices' => 1, 'unallocated' => 0.0], $positions[$id(502)], 'SGD 100 at 1.2');
    }

    public function test_the_aged_report_buckets_by_due_date_and_keeps_lines_without_a_supplier_apart(): void
    {
        $this->odoo->lines = [
            Ap2Fixtures::payable(1, 501, 100.0, '2026-10-01', '2026-11-10'),
            Ap2Fixtures::payable(2, 501, 200.0, '2026-09-01', '2026-10-20'),
            Ap2Fixtures::payable(3, 501, 300.0, '2026-08-01', '2026-09-20'),
            Ap2Fixtures::payable(4, 501, 400.0, '2026-07-01', '2026-08-20'),
            Ap2Fixtures::payable(5, 501, 500.0, '2026-05-01', '2026-06-01'),
            Ap2Fixtures::payable(6, 501, -50.0, '2026-10-30', type: 'entry'),
            Ap2Fixtures::payable(7, null, 14000000.0, '2026-06-30', type: 'entry'),
            Ap2Fixtures::payable(8, null, 1000.0, '2026-06-30', type: 'entry', companyId: 1),
        ];

        $rows = $this->ledger->aged();

        self::assertCount(3, $rows);
        self::assertSame('Karya Sumiden', $rows[0]['supplierName']);
        // The payment has no due date: it ages from its own date (3 days), as in Odoo's aged report.
        self::assertEquals(['current' => 100.0, 'd1_30' => 150.0, 'd31_60' => 300.0, 'd61_90' => 400.0, 'd90p' => 500.0, 'total' => 1450.0, 'overdue' => 1350.0], array_intersect_key($rows[0], array_flip(['current', 'd1_30', 'd31_60', 'd61_90', 'd90p', 'total', 'overdue'])));
        self::assertSame(50.0, $rows[0]['unallocated']);
        self::assertSame('AU', $rows[0]['entity']);
        self::assertFalse($rows[0]['notBySupplier']);
        self::assertTrue($rows[0]['onHold'], 'a new supplier has no verified bank');
        self::assertSame(['Not by supplier (AU)', 'Not by supplier (SG)'], [$rows[1]['supplierName'], $rows[2]['supplierName']]);
        self::assertTrue($rows[1]['notBySupplier']);
        self::assertNull($rows[1]['supplierId']);
        self::assertSame(14000000.0, $rows[1]['d90p']);
        self::assertSame(1200.0, $rows[2]['total'], 'SGD 1,000 at 1.2');
    }

    public function test_without_odoo_the_supplier_list_still_opens_and_the_aged_report_says_why(): void
    {
        $this->suppliers->insert(Ap2Fixtures::supplier());
        $this->odoo->refuse = true;

        $read = $this->ledger->suppliersWithPositions();
        self::assertCount(1, $read['suppliers']);
        self::assertNull($read['positions']);
        self::assertNull($read['readAt']);
        try {
            $this->ledger->aged();
            self::fail('Expected a refusal');
        } catch (Ap2Upstream $e) {
            self::assertSame(502, $e->httpStatus());
        }
    }

    public function test_a_linked_supplier_keeps_odoo_fields_read_only_and_its_own_editable(): void
    {
        $c = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP2_ENABLED' => 'true'])->getContainer();
        $odoo = $c->get(InMemoryAp2OdooLedgerSource::class);
        self::assertInstanceOf(InMemoryAp2OdooLedgerSource::class, $odoo);
        $odoo->vendors = $this->odoo->vendors;
        $odoo->lines = [Ap2Fixtures::payable(1, 501, 1000.0, '2026-10-01', '2099-01-01')];
        $service = $c->get(Ap2SupplierService::class);
        self::assertInstanceOf(Ap2SupplierService::class, $service);
        $actor = new Ap2Actor(7, 'Alex Chen', 'alex@tricab.com', [Module::PERMISSION_AP2_ACCESS]);

        $list = $service->list();
        $karya = array_values(array_filter($list, static fn (array $s): bool => $s['odooPartnerId'] === 501))[0];
        self::assertTrue($karya['fromOdoo']);
        self::assertSame(1000.0, $karya['balance']);
        self::assertSame(1, $karya['openInvoices']);
        self::assertSame(0.0, $karya['unallocated']);
        self::assertIsString($karya['odooReadAt']);
        $id = is_string($karya['id']) ? $karya['id'] : '';

        try {
            $service->update($actor, $id, ['name' => 'Renamed', 'terms' => 5]);
            self::fail('Expected a refusal');
        } catch (Ap2Failure $f) {
            self::assertSame(422, $f->httpStatus());
            self::assertStringContainsString('name comes from Odoo', json_encode($f->extra, JSON_THROW_ON_ERROR));
        }
        $same = $service->update($actor, $id, ['name' => 'Karya Sumiden', 'hold' => true, 'notes' => 'Call first']);
        self::assertTrue($same['hold']);
        self::assertSame('Call first', $same['notes']);
        self::assertSame(1000.0, $same['balance']);

        $stored = $c->get(Ap2SupplierStore::class);
        self::assertInstanceOf(Ap2SupplierStore::class, $stored);
        self::assertCount(2, $stored->all());
    }

    public function test_the_kept_ledger_is_read_once_until_a_refresh(): void
    {
        $cached = new CachedAp2OdooLedgerSource($this->odoo, new ArrayApplicationCache());
        $first = $cached->ledger();
        $cached->ledger();
        self::assertSame(1, $this->odoo->reads);
        self::assertEquals(array_keys($first->vendors), array_keys($cached->ledger()->vendors));

        $cached->ledger(true);
        self::assertSame(2, $this->odoo->reads);
    }

    public function test_the_http_source_reads_open_payables_and_the_suppliers_on_vendor_bills(): void
    {
        $odoo = new InMemoryOdoo();
        $odoo->add('res.company', ['id' => 2, 'name' => 'Tricab (Australia) Pty Ltd', 'currency_id' => [21, 'AUD']]);
        $odoo->add('res.company', ['id' => 1, 'name' => 'TRICAB CABLES ASIA PTE. LTD.', 'currency_id' => [37, 'SGD']]);
        $odoo->add('account.move.line', self::payable(['id' => 1, 'move_id' => [11, 'BILL/1'], 'move_type' => 'in_invoice', 'partner_id' => [501, 'Karya'], 'company_id' => [2, 'AU'], 'date' => '2026-10-01', 'date_maturity' => '2026-10-31', 'balance' => -1000.0, 'amount_residual' => -1000.0, 'reconciled' => false]));
        $odoo->add('account.move.line', self::payable(['id' => 2, 'move_id' => [12, 'BILL/2'], 'move_type' => 'in_invoice', 'partner_id' => [501, 'Karya'], 'company_id' => [2, 'AU'], 'date' => '2026-09-01', 'date_maturity' => '2026-09-30', 'balance' => -500.0, 'amount_residual' => 0.0, 'reconciled' => true]));
        $odoo->add('account.move.line', self::payable(['id' => 3, 'move_id' => [13, 'OP/1'], 'move_type' => 'entry', 'partner_id' => false, 'company_id' => [2, 'AU'], 'date' => '2026-06-30', 'date_maturity' => false, 'balance' => -14000000.0, 'amount_residual' => -14000000.0, 'reconciled' => false]));
        $odoo->add('account.move', ['id' => 20, 'move_type' => 'in_invoice', 'state' => 'posted', 'commercial_partner_id' => [503, 'Paid Up Pte'], 'company_id' => [1, 'SG']]);
        $odoo->add('account.move', ['id' => 21, 'move_type' => 'in_invoice', 'state' => 'draft', 'commercial_partner_id' => [504, 'Draft Only'], 'company_id' => [2, 'AU']]);
        $odoo->add('res.partner', ['id' => 501, 'name' => 'Karya', 'email' => 'ap@karya.example', 'phone' => false, 'industry_id' => false, 'property_supplier_payment_term_id' => [3, '30 Days'], 'active' => true]);
        $odoo->add('res.partner', ['id' => 503, 'name' => 'Paid Up Pte', 'email' => false, 'phone' => '+65', 'industry_id' => [7, 'Services'], 'property_supplier_payment_term_id' => false, 'active' => true]);
        $odoo->add('res.partner', ['id' => 504, 'name' => 'Draft Only', 'active' => true]);
        $source = new HttpAp2OdooLedgerSource($odoo, new Ap2Time(FixedClock::utc('2026-11-02 01:00:00'), 'Australia/Melbourne'));

        $ledger = $source->ledger();

        self::assertSame([2 => 'AU', 1 => 'SG'], $ledger->entities);
        self::assertSame([1, 3], array_map(static fn ($l): int => $l->id, $ledger->openLines), 'unreconciled lines only');
        self::assertSame(1000.0, $ledger->openLines[0]->owed());
        self::assertSame('2026-10-31', $ledger->openLines[0]->agedFrom());
        self::assertNull($ledger->openLines[1]->partnerId);
        self::assertSame('2026-06-30', $ledger->openLines[1]->agedFrom(), 'no due date: its date');
        self::assertSame([501, 503], array_keys($ledger->vendors), 'a draft bill does not make a supplier');
        self::assertSame(30, $ledger->vendors[501]->terms);
        self::assertSame('AU', $ledger->vendors[501]->entity);
        self::assertSame(['SG', 'Services', null], [$ledger->vendors[503]->entity, $ledger->vendors[503]->industry, $ledger->vendors[503]->terms]);

        self::assertSame([1, 2], array_map(static fn ($l): int => $l->id, $source->payableLines([501], '2026-12-31')), 'reconciled lines count towards a past balance');
        self::assertSame([2], array_map(static fn ($l): int => $l->id, $source->payableLines([501], '2026-09-15')));
    }

    /**
     * A payable line as Odoo's search_read gives it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function payable(array $row): array
    {
        return $row + [
            'account_id' => [10, 'liability_payable'], 'parent_state' => 'posted', 'move_name' => 'BILL/1', 'ref' => 'INV-9', 'journal_id' => [44, 'Purchases'],
            'company_currency_id' => [21, 'AUD'], 'currency_id' => [21, 'AUD'], 'amount_currency' => $row['balance'] ?? 0.0, 'amount_residual_currency' => $row['amount_residual'] ?? 0.0,
        ];
    }

    public function test_payment_term_names_give_a_day_count_only_when_they_carry_one(): void
    {
        self::assertSame(30, Ap2OdooVendor::termDays('30 Days'));
        self::assertSame(0, Ap2OdooVendor::termDays('Immediate Payment'));
        self::assertSame(14, Ap2OdooVendor::termDays('NET14'));
        self::assertSame(7, Ap2OdooVendor::termDays('NET07'));
        self::assertSame(0, Ap2OdooVendor::termDays('COD'));
        self::assertNull(Ap2OdooVendor::termDays('EOM30'), 'end of month, not a day count');
        self::assertNull(Ap2OdooVendor::termDays('End of Following Month'));
        self::assertNull(Ap2OdooVendor::termDays(''));
    }
}
