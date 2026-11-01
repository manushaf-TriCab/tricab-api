<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2\Odoo;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Ap2\Odoo\FakeAp2OdooGateway;
use TriCab\Api\Tests\Finance\Ap2\Ap2Fixtures;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBill;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillState;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooBillUpdate;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooMatchFacts;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooMatchRequest;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPoLine;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPostingDisabled;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooPurchaseOrder;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooReceipt;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooSyncJob;
use TriCab\Core\Finance\Ap2\Odoo\Ap2OdooTrackedBill;

/** The 15-minute read-back job over the fake gateway and a recording target. */
final class Ap2OdooSyncJobTest extends TestCase
{
    private FakeAp2OdooGateway $odoo;

    private RecordingAp2OdooSyncTarget $target;

    protected function setUp(): void
    {
        $this->odoo = new FakeAp2OdooGateway();
        $this->target = new RecordingAp2OdooSyncTarget();
    }

    private function job(): Ap2OdooSyncJob
    {
        return new Ap2OdooSyncJob($this->odoo, $this->target);
    }

    private function seed(): void
    {
        $this->target->bills = [
            new Ap2OdooTrackedBill('inv1', 801, 'CM-2200', 'AUD', 22000.0, 2200.0, 0.0, 'Imported draft'),
            new Ap2OdooTrackedBill('inv2', 802, 'OH-004', 'USD', 1100.0, 0.0, 0.0, 'Posted', 'BILL/2026/10/0013'),
            new Ap2OdooTrackedBill('inv3', 803, 'GONE-1', 'AUD', 500.0, 0.0, 0.0, 'Posted'),
            new Ap2OdooTrackedBill('inv4', 804, 'SAME-1', 'AUD', 500.0, 0.0, 0.0, 'Posted', 'BILL/2026/10/0014'),
        ];
        $this->odoo->states = [
            801 => new Ap2OdooBillState(801, 'BILL/2026/10/0012', 'CM-2200', 'in_invoice', 'posted', 'paid', 22000.0, 0.0, 'AUD', []),
            802 => new Ap2OdooBillState(802, 'BILL/2026/10/0013', 'OH-004', 'in_invoice', 'posted', 'partial', 1100.0, 600.0, 'USD', []),
            804 => new Ap2OdooBillState(804, 'BILL/2026/10/0014', 'SAME-1', 'in_invoice', 'posted', 'not_paid', 500.0, 500.0, 'AUD', []),
        ];
        $this->target->waiting = [new Ap2OdooMatchRequest('inv5', 'PO-1001', 'AUD'), new Ap2OdooMatchRequest('inv6', 'PO-404', 'AUD')];
        $this->odoo->orders = ['PO-1001' => new Ap2OdooPurchaseOrder(11, 'PO-1001', 'purchase', 70, 'Cable Materials Pty Ltd', 2, 'Tricab Australia', 'AUD', '2026-09-20', [
            new Ap2OdooPoLine(101, 500, 'Cable', 1000.0, 900.0, 0.0, 20.0),
        ], [new Ap2OdooReceipt(201, 'WH/IN/00042', 'done', '2026-10-02')])];
        $this->odoo->rates = ['AUD' => 1.0, 'USD' => 1.6, 'NZD' => 0.91];
    }

    public function testItReadsBackBillsMatchesPosAndTakesRates(): void
    {
        $this->seed();

        $facts = $this->job()->run(new DateTimeImmutable('2026-10-26 01:00:00 UTC'));

        self::assertSame([
            'billsTracked' => 4, 'billsChanged' => 2, 'billsSettled' => 1, 'billsMissing' => 1, 'billIssues' => 0,
            'matchingWaiting' => 2, 'poFound' => 1, 'poMissing' => 1,
            'ratesRead' => 2, 'ratesMissing' => 'SGD',
        ], $facts);
        self::assertSame(['inv1', 'inv2'], array_map(static fn (Ap2OdooBillUpdate $u): string => $u->invoiceId, $this->target->applied), 'an unchanged bill is not written');
        self::assertSame(['Posted', 19800.0, 'Posted'], [$this->target->applied[0]->importState, $this->target->applied[0]->paid, $this->target->applied[0]->stage]);
        self::assertSame([500.0, 'Posted'], [$this->target->applied[1]->paid, $this->target->applied[1]->stage], 'the USD bill reads in USD');
        self::assertSame(['inv3'], $this->target->missing);
        self::assertSame([[1000.0, 900.0, 20.0, 'WH/IN/00042'], [false]], [
            [$this->target->matched[0]->orderedQty, $this->target->matched[0]->receivedQty, $this->target->matched[0]->poUnitPrice, $this->target->matched[0]->receiptRef],
            [$this->target->matched[1]->found],
        ]);
        self::assertSame('2026-10-26', $this->target->rates[0]->date, 'Sydney day');
        self::assertNotContains('postBill', $this->odoo->calls, 'the job never writes to Odoo');
    }

    public function testRunningAgainChangesNothingMore(): void
    {
        $this->seed();
        $this->job()->run(new DateTimeImmutable('2026-10-26 01:00:00 UTC'));
        // The target took the updates: AP2 now holds what Odoo holds.
        $this->target->bills = [
            new Ap2OdooTrackedBill('inv2', 802, 'OH-004', 'USD', 1100.0, 0.0, 500.0, 'Posted', 'BILL/2026/10/0013'),
        ];
        $this->target->applied = [];

        $this->job()->run(new DateTimeImmutable('2026-10-26 01:15:00 UTC'));

        self::assertSame([], $this->target->applied);
    }

    public function testARefusedPartDoesNotStopTheOthers(): void
    {
        $this->seed();
        $this->odoo->refuse = ['billStates'];

        try {
            $this->job()->run(new DateTimeImmutable('2026-10-26 01:00:00 UTC'));
            self::fail('expected the job to fail');
        } catch (RuntimeException $e) {
            self::assertStringStartsWith('The Tri-AP Odoo sync could not read bill states: Odoo billStates returned HTTP 403', $e->getMessage());
        }
        self::assertSame([], $this->target->applied);
        self::assertCount(2, $this->target->matched, 'PO matching still ran');
        self::assertCount(1, $this->target->rates, 'rates still ran');
    }

    public function testNothingTrackedReadsNothing(): void
    {
        $this->odoo->rates = ['AUD' => 1.0];

        $facts = $this->job()->run(new DateTimeImmutable('2026-10-26 01:00:00 UTC'));

        self::assertSame(['currencyRates'], $this->odoo->calls);
        self::assertSame([], $this->target->rates, 'no rates, nothing to apply');
        self::assertSame('USD,NZD,SGD', $facts['ratesMissing']);
    }

    public function testScheduleAndName(): void
    {
        self::assertSame('ap2.odoo-sync', $this->job()->name());
        $schedule = $this->job()->schedule();
        self::assertSame($schedule->dueSlot(new DateTimeImmutable('2026-10-26 01:00:00 UTC')), $schedule->dueSlot(new DateTimeImmutable('2026-10-26 01:14:59 UTC')));
        self::assertNotSame($schedule->dueSlot(new DateTimeImmutable('2026-10-26 01:00:00 UTC')), $schedule->dueSlot(new DateTimeImmutable('2026-10-26 01:15:00 UTC')));
    }

    public function testTheFakeGatewayPostsOnlyWhenSwitchedOnAndOnce(): void
    {
        $bill = Ap2OdooBill::forInvoice(Ap2Fixtures::invoice(Ap2Fixtures::supplier()), 70, 2, null, [], null)[0];
        try {
            $this->odoo->postBill($bill);
            self::fail('expected a refusal');
        } catch (Ap2OdooPostingDisabled) {
            self::assertSame([], $this->odoo->posted);
        }

        $this->odoo->postingEnabled = true;
        $first = $this->odoo->postBill($bill);
        $again = $this->odoo->postBill($bill);
        self::assertSame([true, false, $first->moveId], [$first->created, $again->created, $again->moveId]);
        self::assertCount(1, $this->odoo->posted);
    }
}
