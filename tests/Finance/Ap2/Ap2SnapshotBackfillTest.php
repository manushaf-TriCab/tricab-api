<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ap2\InMemoryAp2ApprovalStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2HistoryStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2InvoiceStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2MailStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2PaymentStore;
use TriCab\Api\Finance\Ap2\InMemoryAp2SupplierStore;
use TriCab\Api\Finance\Ap2\Migration\Ap2SnapshotBackfill;
use TriCab\Core\Finance\Ap2\Domain\Ap2Approval;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Service\Ap2Snapshots;

final class Ap2SnapshotBackfillTest extends TestCase
{
    private InMemoryAp2ApprovalStore $approvals;

    private InMemoryAp2HistoryStore $history;

    private Ap2SnapshotBackfill $backfill;

    private Ap2Snapshots $snapshots;

    protected function setUp(): void
    {
        $suppliers = new InMemoryAp2SupplierStore();
        $payments = new InMemoryAp2PaymentStore();
        $invoices = new InMemoryAp2InvoiceStore($payments);
        $mail = new InMemoryAp2MailStore();
        $this->approvals = new InMemoryAp2ApprovalStore();
        $this->history = new InMemoryAp2HistoryStore();
        $supplier = Ap2Fixtures::supplier();
        $suppliers->insert($supplier);
        $invoices->insert(Ap2Fixtures::invoice($supplier, 'B010', 'OH-001'));
        $invoices->insert(Ap2Fixtures::invoice($supplier, 'B011', 'OH-002'));
        $this->snapshots = new Ap2Snapshots($invoices, $suppliers, $payments, $mail);
        $this->backfill = new Ap2SnapshotBackfill($this->approvals, $invoices, $this->snapshots, $this->history);
        $this->add('a1', 'B010', 'Approved');
        $this->add('a2', 'B011', 'Rejected');
        $this->add('a3', 'B999', 'Approved');
        $this->add('a4', 'B011', 'Approved', 'already');
    }

    private function add(string $id, string $invoice, string $status, ?string $snapshot = null): void
    {
        $person = new Ap2Person(0, 'x', '');
        $at = Ap2Fixtures::at();
        $this->approvals->insert(new Ap2Approval(md5($id), 'Invoice', $person, $person, $at, $at, invoiceId: md5($invoice), supplierId: md5('S001'), snapshot: $snapshot, status: $status));
    }

    /** @return list<string> */
    private function ids(): array
    {
        return array_map('md5', ['a1', 'a2', 'a3', 'a4']);
    }

    public function test_a_dry_run_counts_and_writes_nothing(): void
    {
        $counts = $this->backfill->run($this->ids(), false, Ap2Fixtures::at());

        self::assertSame(['considered' => 4, 'would_set' => 1, 'set' => 0, 'already_has_snapshot' => 1, 'not_approved' => 1, 'item_missing' => 1, 'no_supplier' => 0], $counts);
        self::assertNull($this->approvals->get(md5('a1'))?->snapshot);
        self::assertSame([], $this->history->all());
    }

    public function test_apply_stores_the_current_snapshot_logs_it_and_a_second_run_does_nothing(): void
    {
        $counts = $this->backfill->run($this->ids(), true, Ap2Fixtures::at());
        $approval = $this->approvals->get(md5('a1'));

        self::assertSame(1, $counts['set']);
        self::assertNotNull($approval);
        self::assertTrue($this->snapshots->isCurrent($approval));
        self::assertSame('already', $this->approvals->get(md5('a4'))?->snapshot);
        self::assertNull($this->approvals->get(md5('a2'))?->snapshot);
        self::assertCount(1, $this->history->all());
        self::assertSame('Approval snapshot backfilled', $this->history->all()[0]->type);

        $again = $this->backfill->run($this->ids(), true, Ap2Fixtures::at());
        self::assertSame(0, $again['set']);
        self::assertCount(1, $this->history->all());
    }
}
