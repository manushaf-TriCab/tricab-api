<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Contract\ApApprovalStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Contract\ApOutboundStore;
use TriCab\Core\Finance\Ap\Domain\ApApprovalBatch;
use TriCab\Core\Finance\Ap\Domain\ApApprovalDecision;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApOutboundTarget;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailLedger;

/**
 * The approvals held in memory, with the same rules as the SQL store: the tests', and a database-less run's. The invoice store
 * (the mailboxes a batch's scope reads), the outbound links and the mail ledger (the request mail's recipients) are the ones the
 * rest of Tri-AP holds. There is no transaction: a failure part way leaves what was written.
 */
final class InMemoryApApprovalStore implements ApApprovalStore
{
    /** @var array<string, ApApprovalBatch> keyed by id */
    private array $batches = [];

    /** @var array<string, array<string, string>> batch id => item id => invoice id, in insertion order */
    private array $items = [];

    /** @var list<ApApprovalDecision> */
    private array $decisions = [];

    public function __construct(
        private readonly ApInvoiceStore $invoices,
        private readonly ApOutboundStore $outbound,
        private readonly FinanceMailLedger $ledger,
    ) {
    }

    public function transactional(callable $work): mixed
    {
        return $work();
    }

    public function add(ApApprovalBatch $batch, array $items, DateTimeImmutable $at): void
    {
        $seen = [];
        foreach ($items as $invoiceId) {
            if (isset($seen[$invoiceId])) {
                throw new ApConflict('The invoice is already in this approval request');
            }
            $seen[$invoiceId] = true;
        }
        $this->batches[$batch->id] = $batch;
        $this->items[$batch->id] = $items;
    }

    public function find(string $id): ?ApApprovalBatch
    {
        return $this->batches[$id] ?? null;
    }

    public function recent(int $limit, ?array $mailboxes): array
    {
        $rows = array_values($this->batches);
        usort($rows, static fn (ApApprovalBatch $a, ApApprovalBatch $b): int => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);
        if ($mailboxes !== null) {
            $rows = array_values(array_filter($rows, fn (ApApprovalBatch $b): bool => $this->visible($b->id, $mailboxes)));
        }

        return array_slice($rows, 0, max(0, $limit));
    }

    public function invoiceIdsOf(array $batchIds): array
    {
        $out = [];
        foreach ($batchIds as $id) {
            if (($this->items[$id] ?? []) !== []) {
                $out[$id] = array_values($this->items[$id]);
            }
        }

        return $out;
    }

    public function decisionsOf(array $batchIds): array
    {
        $out = [];
        foreach ($this->decisions as $decision) {
            if (in_array($decision->batchId, $batchIds, true)) {
                $out[$decision->batchId][] = $decision;
            }
        }

        return $out;
    }

    public function notifiedTo(array $batchIds): array
    {
        $out = [];
        foreach (array_unique($batchIds) as $id) {
            $links = array_reverse($this->outbound->of(ApOutboundTarget::approvalBatch($id), PHP_INT_MAX));
            foreach ($links as $link) {
                $delivery = $this->ledger->findById($link->deliveryId);
                if ($delivery !== null && $delivery->application === Module::APP_AP && $delivery->idempotencyKey === 'ap-approval:' . $id . ':request') {
                    $out[$id] = $delivery->to;
                    break;
                }
            }
        }

        return $out;
    }

    public function decide(string $id, string $status, ?int $userId, string $name, string $comment, DateTimeImmutable $at): bool
    {
        $batch = $this->batches[$id] ?? null;
        if ($batch === null || !$batch->isPending()) {
            return false;
        }
        $this->batches[$id] = new ApApprovalBatch(
            $batch->id,
            $batch->preparedByUserId,
            $batch->preparedByName,
            $batch->preparedByEmail,
            $status,
            $batch->note,
            $batch->createdAt,
            $at,
            $userId,
            $name,
            $at,
            $comment,
        );

        return true;
    }

    public function addDecision(ApApprovalDecision $decision): void
    {
        $this->decisions[] = $decision;
    }

    /**
     * @param list<string> $mailboxes
     */
    private function visible(string $batchId, array $mailboxes): bool
    {
        $rows = $this->invoices->findMany(array_values($this->items[$batchId] ?? []));
        foreach ($rows as $invoice) {
            $address = strtolower(trim((string) $invoice->mailbox));
            if ($address !== '' && !in_array($address, $mailboxes, true)) {
                return false;
            }
        }

        return true;
    }
}
