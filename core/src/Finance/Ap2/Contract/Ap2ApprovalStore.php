<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2Approval;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalEvent;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalRef;

/** `finance.ap2_approvals` and `finance.ap2_approval_events`. */
interface Ap2ApprovalStore
{
    /** What the invoice rules read: kind, stored status and snapshot. */
    public function find(string $id): ?Ap2ApprovalRef;

    /**
     * @param list<string> $ids
     * @return array<string, Ap2ApprovalRef> by id; unknown ids are absent
     */
    public function findMany(array $ids): array;

    public function get(string $id): ?Ap2Approval;

    /** @return list<Ap2Approval> newest request first */
    public function all(): array;

    /**
     * The approvals of one kind still stored as Pending for one item (an invoice, mail or payment id).
     *
     * @return list<Ap2Approval>
     */
    public function pendingFor(string $kind, string $itemId): array;

    public function insert(Ap2Approval $approval): void;

    public function update(Ap2Approval $approval): void;

    /** Stores a snapshot on an approval that has none (the migration's backfill). Returns false when it already had one. */
    public function setSnapshotIfMissing(string $id, string $snapshot): bool;

    public function addEvent(Ap2ApprovalEvent $event): void;

    /** @return list<Ap2ApprovalEvent> oldest first */
    public function events(string $approvalId): array;
}
