<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Odoo;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Finance\Ap2\Ap2Fixtures;
use TriCab\Core\Finance\Ap2\Domain\Ap2InvoiceRules;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBill;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillLine;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillMapping;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillState;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooTrackedBill;

/** Odoo bill state → import state, absolute `paid` and stage; and the bill built from an invoice. */
final class Ap2OdooBillMappingTest extends TestCase
{
    private static function ours(float $total = 22000.0, float $credit = 0.0, float $paid = 0.0, string $importState = 'Imported draft', string $currency = 'AUD', string $odooRef = ''): Ap2OdooTrackedBill
    {
        return new Ap2OdooTrackedBill('inv1', 801, 'CM-2200', $currency, $total, $credit, $paid, $importState, $odooRef);
    }

    private static function odoo(string $state = 'posted', string $paymentState = 'not_paid', float $total = 22000.0, float $residual = 22000.0, string $currency = 'AUD', string $name = 'BILL/2026/10/0012'): Ap2OdooBillState
    {
        return new Ap2OdooBillState(801, $name, 'CM-2200', 'in_invoice', $state, $paymentState, $total, $residual, $currency, []);
    }

    public function testPostedNotPaid(): void
    {
        $u = Ap2OdooBillMapping::map(self::ours(), self::odoo());

        self::assertSame(['Posted', 'BILL/2026/10/0012', 0.0, 'Posted', false, [], true], [$u->importState, $u->odooRef, $u->paid, $u->stage, $u->settled, $u->issues, $u->changed]);
    }

    public function testPartialPaymentIsAbsoluteNotAdded(): void
    {
        $u = Ap2OdooBillMapping::map(self::ours(paid: 5000.0, importState: 'Posted'), self::odoo(paymentState: 'partial', residual: 12000.0));

        self::assertSame([10000.0, 'Partially settled', false], [$u->paid, $u->stage, $u->settled], 'paid is what Odoo says, not 5000 + 10000');
    }

    public function testPaidAndInPaymentAreSettled(): void
    {
        foreach (['paid', 'in_payment'] as $paymentState) {
            $u = Ap2OdooBillMapping::map(self::ours(importState: 'Posted'), self::odoo(paymentState: $paymentState, residual: 0.0));
            self::assertSame([22000.0, 'Settled', true], [$u->paid, $u->stage, $u->settled], $paymentState);
        }
    }

    public function testACreditReducesWhatIsOwed(): void
    {
        // CM-2200 22,000 with the CN-2200 credit 2,200 reconciled in Odoo: the residual is 0 once 19,800 is paid.
        $u = Ap2OdooBillMapping::map(self::ours(credit: 2200.0, importState: 'Posted'), self::odoo(paymentState: 'paid', residual: 0.0));

        self::assertSame([19800.0, 'Settled', true], [$u->paid, $u->stage, $u->settled]);
        $invoice = Ap2Fixtures::cm2200WithCredit(Ap2Fixtures::supplier())->with(importState: 'Posted', paid: $u->paid);
        self::assertSame($u->stage, Ap2InvoiceRules::stage($invoice, [], true), 'the stage the rules give');
        self::assertSame(0.0, $invoice->balance());
    }

    public function testAForeignCurrencyBillReadsInItsOwnCurrency(): void
    {
        $u = Ap2OdooBillMapping::map(self::ours(total: 1100.0, currency: 'USD', importState: 'Posted'), self::odoo(paymentState: 'partial', total: 1100.0, residual: 600.0, currency: 'USD'));

        self::assertSame([500.0, 'Partially settled', []], [$u->paid, $u->stage, $u->issues]);
    }

    public function testACurrencyMismatchIsReportedNotConverted(): void
    {
        $u = Ap2OdooBillMapping::map(self::ours(paid: 0.0, importState: 'Posted'), self::odoo(paymentState: 'paid', residual: 0.0, currency: 'USD'));

        self::assertSame([0.0, 'Posted'], [$u->paid, $u->importState]);
        self::assertSame(['Odoo holds CM-2200 in USD, not AUD; the payment state was not read'], $u->issues);
    }

    public function testDraftCancelledReversedAndTotals(): void
    {
        $draft = Ap2OdooBillMapping::map(self::ours(importState: 'Exported CSV'), self::odoo(state: 'draft', name: '/'));
        self::assertSame(['Imported draft', '', '', true], [$draft->importState, $draft->odooRef, $draft->stage, $draft->changed]);

        $cancelled = Ap2OdooBillMapping::map(self::ours(importState: 'Posted'), self::odoo(state: 'cancel'));
        self::assertSame('Import failed', $cancelled->importState);
        self::assertSame(['The bill BILL/2026/10/0012 was cancelled in Odoo'], $cancelled->issues);

        $reversed = Ap2OdooBillMapping::map(self::ours(importState: 'Posted'), self::odoo(paymentState: 'reversed', residual: 0.0));
        self::assertSame([22000.0, 'Settled'], [$reversed->paid, $reversed->stage]);
        self::assertSame(['The bill was reversed in Odoo (credit note): nothing is owed'], $reversed->issues);

        $differs = Ap2OdooBillMapping::map(self::ours(), self::odoo(total: 22100.0, residual: 22100.0));
        self::assertSame(['Odoo total AUD 22100.00 differs from 22000.00'], $differs->issues);
    }

    public function testNothingChangedIsNotAnUpdate(): void
    {
        $u = Ap2OdooBillMapping::map(self::ours(paid: 10000.0, importState: 'Posted', odooRef: 'BILL/2026/10/0012'), self::odoo(paymentState: 'partial', residual: 12000.0));

        self::assertFalse($u->changed);
    }

    public function testABillIsBuiltFromAnInvoiceOnlyWhenItIsComplete(): void
    {
        $plain = Ap2OdooBill::forInvoice(Ap2Fixtures::invoice(Ap2Fixtures::supplier()), 70, 2, null, [], null);
        self::assertCount(1, $plain, 'no credit, no refund');
        self::assertSame(['name' => 'OH-001 No PO', 'quantity' => 1.0, 'price_unit' => 1000.0, 'tax_ids' => [[6, 0, []]]], $plain[0]->lines[0]->command()[2], 'no account: Odoo uses its default');

        foreach ([
            static fn () => new Ap2OdooBill('x', 'triap_x', 'in_invoice', 70, 2, 'AUD', 'r', null, null, '', [new Ap2OdooBillLine('l', 1, 1)]),
            static fn () => new Ap2OdooBill('x', 'triap_' . md5('x'), 'out_invoice', 70, 2, 'AUD', 'r', null, null, '', [new Ap2OdooBillLine('l', 1, 1)]),
            static fn () => new Ap2OdooBill('x', 'triap_' . md5('x'), 'in_invoice', 0, 2, 'AUD', 'r', null, null, '', [new Ap2OdooBillLine('l', 1, 1)]),
            static fn () => new Ap2OdooBill('x', 'triap_' . md5('x'), 'in_invoice', 70, 2, 'EUR', 'r', null, null, '', [new Ap2OdooBillLine('l', 1, 1)]),
            static fn () => new Ap2OdooBill('x', 'triap_' . md5('x'), 'in_invoice', 70, 2, 'AUD', 'r', null, null, '', []),
        ] as $i => $make) {
            try {
                $make();
                self::fail('expected a refusal for case ' . $i);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
