<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2ApprovalStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Approval;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalEvent;
use TriCab\Core\Finance\Ap2\Domain\Ap2ApprovalRef;

/** The approvals held in memory (`add` and `addRef` are test seams). */
final class InMemoryAp2ApprovalStore implements Ap2ApprovalStore
{
    /** @var array<string, Ap2Approval> */
    private array $rows = [];

    /** @var array<string, Ap2ApprovalRef> */
    private array $refs = [];

    /** @var array<string, list<Ap2ApprovalEvent>> */
    private array $events = [];

    /** A bare reference (kind, status, snapshot) with no full record, for rule tests. */
    public function addRef(Ap2ApprovalRef $approval): void
    {
        $this->refs[$approval->id] = $approval;
    }

    public function find(string $id): ?Ap2ApprovalRef
    {
        return $this->findMany([$id])[$id] ?? null;
    }

    public function findMany(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->rows[$id])) {
                $a = $this->rows[$id];
                $out[$id] = new Ap2ApprovalRef($a->id, $a->kind, $a->status, $a->snapshot);
            } elseif (isset($this->refs[$id])) {
                $out[$id] = $this->refs[$id];
            }
        }

        return $out;
    }

    public function get(string $id): ?Ap2Approval
    {
        return $this->rows[$id] ?? null;
    }

    public function all(): array
    {
        $rows = array_values($this->rows);
        usort($rows, static fn (Ap2Approval $a, Ap2Approval $b): int => [$b->requestedAt, $b->id] <=> [$a->requestedAt, $a->id]);

        return $rows;
    }

    public function pendingFor(string $kind, string $itemId): array
    {
        return array_values(array_filter($this->rows, static fn (Ap2Approval $a): bool => $a->kind === $kind && $a->itemId() === $itemId && $a->status === 'Pending'));
    }

    public function insert(Ap2Approval $approval): void
    {
        $this->rows[$approval->id] = $approval;
    }

    public function update(Ap2Approval $approval): void
    {
        $this->rows[$approval->id] = $approval;
    }

    public function setSnapshotIfMissing(string $id, string $snapshot): bool
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null || $row->snapshot !== null) {
            return false;
        }
        $this->rows[$id] = $row->with(snapshot: $snapshot);

        return true;
    }

    public function addEvent(Ap2ApprovalEvent $event): void
    {
        $this->events[$event->approvalId][] = $event;
    }

    public function events(string $approvalId): array
    {
        return $this->events[$approvalId] ?? [];
    }
}
