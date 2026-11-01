<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Odoo;

use DateTimeImmutable;
use DI\Container;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Finance\Ap2\InMemoryAp2ApprovalStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2HistoryStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2InvoiceStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2PaymentStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2PersonDirectory;
use TriCab\Api\Finance\Ap2\InMemoryAp2SettingsStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2SupplierStore;
use TriCab\Api\Finance\Ap2\Odoo\FakeAp2OdooGateway;
use TriCab\Api\Finance\Ap2\Odoo\HttpAp2OdooGateway;
use TriCab\Api\Finance\Ap2\Odoo\HttpAp2OdooRecordWriter;
use TriCab\Api\Finance\Odoo\InMemoryOdooRecordStore;
use TriCab\Api\Tests\Finance\Ap2\Ap2Fixtures;
use TriCab\Api\Tests\Finance\Ap2\Ap2Json;
use TriCab\Api\Tests\TestAppFactory;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooGateway;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooRecordWriter;
use TriCab\Core\Finance\Ap2\Contract\Ap2OdooSyncTarget;
use TriCab\Core\Finance\Ap2\Domain\Ap2Actor;
use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;
use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2Payment;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillState;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillUpdate;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooMatchFacts;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPaymentRef;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooRates;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooSyncJob;
use TriCab\Core\Finance\Ap2\Odoo\ServiceAp2OdooSyncTarget;
use TriCab\Core\Finance\Ap2\Service\Ap2ApprovalService;
use TriCab\Core\Finance\Ap2\Service\Ap2Fx;
use TriCab\Core\Finance\Ap2\Service\Ap2OdooPostingService;
use TriCab\Core\Finance\Ap2\Service\Ap2PaymentService;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Finance\Shared\Odoo\OdooLists;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordRow;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordStore;

/** Wiring of the Odoo stream into the app: bindings, job registration, the sync target, the double-count fix and post-to-odoo. */
final class Ap2OdooWiringTest extends TestCase
{
    private const ODOO = ['ODOO_URL' => 'https://odoo.example.test', 'ODOO_API_KEY' => 'key', 'ODOO_COMPANY_IDS' => '2,4'];

    private Container $c;

    private Ap2Actor $requester;

    private Ap2Actor $approver;

    /** @param array<string, string> $env */
    private function boot(array $env = []): void
    {
        $app = TestAppFactory::create(array_merge(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP2_ENABLED' => 'true'], $env));
        $c = $app->getContainer();
        self::assertInstanceOf(Container::class, $c);
        $this->c = $c;
        $this->requester = new Ap2Actor(7, 'Alex Chen', 'alex@tricab.com', [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_REQUEST, Module::PERMISSION_AP2_PAYMENTS_PREPARE, Module::PERMISSION_AP2_EXPORT]);
        $this->approver = new Ap2Actor(9, 'Priya Shah', 'priya@tricab.com', [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE, Module::PERMISSION_AP2_PAYMENTS_APPROVE]);
        $dir = $this->get(InMemoryAp2PersonDirectory::class);
        $dir->add($this->approver->person(), [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE, Module::PERMISSION_AP2_PAYMENTS_APPROVE]);
        $dir->add($this->requester->person(), [Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_APPROVALS_DECIDE]);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function get(string $class): object
    {
        $contracts = [
            InMemoryAp2ApprovalStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore::class,
            InMemoryAp2HistoryStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore::class,
            InMemoryAp2InvoiceStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore::class,
            InMemoryAp2PaymentStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2PaymentStore::class,
            InMemoryAp2PersonDirectory::class => \TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory::class,
            InMemoryAp2SettingsStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2SettingsStore::class,
            InMemoryAp2SupplierStore::class => \TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore::class,
            InMemoryOdooRecordStore::class => OdooRecordStore::class,
        ];
        $service = $this->c->get($contracts[$class] ?? $class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /** @return list<string> */
    private function jobNames(): array
    {
        $registry = $this->c->get(FinanceJobRegistry::class);
        self::assertInstanceOf(FinanceJobRegistry::class, $registry);

        return array_map(static fn ($j): string => $j->name(), $registry->all());
    }

    // ------------------------------------------------------------------ bindings and registration

    public function test_the_aud_company_setting_is_a_positive_whole_number_or_null(): void
    {
        self::assertNull(Settings::fromEnvironment([])->financeAp2OdooAudCompanyId);
        self::assertSame(2, Settings::fromEnvironment(['FINANCE_AP2_ODOO_AUD_COMPANY_ID' => '2'])->financeAp2OdooAudCompanyId);
        self::assertNull(Settings::fromEnvironment(['FINANCE_AP2_ODOO_AUD_COMPANY_ID' => 'two'])->financeAp2OdooAudCompanyId);
        self::assertNull(Settings::fromEnvironment(['FINANCE_AP2_ODOO_AUD_COMPANY_ID' => '0'])->financeAp2OdooAudCompanyId);
    }

    public function test_odoo_configured_binds_the_http_gateway_and_writer_and_registers_the_job(): void
    {
        $this->boot(self::ODOO + ['FINANCE_AP2_ODOO_AUD_COMPANY_ID' => '2']);

        self::assertInstanceOf(HttpAp2OdooGateway::class, $this->c->get(Ap2OdooGateway::class));
        self::assertInstanceOf(HttpAp2OdooRecordWriter::class, $this->c->get(Ap2OdooRecordWriter::class));
        self::assertInstanceOf(ServiceAp2OdooSyncTarget::class, $this->c->get(Ap2OdooSyncTarget::class));
        self::assertContains(Ap2OdooSyncJob::NAME, $this->jobNames());
    }

    public function test_odoo_not_configured_binds_the_fake_and_registers_no_job(): void
    {
        $this->boot(['FINANCE_AP2_ODOO_POSTING_ENABLED' => 'true']);

        $gateway = $this->c->get(Ap2OdooGateway::class);
        self::assertInstanceOf(FakeAp2OdooGateway::class, $gateway);
        self::assertFalse($gateway->postingEnabled, 'the fake never posts, even with the switch on');
        self::assertNotContains(Ap2OdooSyncJob::NAME, $this->jobNames());
    }

    public function test_the_job_needs_the_app_as_well_as_odoo(): void
    {
        $this->boot(self::ODOO + ['FINANCE_AP2_ENABLED' => 'false']);
        self::assertNotContains(Ap2OdooSyncJob::NAME, $this->jobNames());
    }

    public function test_the_writer_refuses_while_posting_is_off_even_when_odoo_is_configured(): void
    {
        $this->boot(self::ODOO);
        $this->expectException(\TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostingDisabled::class);
        $writer = $this->c->get(Ap2OdooRecordWriter::class);
        self::assertInstanceOf(Ap2OdooRecordWriter::class, $writer);
        $writer->create('account.move', []);
    }

    public function test_without_an_aud_company_the_gateway_answers_aud_only_without_asking_odoo(): void
    {
        $odoo = new InMemoryOdoo();
        $gateway = new HttpAp2OdooGateway($odoo, $odoo, null, false);

        $rates = $gateway->currencyRates('2026-10-26');

        self::assertSame(['AUD' => 1.0], $rates->audPerUnit);
        self::assertSame([], $odoo->reads);
    }

    // ------------------------------------------------------------------ rates

    public function test_the_synced_rates_are_stored_and_used_by_the_dashboard_fx(): void
    {
        $this->boot();
        $target = $this->get(ServiceAp2OdooSyncTarget::class);
        $store = $this->get(InMemoryAp2SettingsStore::class);
        self::assertSame(['AUD' => 1.0], $store->get()->fxRates);

        $target->applyCurrencyRates(new Ap2OdooRates('2026-10-26', ['AUD' => 1.0, 'USD' => 1.55, 'NZD' => 0.9], ['USD' => '2026-10-26', 'NZD' => '2026-10-25']));
        self::assertSame(['AUD' => 1.0, 'USD' => 1.55, 'NZD' => 0.9], $store->get()->fxRates);

        // Only the currencies present change.
        $target->applyCurrencyRates(new Ap2OdooRates('2026-10-27', ['AUD' => 1.0, 'USD' => 1.6], ['USD' => '2026-10-27']));
        self::assertSame(['AUD' => 1.0, 'USD' => 1.6, 'NZD' => 0.9], $store->get()->fxRates);

        // The FX helper the dashboard, the ageing and the payment limit use reads the stored rates.
        self::assertSame(1600.0, (new Ap2Fx($store->get()->fxRates))->toAud(1000.0, 'USD'));
    }

    // ------------------------------------------------------------------ sync target and the double count

    private function supplier(): \TriCab\Core\Finance\Ap2\Domain\Ap2Supplier
    {
        $s = Ap2Fixtures::supplier()->with(odooPartnerId: 77);
        $this->get(InMemoryAp2SupplierStore::class)->insert($s);

        return $s;
    }

    /** An approved, Odoo-posted invoice of AUD 500 (Odoo move 801). */
    private function postedInvoice(float $total = 500.0, string $id = 'B020', mixed ...$over): Ap2Invoice
    {
        $s = $this->get(InMemoryAp2SupplierStore::class)->find(md5('S001')) ?? $this->supplier();
        $b = Ap2Fixtures::invoice($s, $id, 'PAY-' . $id, ...array_merge(['net' => $total / 1.1, 'tax' => $total - $total / 1.1, 'total' => $total, 'due' => '2026-12-31'], $over));
        $this->get(InMemoryAp2InvoiceStore::class)->insert($b);
        $a = $this->get(Ap2ApprovalService::class)->request($this->requester, ['kind' => 'Invoice', 'invoiceIds' => [$b->id], 'approverUserId' => 9])[0];
        $this->get(Ap2ApprovalService::class)->decide($this->approver, $this->idOf($a), ['decision' => 'approve', 'comment' => '']);
        $store = $this->get(InMemoryAp2InvoiceStore::class);
        $store->update(($store->find($b->id) ?? $b)->with(importState: 'Posted', odooMoveId: 801, odooRef: 'BILL/1'));

        return $store->find($b->id) ?? $b;
    }

    /** A payment proposal through approval, left at Payment approved. */
    private function approvedPayment(Ap2Invoice $b, float $amount): string
    {
        $future = (new DateTimeImmutable('now', new \DateTimeZone('Australia/Melbourne')))->modify('+2 days')->format('Y-m-d');
        $p = $this->get(Ap2PaymentService::class)->create($this->requester, ['invoiceId' => $b->id, 'amount' => $amount, 'date' => $future]);
        $a = $this->get(Ap2ApprovalService::class)->request($this->requester, ['kind' => 'Payment', 'paymentId' => $p['id'], 'approverUserId' => 9])[0];
        $this->get(Ap2ApprovalService::class)->decide($this->approver, $this->idOf($a), ['decision' => 'approve', 'comment' => '']);
        $id = $p['id'];
        self::assertIsString($id);

        return $id;
    }

    private function billState(string $paymentState, float $residual, string $name = 'PAY/1', float $amount = 500.0, string $odooPaymentState = 'paid'): Ap2OdooBillState
    {
        return new Ap2OdooBillState(801, 'BILL/1', 'PAY-B020', 'in_invoice', 'posted', $paymentState, 500.0, $residual, 'AUD', [new Ap2OdooPaymentRef(1, $name, '2026-10-26', $amount, $odooPaymentState)]);
    }

    private function update(Ap2Invoice $b, float $paid, string $importState = 'Posted'): Ap2OdooBillUpdate
    {
        return new Ap2OdooBillUpdate($b->id, 801, $importState, 'BILL/1', $paid, '', false, [], true);
    }

    /** @param array<string, mixed> $row */
    private function idOf(array $row): string
    {
        $id = $row['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function invoice(string $id): Ap2Invoice
    {
        return $this->get(InMemoryAp2InvoiceStore::class)->find($id) ?? self::fail('no invoice');
    }

    private function payment(string $id): Ap2Payment
    {
        return $this->get(InMemoryAp2PaymentStore::class)->find($id) ?? self::fail('no payment');
    }

    public function test_the_sync_sets_paid_absolutely_and_moves_the_matching_proposal_to_paid_without_adding_it_again(): void
    {
        $this->boot();
        $b = $this->postedInvoice(500.0);
        $pid = $this->approvedPayment($b, 500.0);

        $this->get(ServiceAp2OdooSyncTarget::class)->applyBillState($this->update($b, 500.0), $this->billState('paid', 0.0));

        $paid = $this->payment($pid);
        self::assertSame('Paid', $paid->status);
        self::assertSame('PAY/1', $paid->odooPaymentRef);
        $inv = $this->invoice($b->id);
        self::assertSame(500.0, $inv->paid, 'the 500 is in Odoo\'s figure already: not 1000');
        self::assertSame('Posted', $inv->stage, 'settled, and still Posted');
        self::assertSame(0.0, $inv->balance());
    }

    public function test_a_payment_in_process_is_only_sent_to_odoo_and_paid_is_unchanged(): void
    {
        $this->boot();
        $b = $this->postedInvoice(500.0);
        $pid = $this->approvedPayment($b, 500.0);

        $this->get(ServiceAp2OdooSyncTarget::class)->applyBillState($this->update($b, 0.0), $this->billState('in_payment', 500.0, odooPaymentState: 'in_process'));

        self::assertSame('Sent to Odoo', $this->payment($pid)->status);
        self::assertSame(0.0, $this->invoice($b->id)->paid);
    }

    public function test_a_second_run_with_the_same_odoo_state_changes_nothing(): void
    {
        $this->boot();
        $b = $this->postedInvoice(500.0);
        $this->approvedPayment($b, 500.0);
        $target = $this->get(ServiceAp2OdooSyncTarget::class);
        $history = $this->get(InMemoryAp2HistoryStore::class);

        $target->applyBillState($this->update($b, 500.0), $this->billState('paid', 0.0));
        $lines = count($history->all());
        $after = $this->invoice($b->id);
        $target->applyBillState($this->update($b, 500.0), $this->billState('paid', 0.0));

        self::assertCount($lines, $history->all());
        self::assertEquals($after, $this->invoice($b->id));
        self::assertSame(500.0, $this->invoice($b->id)->paid);
    }

    public function test_marking_a_payment_paid_after_the_sync_does_not_count_it_twice(): void
    {
        $this->boot();
        $b = $this->postedInvoice(500.0);
        $pid = $this->approvedPayment($b, 500.0);
        $pay = $this->get(Ap2PaymentService::class);

        // The sync reads Odoo first (paid 500, absolute), then the Odoo stream marks the proposal.
        $store = $this->get(InMemoryAp2InvoiceStore::class);
        $store->update($this->invoice($b->id)->with(paid: 500.0));
        $pay->recordOdooState($pid, 'Sent to Odoo', 'PAY/1');
        $pay->recordOdooState($pid, 'Paid', '');

        self::assertSame(500.0, $this->invoice($b->id)->paid);
        self::assertSame('Posted', $this->invoice($b->id)->stage, 'settled, and still Posted');
        self::assertSame(0.0, $this->invoice($b->id)->balance());
    }

    public function test_marking_a_payment_paid_before_the_sync_still_adds_it_once_and_the_sync_then_agrees(): void
    {
        $this->boot();
        $b = $this->postedInvoice(500.0);
        $pid = $this->approvedPayment($b, 500.0);
        $pay = $this->get(Ap2PaymentService::class);

        $pay->recordOdooState($pid, 'Sent to Odoo', 'PAY/1');
        $pay->recordOdooState($pid, 'Paid', '');
        self::assertSame(500.0, $this->invoice($b->id)->paid);

        $this->get(ServiceAp2OdooSyncTarget::class)->applyBillState($this->update($b, 500.0), $this->billState('paid', 0.0));
        self::assertEqualsWithDelta(500.0, $this->invoice($b->id)->paid, 0.001);
    }

    public function test_two_proposals_and_a_payment_made_outside_add_up_to_odoos_figure(): void
    {
        $this->boot();
        $b = $this->postedInvoice(1000.0, 'B021');
        $one = $this->approvedPayment($b, 300.0);
        $pay = $this->get(Ap2PaymentService::class);
        $pay->recordOdooState($one, 'Sent to Odoo', 'PAY/1');
        $pay->recordOdooState($one, 'Paid', '');
        self::assertSame(300.0, $this->invoice($b->id)->paid);

        $two = $this->approvedPayment($b, 200.0);
        $pay->recordOdooState($two, 'Sent to Odoo', 'PAY/2');
        $pay->recordOdooState($two, 'Paid', '');
        self::assertSame(500.0, $this->invoice($b->id)->paid, 'sum of the Paid proposals, each counted once');

        // Odoo also knows 100 paid outside Tri-AP: its absolute figure wins, and the proposals do not push it over.
        $store = $this->get(InMemoryAp2InvoiceStore::class);
        $store->update($this->invoice($b->id)->with(paid: 600.0));
        $three = $this->approvedPayment($b, 100.0);
        $pay->recordOdooState($three, 'Sent to Odoo', 'PAY/3');
        $pay->recordOdooState($three, 'Paid', '');
        self::assertSame(600.0, $this->invoice($b->id)->paid);
    }

    public function test_a_payment_over_the_total_is_still_refused(): void
    {
        $this->boot();
        $b = $this->postedInvoice(500.0);
        $pid = $this->approvedPayment($b, 500.0);
        $pay = $this->get(Ap2PaymentService::class);
        $pay->recordOdooState($pid, 'Sent to Odoo', 'PAY/1');
        // The invoice's amount changes below the proposal (a credit arrives).
        $store = $this->get(InMemoryAp2InvoiceStore::class);
        $store->update($this->invoice($b->id)->with(credit: 100.0));

        $this->expectException(Ap2Failure::class);
        $pay->recordOdooState($pid, 'Paid', '');
    }

    public function test_tracked_bills_skip_settled_excluded_and_unlinked_invoices(): void
    {
        $this->boot();
        $one = $this->postedInvoice(500.0, 'B031');
        $this->postedInvoice(500.0, 'B032');
        $this->postedInvoice(500.0, 'B033');
        $store = $this->get(InMemoryAp2InvoiceStore::class);
        $settled = $this->invoice(md5('B032'));
        $store->update($settled->with(paid: round($settled->total - $settled->credit, 2)));
        $store->update($this->invoice(md5('B033'))->with(excluded: true));
        $store->insert(Ap2Fixtures::invoice($this->supplier(), 'B034', 'NO-MOVE'));

        $tracked = $this->get(ServiceAp2OdooSyncTarget::class)->trackedBills();

        self::assertSame([$one->id], array_map(static fn ($t): string => $t->invoiceId, $tracked));
        self::assertSame(801, $tracked[0]->moveId);
    }

    public function test_a_missing_draft_frees_the_invoice_and_a_missing_posted_bill_is_flagged_once(): void
    {
        $this->boot();
        $draft = $this->postedInvoice(500.0, 'B041');
        $store = $this->get(InMemoryAp2InvoiceStore::class);
        $store->update($this->invoice($draft->id)->with(importState: 'Imported draft'));
        $posted = $this->postedInvoice(500.0, 'B042');
        $target = $this->get(ServiceAp2OdooSyncTarget::class);
        $history = $this->get(InMemoryAp2HistoryStore::class);

        $bills = $target->trackedBills();
        foreach ($bills as $bill) {
            $target->billMissing($bill);
        }
        $lines = count($history->all());
        foreach ($target->trackedBills() as $bill) {
            $target->billMissing($bill);
        }

        self::assertSame('Import failed', $this->invoice($draft->id)->importState);
        self::assertNull($this->invoice($draft->id)->odooMoveId);
        self::assertSame('Posted', $this->invoice($posted->id)->importState);
        self::assertNotNull($this->invoice($posted->id)->odooMoveId);
        $synced = array_filter($history->all(), static fn ($e): bool => $e->type === 'Sync failed');
        self::assertCount(2, $synced);
        self::assertCount($lines, $history->all(), 'a standing problem is not written again');
    }

    public function test_purchase_order_facts_fill_the_matching_fields_and_a_missing_po_is_noted_once(): void
    {
        $this->boot();
        $s = $this->supplier();
        $b = Ap2Fixtures::cm2200($s, receiptRef: '', receivedQty: 0.0, orderedQty: 0.0, poUnitPrice: 0.0);
        $this->get(InMemoryAp2InvoiceStore::class)->insert($b);
        $target = $this->get(ServiceAp2OdooSyncTarget::class);

        $waiting = $target->awaitingMatching();
        self::assertSame(['PO-1001'], array_map(static fn ($w): string => $w->po, $waiting));

        $target->applyPurchaseOrder(new Ap2OdooMatchFacts($b->id, 'PO-1001', true, 1000.0, 900.0, 20.0, 'WH/IN/1', 'AUD'));
        $inv = $this->invoice($b->id);
        self::assertSame([1000.0, 900.0, 20.0, 'WH/IN/1'], [$inv->orderedQty, $inv->receivedQty, $inv->poUnitPrice, $inv->receiptRef]);

        $missing = new Ap2OdooMatchFacts($b->id, 'PO-1001', false, issues: ['PO PO-1001 is not in Odoo']);
        $target->applyPurchaseOrder($missing);
        $lines = count($this->get(InMemoryAp2HistoryStore::class)->all());
        $target->applyPurchaseOrder($missing);
        self::assertCount($lines, $this->get(InMemoryAp2HistoryStore::class)->all());
        self::assertSame(900.0, $this->invoice($b->id)->receivedQty, 'a PO Odoo does not have leaves the fields');
    }

    // ------------------------------------------------------------------ post-to-odoo

    /** @param array<string, mixed> $payload */
    private function record(string $list, int $id, string $name, ?string $code, ?int $company, array $payload): void
    {
        $this->get(InMemoryOdooRecordStore::class)->insert(
            new OdooRecordRow($list, $id, $name, $code, $company, true, json_encode($payload, JSON_THROW_ON_ERROR), null),
            new DateTimeImmutable('2026-10-26 00:00:00'),
        );
    }

    private function seedOdooReference(): void
    {
        $this->record(OdooLists::COMPANIES, 2, 'Tricab Australia', null, null, ['name' => 'Tricab Australia', 'company_id' => 2]);
        $this->record(OdooLists::COMPANIES, 4, 'Tricab NZ', null, null, ['name' => 'Tricab NZ', 'company_id' => 4]);
        $this->record(OdooLists::ACCOUNTS, 301, '6000 Office', '6000', null, ['name' => 'Office', 'code' => '6000', 'company_ids' => [2, 4]]);
        $this->record(OdooLists::COST_CENTRES, 55, 'Operations', 'OPS', 2, ['name' => 'Operations', 'code' => 'OPS', 'company_id' => 2]);
    }

    /**
     * An approved tax-free invoice ready to post, and the fake gateway bound with posting on.
     *
     * @param array<string, string> $env
     * @param array<string, mixed>  $over
     */
    private function postable(array $env = ['FINANCE_AP2_ODOO_POSTING_ENABLED' => 'true'], array $over = []): Ap2Invoice
    {
        $this->boot(self::ODOO + $env);
        $this->c->set(Ap2OdooGateway::class, new FakeAp2OdooGateway(true));
        $this->seedOdooReference();
        $s = $this->supplier();
        $b = Ap2Fixtures::invoice($s, 'B050', 'PO-NOTAX', ...array_merge(['net' => 1000.0, 'tax' => 0.0, 'total' => 1000.0, 'costCentre' => 'OPS', 'taxCode' => 'GST-FREE'], $over));
        $this->get(InMemoryAp2InvoiceStore::class)->insert($b);
        $a = $this->get(Ap2ApprovalService::class)->request($this->requester, ['kind' => 'Invoice', 'invoiceIds' => [$b->id], 'approverUserId' => 9])[0];
        $this->get(Ap2ApprovalService::class)->decide($this->approver, $this->idOf($a), ['decision' => 'approve', 'comment' => '']);

        return $b;
    }

    private function gateway(): FakeAp2OdooGateway
    {
        $g = $this->c->get(Ap2OdooGateway::class);
        self::assertInstanceOf(FakeAp2OdooGateway::class, $g);

        return $g;
    }

    private function refused(Ap2Invoice $b, int $status): string
    {
        try {
            $this->get(Ap2OdooPostingService::class)->post($this->requester, $b->id);
        } catch (Ap2Failure $f) {
            self::assertSame($status, $f->httpStatus(), $f->getMessage());

            return $f->getMessage() . ' | ' . implode(' | ', array_map(static fn (mixed $i): string => is_string($i) ? $i : '', Ap2Json::arr($f->extra, 'details.issues')));
        }
        self::fail('Expected a refusal');
    }

    public function test_post_to_odoo_creates_a_draft_and_records_it(): void
    {
        $b = $this->postable();

        $view = $this->get(Ap2OdooPostingService::class)->post($this->requester, $b->id);

        self::assertSame('Imported draft', $view['importState']);
        self::assertSame(9001, $view['odooMoveId']);
        $gateway = $this->gateway();
        self::assertSame(['postBill'], $gateway->calls);
        $bill = $gateway->posted['triap_' . $b->id]['bill'];
        self::assertSame([77, 2, 'in_invoice'], [$bill->partnerId, $bill->companyId, $bill->moveType]);
        $line = $bill->lines[0];
        self::assertSame([301, [], 55], [$line->accountId, $line->taxIds, $line->analyticAccountId]);
        $stored = $this->invoice($b->id);
        self::assertSame([9001, 'Imported draft'], [$stored->odooMoveId, $stored->importState]);
        $types = array_map(static fn ($e): string => $e->type, $this->get(InMemoryAp2HistoryStore::class)->all());
        self::assertContains('Posted to Odoo as draft', $types);

        // Not twice.
        self::assertStringContainsString('already in Odoo', $this->refused($b, 409));
        self::assertCount(1, $gateway->posted);
    }

    public function test_post_to_odoo_posts_the_credit_note_too(): void
    {
        $b = $this->postable(over: ['credit' => 100.0, 'creditRef' => 'CN-1', 'creditNet' => 100.0, 'creditTax' => 0.0, 'creditResolved' => true]);
        $this->get(Ap2OdooPostingService::class)->post($this->requester, $b->id);

        self::assertSame(['triap_' . $b->id, 'triap_' . $b->id . '_credit'], array_keys($this->gateway()->posted));
        self::assertSame(9001, $this->invoice($b->id)->odooMoveId);
    }

    public function test_post_to_odoo_is_refused_while_posting_is_off_or_odoo_is_not_configured(): void
    {
        $b = $this->postable(env: []);
        self::assertStringContainsString('switched off', $this->refused($b, 409));
        self::assertSame([], $this->gateway()->calls);

        $this->boot(['FINANCE_AP2_ODOO_POSTING_ENABLED' => 'true']);
        $s = $this->supplier();
        $x = Ap2Fixtures::invoice($s, 'B051', 'X-1');
        $this->get(InMemoryAp2InvoiceStore::class)->insert($x);
        self::assertStringContainsString('switched off', $this->refused($x, 409));
    }

    public function test_post_to_odoo_lists_what_it_cannot_resolve(): void
    {
        $this->boot(self::ODOO + ['FINANCE_AP2_ODOO_POSTING_ENABLED' => 'true']);
        $this->c->set(Ap2OdooGateway::class, new FakeAp2OdooGateway(true));
        $s = Ap2Fixtures::supplier();
        $this->get(InMemoryAp2SupplierStore::class)->insert($s);
        $b = Ap2Fixtures::invoice($s, 'B052', 'LOST-1', costCentre: 'Nowhere');
        $this->get(InMemoryAp2InvoiceStore::class)->insert($b);
        $a = $this->get(Ap2ApprovalService::class)->request($this->requester, ['kind' => 'Invoice', 'invoiceIds' => [$b->id], 'approverUserId' => 9])[0];
        $this->get(Ap2ApprovalService::class)->decide($this->approver, $this->idOf($a), ['decision' => 'approve', 'comment' => '']);

        $issues = $this->refused($b, 409);

        self::assertStringContainsString('Vendor: Cable Materials Pty Ltd has no Odoo partner id', $issues);
        self::assertStringContainsString('Company: the entity "Tricab Australia" matches no Odoo company', $issues);
        self::assertStringContainsString('Account: GL 6000 matches no Odoo account', $issues);
        self::assertStringContainsString('Cost centre: "Nowhere"', $issues);
        self::assertStringContainsString('Tax: code "GST10" matches no Odoo purchase tax', $issues);
        self::assertSame([], $this->gateway()->calls);
        self::assertNull($this->invoice($b->id)->odooMoveId);
    }

    /** @param array<string, mixed> $extra */
    private function tax(int $id, string $name, string $description, int $company = 2, string $use = 'purchase', array $extra = []): void
    {
        $this->record(OdooLists::TAXES, $id, $name, null, $company, $extra + ['name' => $name, 'description' => $description, 'amount' => 10.0, 'amount_type' => 'percent', 'type_tax_use' => $use, 'company_id' => $company]);
    }

    private function taxed(): Ap2Invoice
    {
        return $this->postable(over: ['net' => 1000.0, 'tax' => 100.0, 'total' => 1100.0, 'taxCode' => 'GST10']);
    }

    public function test_a_tax_is_resolved_by_name_or_label_in_the_invoices_company_and_purchase_only(): void
    {
        $b = $this->taxed();
        $this->tax(10, 'GST10', 'x', 4);
        $this->tax(11, 'GST10', 'x', 2, 'sale');
        $this->tax(12, 'Other', 'gst10');

        $this->get(Ap2OdooPostingService::class)->post($this->requester, $b->id);

        self::assertSame([12], $this->gateway()->posted['triap_' . $b->id]['bill']->lines[0]->taxIds);
    }

    public function test_the_tax_code_map_wins_over_name_matching(): void
    {
        $b = $this->taxed();
        $this->tax(10, 'GST10', 'GST on purchases');
        $this->tax(13, 'GST10', 'GST on purchases (2)');
        $this->tax(14, 'Mapped', '');
        $this->get(InMemoryAp2SettingsStore::class)->save($this->get(InMemoryAp2SettingsStore::class)->get()->with(taxCodeMap: ['GST10' => 14]), 1);

        $this->get(Ap2OdooPostingService::class)->post($this->requester, $b->id);

        self::assertSame([14], $this->gateway()->posted['triap_' . $b->id]['bill']->lines[0]->taxIds);
    }

    public function test_an_ambiguous_tax_names_the_candidates(): void
    {
        $b = $this->taxed();
        $this->tax(10, 'GST10', 'GST on purchases');
        $this->tax(13, 'Other', 'gst10');

        $issues = $this->refused($b, 409);

        self::assertStringContainsString('Tax: code "GST10" is ambiguous', $issues);
        self::assertStringContainsString('GST10 (10)', $issues);
        self::assertStringContainsString('Other (13)', $issues);
        self::assertSame([], $this->gateway()->calls);
    }

    public function test_a_missing_tax_is_refused_and_settings_validate_map_ids_against_the_list(): void
    {
        $b = $this->taxed();
        self::assertStringContainsString('Tax: code "GST10" matches no Odoo purchase tax', $this->refused($b, 409));

        $this->tax(10, 'GST10', '');
        $this->tax(11, 'GST10 sales', '', 2, 'sale');
        $service = $this->get(\TriCab\Core\Finance\Ap2\Service\Ap2SettingsService::class);
        $out = $service->update($this->requester, ['taxCodeMap' => ['GST-X' => 10]]);
        self::assertEquals((object) ['GST-X' => 10], $out['taxCodeMap']);
        try {
            $service->update($this->requester, ['taxCodeMap' => ['GST-X' => 999]]);
            self::fail('an unknown tax id is refused');
        } catch (\TriCab\Core\Finance\Ap2\Domain\Ap2Invalid $e) {
            self::assertStringContainsString('Odoo tax 999 is not in the synced purchase taxes', implode('|', array_map(static fn (mixed $i): string => is_string($i) ? $i : '', Ap2Json::arr($e->extra, 'details.issues'))));
        }
    }

    public function test_post_to_odoo_applies_the_same_checks_as_the_csv_export(): void
    {
        $b = $this->postable();
        $store = $this->get(InMemoryAp2InvoiceStore::class);
        $store->update($this->invoice($b->id)->with(importState: 'Exported CSV'));
        self::assertStringContainsString('Already exported to CSV', $this->refused($b, 409));

        $store->update($this->invoice($b->id)->with(importState: 'Not exported', gl: '6000', number: 'CHANGED'));
        self::assertStringContainsString('approval required / stale', $this->refused($b, 409));
        self::assertSame([], $this->gateway()->calls);
    }

    public function test_odoo_refusing_the_bill_is_a_502_and_changes_nothing(): void
    {
        $b = $this->postable();
        $this->gateway()->refuse = ['postBill'];

        $this->refused($b, 502);

        self::assertNull($this->invoice($b->id)->odooMoveId);
        self::assertSame('Not exported', $this->invoice($b->id)->importState);
    }

    public function test_the_route_answers_409_with_issues_while_posting_is_off(): void
    {
        $app = TestAppFactory::create(['FINANCE_MODULE_ENABLED' => 'true', 'FINANCE_AP2_ENABLED' => 'true']);
        $users = $app->getContainer()->get(\TriCab\Core\Identity\Contract\UserRepository::class);
        self::assertInstanceOf(\TriCab\Api\Identity\Persistence\InMemoryApiUserRepository::class, $users);
        $users->replacePermissions(1, ['account.login', Module::PERMISSION_ACCESS, Module::PERMISSION_AP2_ACCESS, Module::PERMISSION_AP2_EXPORT], ['EMPLOYEE']);
        $store = $app->getContainer()->get(\TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore::class);
        $suppliers = $app->getContainer()->get(\TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore::class);
        self::assertInstanceOf(\TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore::class, $store);
        self::assertInstanceOf(\TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore::class, $suppliers);
        $s = Ap2Fixtures::supplier();
        $suppliers->insert($s);
        $b = Ap2Fixtures::invoice($s);
        $store->insert($b);

        $response = $app->handle(TestAppFactory::request('POST', '/api/v1/finance/ap2/invoices/' . $b->id . '/post-to-odoo'));

        self::assertSame(409, $response->getStatusCode());
        $json = TestAppFactory::json($response);
        self::assertSame(['FINANCE_AP2_ODOO_POSTING_ENABLED is off, or Odoo is not configured on this host'], Ap2Json::get($json, 'error.details.issues'));
    }
}
