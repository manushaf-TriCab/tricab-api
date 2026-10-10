<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Domain\ApApprovalBatch;
use TriCab\Core\Finance\Ap\Domain\ApApprovalDecision;

/**
 * The approval batches, their invoices and their decisions (`finance.invoice_approval_batches`, `_items`, `_decisions`).
 * Storage only: who may ask, the eligibility rule, the mail and the audit events are `ApApprovalService`'s, and the invoices'
 * `approval_status` is written through {@see ApInvoiceStore::setApprovalStatus()} in the same transaction.
 *
 * Every read that takes several batches answers for all of them in one query, so a list is built in a fixed number of
 * queries whatever its length (decision D75).
 */
interface ApApprovalStore
{
    /**
     * Runs `$work` as one unit: commits when it returns, rolls back when it throws. A call inside another joins it, and the
     * invoice and audit stores on the same connection join it too.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;

    /**
     * Writes the batch and one item per invoice.
     *
     * @param array<string, string> $items item id => invoice id, one per distinct invoice
     */
    public function add(ApApprovalBatch $batch, array $items, DateTimeImmutable $at): void;

    public function find(string $id): ?ApApprovalBatch;

    /**
     * The newest batches (`created_date`, then id, descending), at most `$limit`. With `$mailboxes` a batch is left out when
     * ANY of its invoices has a mailbox outside the list (a blank mailbox is visible to all); the limit counts only the batches
     * left in. Null is no restriction.
     *
     * @param list<string>|null $mailboxes lower-case addresses
     * @return list<ApApprovalBatch>
     */
    public function recent(int $limit, ?array $mailboxes): array;

    /**
     * The ids of the invoices in each batch (a batch with none is left out).
     *
     * @param list<string> $batchIds
     * @return array<string, list<string>>
     */
    public function invoiceIdsOf(array $batchIds): array;

    /**
     * @param list<string> $batchIds
     * @return array<string, list<ApApprovalDecision>> oldest first
     */
    public function decisionsOf(array $batchIds): array;

    /**
     * The `to` addresses of the request mail of each batch (the oldest message recorded for it whose idempotency key is
     * `ap-approval:<id>:request`); a batch with no such message is left out.
     *
     * @param list<string> $batchIds
     * @return array<string, list<string>>
     */
    public function notifiedTo(array $batchIds): array;

    /**
     * The first decision wins: moves a batch from `Pending` to `$status` (`Approved` / `Rejected`) with who and when, in one
     * conditional statement. False when the batch was not `Pending` (decided already, or another request got there first):
     * nothing was changed.
     */
    public function decide(string $id, string $status, ?int $userId, string $name, string $comment, DateTimeImmutable $at): bool;

    public function addDecision(ApApprovalDecision $decision): void;
}
