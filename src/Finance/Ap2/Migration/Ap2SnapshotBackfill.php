<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2\Migration;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Contract\Ap2InvoiceStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;
use TriCab\Core\Finance\Ap2\Service\Ap2Snapshots;

/**
 * Gives migrated, Approved approvals the snapshot they would have if they were made today, so they read as current
 * (an approval is current only while its stored snapshot equals the item's snapshot now). Tri-AP kept no snapshot.
 *
 * Only approvals named by the caller (the ones in `ap2_migration_map`) with status Approved and a NULL snapshot are
 * touched; an approval whose item is gone is skipped. Each change is logged to `ap2_history`. A dry run writes nothing.
 */
final class Ap2SnapshotBackfill
{
    public function __construct(
        private readonly Ap2ApprovalStore $approvals,
        private readonly Ap2InvoiceStore $invoices,
        private readonly Ap2Snapshots $snapshots,
        private readonly Ap2HistoryStore $history,
    ) {
    }

    /**
     * @param list<string> $migratedApprovalIds
     * @return array{considered: int, would_set: int, set: int, already_has_snapshot: int, not_approved: int, item_missing: int, no_supplier: int}
     */
    public function run(array $migratedApprovalIds, bool $apply, DateTimeImmutable $now): array
    {
        $count = ['considered' => 0, 'would_set' => 0, 'set' => 0, 'already_has_snapshot' => 0, 'not_approved' => 0, 'item_missing' => 0, 'no_supplier' => 0];
        foreach ($migratedApprovalIds as $id) {
            $approval = $this->approvals->get($id);
            if ($approval === null) {
                continue;
            }
            ++$count['considered'];
            if ($approval->status !== 'Approved') {
                ++$count['not_approved'];
                continue;
            }
            if ($approval->snapshot !== null) {
                ++$count['already_has_snapshot'];
                continue;
            }
            $snapshot = $this->snapshots->current($approval);
            if ($snapshot === null) {
                ++$count['item_missing'];
                continue;
            }
            $invoice = $approval->invoiceId === null ? null : $this->invoices->find($approval->invoiceId);
            $supplierId = $approval->supplierId ?? $invoice?->supplierId;
            if ($supplierId === null) {
                ++$count['no_supplier'];
                continue;
            }
            if (!$apply) {
                ++$count['would_set'];
                continue;
            }
            if (!$this->approvals->setSnapshotIfMissing($approval->id, $snapshot)) {
                ++$count['already_has_snapshot'];
                continue;
            }
            $this->history->append(new Ap2HistoryEntry(
                bin2hex(random_bytes(16)),
                $supplierId,
                'Approval snapshot backfilled',
                'Migrated approval ' . $approval->id . ' bound to the ' . strtolower($approval->kind) . ' as it is now (' . substr($snapshot, 0, 12) . ').',
                new Ap2Person(0, 'Tri-AP migration', ''),
                $now,
                $approval->invoiceId,
                $approval->mailId,
                ['approvalId' => $approval->id, 'snapshot' => $snapshot],
            ));
            ++$count['set'];
        }

        return $count;
    }
}
