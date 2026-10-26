<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Odoo;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap2\Odoo\HttpAp2OdooGateway;
use TriCab\Api\Tests\Finance\Ap2\Ap2Fixtures;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBill;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooMatchFacts;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooMatchRequest;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostingDisabled;
use TriCab\Core\Finance\Shared\Odoo\OdooError;

/**
 * The gateway against an in-memory Odoo 19: the reads (POs with lines and receipts, bill states with payments, AUD
 * rates, masked bank accounts) and the one write (a draft bill, idempotent on `triap_<id>`, only when switched on).
 */
final class HttpAp2OdooGatewayTest extends TestCase
{
    private const AU = 2;
    private const NZ = 4;

    private InMemoryOdoo $odoo;

    protected function setUp(): void
    {
        $this->odoo = new InMemoryOdoo();
    }

    private function gateway(bool $posting = false): HttpAp2OdooGateway
    {
        return new HttpAp2OdooGateway($this->odoo, $this->odoo, self::AU, $posting);
    }

    private function seedPo(): void
    {
        $this->odoo->add('purchase.order', ['id' => 11, 'name' => 'PO-1001', 'state' => 'purchase', 'partner_id' => [70, 'Cable Materials Pty Ltd'], 'company_id' => [self::AU, 'Tricab Australia'], 'currency_id' => [1, 'AUD'], 'date_order' => '2026-09-20 03:00:00', 'order_line' => [101, 102, 103], 'picking_ids' => [201, 202, 203]]);
        $this->odoo->add('purchase.order.line', ['id' => 101, 'order_id' => [11, 'PO-1001'], 'product_id' => [500, 'Cable 3C 2.5'], 'name' => 'Cable 3C 2.5mm', 'display_type' => false, 'product_qty' => 600.0, 'qty_received' => 600.0, 'qty_invoiced' => 0.0, 'price_unit' => 20.0]);
        $this->odoo->add('purchase.order.line', ['id' => 102, 'order_id' => [11, 'PO-1001'], 'product_id' => [500, 'Cable 3C 2.5'], 'name' => 'Cable 3C 2.5mm (second drum)', 'display_type' => false, 'product_qty' => 400.0, 'qty_received' => 300.0, 'qty_invoiced' => 0.0, 'price_unit' => 20.0]);
        $this->odoo->add('purchase.order.line', ['id' => 103, 'order_id' => [11, 'PO-1001'], 'product_id' => false, 'name' => 'Delivery notes', 'display_type' => 'line_note', 'product_qty' => 0.0, 'qty_received' => 0.0, 'qty_invoiced' => 0.0, 'price_unit' => 0.0]);
        $this->odoo->add('stock.picking', ['id' => 201, 'name' => 'WH/IN/00042', 'state' => 'done', 'date_done' => '2026-10-02 01:00:00', 'picking_type_code' => 'incoming']);
        $this->odoo->add('stock.picking', ['id' => 202, 'name' => 'WH/IN/00045', 'state' => 'assigned', 'date_done' => false, 'picking_type_code' => 'incoming']);
        $this->odoo->add('stock.picking', ['id' => 203, 'name' => 'WH/OUT/00007', 'state' => 'done', 'date_done' => '2026-10-03 01:00:00', 'picking_type_code' => 'outgoing']);
    }

    public function testAPurchaseOrderComesWithItsProductLinesAndReceipts(): void
    {
        $this->seedPo();

        $orders = $this->gateway()->purchaseOrders(['PO-1001', 'PO-404', ' ']);

        self::assertSame(['PO-1001'], array_keys($orders), 'an unknown PO is absent');
        $po = $orders['PO-1001'];
        self::assertSame([11, 'purchase', 70, 'Cable Materials Pty Ltd', self::AU, 'Tricab Australia', 'AUD', '2026-09-20'], [$po->id, $po->state, $po->partnerId, $po->partnerName, $po->companyId, $po->companyName, $po->currency, $po->orderDate]);
        self::assertCount(2, $po->lines, 'the note line is not a product');
        self::assertSame([1000.0, 900.0, 20.0], [$po->orderedQty(), $po->receivedQty(), $po->uniformUnitPrice()]);
        self::assertSame(['WH/IN/00042', 'WH/IN/00045'], array_map(static fn ($r): string => $r->name, $po->receipts), 'a return to the vendor is not a receipt');
        self::assertSame('WH/IN/00042', $po->receiptRef(), 'only done receipts count');
        self::assertSame(['purchase.order', 'purchase.order.line', 'stock.picking'], array_column($this->odoo->reads, 'model'));
        self::assertSame([['name', 'in', ['PO-1001', 'PO-404']]], $this->odoo->reads[0]['domain']);

        // The prototype's CM-2200 shortfall: 1000 invoiced, 900 received.
        $facts = Ap2OdooMatchFacts::of(new Ap2OdooMatchRequest('inv1', 'PO-1001', 'AUD'), $po);
        self::assertSame([true, 1000.0, 900.0, 20.0, 'WH/IN/00042', []], [$facts->found, $facts->orderedQty, $facts->receivedQty, $facts->poUnitPrice, $facts->receiptRef, $facts->issues]);
    }

    public function testMatchingIssuesAreNamed(): void
    {
        $this->seedPo();
        $po = $this->gateway()->purchaseOrders(['PO-1001'])['PO-1001'];

        $missing = Ap2OdooMatchFacts::of(new Ap2OdooMatchRequest('inv1', 'PO-404', 'AUD'), null);
        self::assertFalse($missing->found);
        self::assertSame(['PO PO-404 is not in Odoo'], $missing->issues);

        self::assertSame(['PO PO-1001 is in AUD, the invoice in USD'], Ap2OdooMatchFacts::of(new Ap2OdooMatchRequest('inv1', 'PO-1001', 'USD'), $po)->issues);

        $mixed = new \TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPurchaseOrder($po->id, $po->name, 'cancel', $po->partnerId, $po->partnerName, $po->companyId, $po->companyName, 'AUD', null, [
            $po->lines[0], new \TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPoLine(9, null, 'x', 1.0, 0.0, 0.0, 25.0),
        ], []);
        $facts = Ap2OdooMatchFacts::of(new Ap2OdooMatchRequest('inv1', 'PO-1001', 'AUD'), $mixed);
        self::assertNull($facts->poUnitPrice, 'no average of different prices');
        self::assertSame(['PO PO-1001 is cancelled in Odoo', 'PO PO-1001 lines have different unit prices; check the price by line'], $facts->issues);
    }

    public function testOnePoNumberInTwoCompaniesPrefersTheConfirmedOne(): void
    {
        $this->odoo->add('purchase.order', ['id' => 21, 'name' => 'PO-2000', 'state' => 'draft', 'currency_id' => [3, 'NZD'], 'company_id' => [self::NZ, 'Tricab NZ'], 'picking_ids' => []]);
        $this->odoo->add('purchase.order', ['id' => 22, 'name' => 'PO-2000', 'state' => 'purchase', 'currency_id' => [1, 'AUD'], 'company_id' => [self::AU, 'Tricab Australia'], 'picking_ids' => []]);

        self::assertSame(22, $this->gateway()->purchaseOrders(['PO-2000'])['PO-2000']->id);
    }

    public function testBillStatesComeWithTheirPayments(): void
    {
        $this->odoo->add('account.move', ['id' => 801, 'name' => 'BILL/2026/10/0012', 'ref' => 'CM-2200', 'move_type' => 'in_invoice', 'state' => 'posted', 'payment_state' => 'partial', 'amount_total' => 22000.0, 'amount_residual' => 12000.0, 'currency_id' => [1, 'AUD'], 'matched_payment_ids' => [901]]);
        $this->odoo->add('account.move', ['id' => 802, 'name' => '/', 'ref' => 'OH-1', 'move_type' => 'in_invoice', 'state' => 'draft', 'payment_state' => 'not_paid', 'amount_total' => 1100, 'amount_residual' => 1100, 'currency_id' => [2, 'USD'], 'matched_payment_ids' => []]);
        $this->odoo->add('account.payment', ['id' => 901, 'name' => 'PBNK1/2026/00031', 'date' => '2026-10-24', 'amount' => 10000.0, 'state' => 'paid']);

        $states = $this->gateway()->billStates([801, 802, 803]);

        self::assertSame([801, 802], array_keys($states));
        $s = $states[801];
        self::assertSame(['BILL/2026/10/0012', 'CM-2200', 'in_invoice', 'posted', 'partial', 22000.0, 12000.0, 'AUD'], [$s->name, $s->ref, $s->moveType, $s->state, $s->paymentState, $s->amountTotal, $s->amountResidual, $s->currency]);
        self::assertSame('PBNK1/2026/00031', $s->payments[0]->name);
        self::assertSame([10000.0, '2026-10-24'], [$s->payments[0]->amount, $s->payments[0]->date]);
        self::assertSame(['USD', 1100.0, []], [$states[802]->currency, $states[802]->amountTotal, $states[802]->payments]);
        self::assertSame([], $this->gateway()->billStates([]));
    }

    public function testARefusedAccountingReadIsAnErrorNotNothing(): void
    {
        $this->odoo->refused = ['account.move'];

        $this->expectException(OdooError::class);
        $this->expectExceptionMessage('account.move.search_read returned HTTP 403');
        $this->gateway()->billStates([801]);
    }

    public function testRatesAreAudPerUnitFromTheLatestDay(): void
    {
        // Odoo's rate is units of the currency per 1 AUD.
        $this->odoo->add('res.currency.rate', ['id' => 1, 'name' => '2026-10-20', 'rate' => 0.65, 'currency_id' => [2, 'USD'], 'company_id' => [self::AU, 'Tricab Australia']]);
        $this->odoo->add('res.currency.rate', ['id' => 2, 'name' => '2026-10-25', 'rate' => 0.64, 'currency_id' => [2, 'USD'], 'company_id' => false]);
        $this->odoo->add('res.currency.rate', ['id' => 3, 'name' => '2026-10-25', 'rate' => 0.625, 'currency_id' => [2, 'USD'], 'company_id' => [self::AU, 'Tricab Australia']]);
        $this->odoo->add('res.currency.rate', ['id' => 4, 'name' => '2026-10-27', 'rate' => 0.5, 'currency_id' => [2, 'USD'], 'company_id' => [self::AU, 'Tricab Australia']]);
        $this->odoo->add('res.currency.rate', ['id' => 5, 'name' => '2026-10-01', 'rate' => 1.1, 'currency_id' => [3, 'NZD'], 'company_id' => [self::AU, 'Tricab Australia']]);
        $this->odoo->add('res.currency.rate', ['id' => 6, 'name' => '2026-10-25', 'rate' => 0.9, 'currency_id' => [3, 'NZD'], 'company_id' => [self::NZ, 'Tricab NZ']]);

        $rates = $this->gateway()->currencyRates('2026-10-26');

        self::assertSame(['AUD' => 1.0, 'USD' => 1.6, 'NZD' => 0.909091], $rates->audPerUnit, 'the company rate of the latest day on or before; not a later day, not the NZ company');
        self::assertSame(['USD' => '2026-10-25', 'NZD' => '2026-10-01'], $rates->rateDates);
        self::assertSame(['SGD'], $rates->missing());
    }

    public function testBankAccountsAreMasked(): void
    {
        $this->odoo->add('res.partner.bank', ['id' => 31, 'partner_id' => [77, 'CleanWorks'], 'acc_number' => '062-000 1234 5678', 'bank_id' => [9, 'Commonwealth Bank'], 'active' => true, 'allow_out_payment' => false, 'create_date' => '2026-10-24 22:01:00', 'write_date' => '2026-10-25 09:30:00']);
        $this->odoo->add('res.partner.bank', ['id' => 32, 'partner_id' => [77, 'CleanWorks'], 'acc_number' => '063-111 9999 0001', 'bank_id' => false, 'active' => false, 'create_date' => '2025-01-01 00:00:00', 'write_date' => '2026-10-25 09:31:00']);

        $accounts = $this->gateway()->supplierBankAccounts([77, 78]);

        self::assertSame([77], array_keys($accounts));
        [$new, $old] = $accounts[77];
        self::assertSame(['•••• 678', 'Commonwealth Bank', true, false, '2026-10-25 09:30:00'], [$new->maskedNumber, $new->bankName, $new->active, $new->trustedForPayments, $new->changedAt]);
        self::assertSame(['•••• 001', false, null], [$old->maskedNumber, $old->active, $old->trustedForPayments]);
        self::assertStringNotContainsString('12345678', serialize($accounts), 'the full number is not kept');
        self::assertStringNotContainsString('1234', serialize($accounts));
    }

    /** @return list<Ap2OdooBill> CM-2200 with its CN-2200 credit, for vendor 70 in the AU company */
    private static function cm2200Bills(): array
    {
        return Ap2OdooBill::forInvoice(Ap2Fixtures::cm2200WithCredit(Ap2Fixtures::supplier()), 70, self::AU, 610, [12], 44);
    }

    public function testPostingIsRefusedWhileSwitchedOffAndSendsNothing(): void
    {
        try {
            $this->gateway(false)->postBill(self::cm2200Bills()[0]);
            self::fail('expected a refusal');
        } catch (Ap2OdooPostingDisabled $refused) {
            self::assertSame(409, $refused->httpStatus());
        }
        self::assertSame([], $this->odoo->reads);
        self::assertSame([], $this->odoo->creates);
    }

    public function testADraftBillIsCreatedOnceAndNamedLikeTheCsvImport(): void
    {
        $this->odoo->add('res.currency', ['id' => 1, 'name' => 'AUD']);
        [$bill] = self::cm2200Bills();

        $first = $this->gateway(true)->postBill($bill);

        self::assertTrue($first->created);
        self::assertTrue($first->externalIdRegistered);
        self::assertSame('/', $first->name, 'a draft has no number yet');
        self::assertSame(['account.move', 'ir.model.data'], array_column($this->odoo->creates, 'model'));
        $values = $this->odoo->creates[0]['values'];
        self::assertSame([
            'move_type' => 'in_invoice',
            'partner_id' => 70,
            'company_id' => self::AU,
            'currency_id' => 1,
            'ref' => 'CM-2200',
            'invoice_origin' => 'PO-1001',
            'invoice_line_ids' => [[0, 0, ['name' => 'CM-2200 Goods PO', 'quantity' => 1.0, 'price_unit' => 20000.0, 'tax_ids' => [[6, 0, [12]]], 'account_id' => 610, 'analytic_distribution' => ['44' => 100]]]],
            'invoice_date' => '2026-10-01',
            'invoice_date_due' => '2026-10-31',
        ], $values);
        self::assertArrayNotHasKey('state', $values, 'Odoo creates it as a draft; nothing posts it');
        self::assertSame(['module' => '__import__', 'name' => 'triap_' . $bill->invoiceId, 'model' => 'account.move', 'res_id' => $first->moveId, 'noupdate' => true], $this->odoo->creates[1]['values']);

        // A retry finds it by the external id and creates nothing.
        $again = $this->gateway(true)->postBill($bill);
        self::assertFalse($again->created);
        self::assertSame($first->moveId, $again->moveId);
        self::assertCount(2, $this->odoo->creates);
    }

    public function testABillImportedFromTheCsvIsFound(): void
    {
        [$bill] = self::cm2200Bills();
        $this->odoo->add('account.move', ['id' => 777, 'name' => 'BILL/2026/10/0020', 'state' => 'posted']);
        $this->odoo->add('ir.model.data', ['id' => 1, 'module' => '__import__', 'name' => $bill->externalId, 'model' => 'account.move', 'res_id' => 777]);

        $found = $this->gateway(true)->postBill($bill);

        self::assertSame([777, 'BILL/2026/10/0020', false], [$found->moveId, $found->name, $found->created]);
        self::assertSame([], $this->odoo->creates);
    }

    public function testTheSameVendorReferenceIsFoundWhenTheExternalIdIsMissing(): void
    {
        [$bill] = self::cm2200Bills();
        $this->odoo->add('account.move', ['id' => 778, 'name' => '/', 'partner_id' => [70, 'Cable Materials Pty Ltd'], 'ref' => 'CM-2200', 'move_type' => 'in_invoice', 'company_id' => [self::AU, 'Tricab Australia'], 'state' => 'draft']);
        $this->odoo->add('account.move', ['id' => 779, 'name' => '/', 'partner_id' => [70, 'Cable Materials Pty Ltd'], 'ref' => 'CM-2200', 'move_type' => 'in_refund', 'company_id' => [self::AU, 'Tricab Australia'], 'state' => 'draft']);

        $found = $this->gateway(true)->postBill($bill);

        self::assertSame([778, false, false], [$found->moveId, $found->created, $found->externalIdRegistered]);
        self::assertSame([], $this->odoo->creates);
    }

    public function testACreditNoteIsARefundOfItsOwn(): void
    {
        $this->odoo->add('res.currency', ['id' => 1, 'name' => 'AUD']);
        $bills = self::cm2200Bills();
        self::assertCount(2, $bills);
        [, $credit] = $bills;
        self::assertSame(['in_refund', 'triap_' . $credit->invoiceId . '_credit', 'CN-2200', 'CM-2200'], [$credit->moveType, $credit->externalId, $credit->ref, $credit->origin]);

        $this->gateway(true)->postBill($credit);

        $values = $this->odoo->creates[0]['values'];
        self::assertSame('in_refund', $values['move_type']);
        self::assertSame([[0, 0, ['name' => 'CN-2200 against CM-2200', 'quantity' => 1.0, 'price_unit' => 2000.0, 'tax_ids' => [[6, 0, [12]]], 'account_id' => 610, 'analytic_distribution' => ['44' => 100]]]], $values['invoice_line_ids'], 'the credit net');
    }

    public function testARefusedExternalIdStillReturnsTheBill(): void
    {
        $this->odoo->add('res.currency', ['id' => 1, 'name' => 'AUD']);
        $this->odoo->refused = ['ir.model.data.create'];

        $posted = $this->gateway(true)->postBill(self::cm2200Bills()[0]);

        self::assertTrue($posted->created);
        self::assertFalse($posted->externalIdRegistered);
    }

    public function testAnUnknownCurrencyIsAnError(): void
    {
        $this->expectException(OdooError::class);
        $this->expectExceptionMessage('Odoo has no currency AUD');
        $this->gateway(true)->postBill(self::cm2200Bills()[0]);
    }
}
