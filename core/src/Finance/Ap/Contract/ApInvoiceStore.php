<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApInvoiceQuery;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;

/**
 * The register's invoices (`finance.invoice_invoices`) and the few rows that hang off them. Storage only: the rules (what
 * may be edited, the Bin's order of events, the grade, the audit events) are `ApInvoiceService`'s, so both adapters behave
 * the same. Nothing here writes the derived Overdue.
 *
 * Who calls what, for the later Tri-AP tasks:
 *
 * - AP Mailbox: {@see invoiceIdsByEmail()} (the `invoice_ids` of each email, one query), {@see find()} / {@see findMany()}.
 * - Scan: {@see findByContentHash()} (rule 1), {@see findBusinessDuplicate()} (rule 2), {@see findCaptured()},
 *   {@see scanSkipped()}, {@see insert()} (through `ApInvoiceService::createFromScan()`).
 * - Vendor matching: {@see setVendorMatch()} (the five vendor columns), {@see liveCountsByVendor()}, {@see find()} /
 *   {@see page()} for the invoices to match.
 * - Mail and approvals: {@see find()} / {@see findMany()}, {@see setApprovalStatus()}.
 *
 * Every read returns the row with `senderName` filled from the scanned email.
 */
interface ApInvoiceStore
{
    /**
     * Runs `$work` as one unit: commits when it returns, rolls back when it throws, and rethrows. A call inside another
     * joins it. {@see ApAuditStore} and the file store on the same connection join it too.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;

    /**
     * The rows the query asks for, the filter and the scope and the period applied before the limit, ordered by the sort
     * key and then the id.
     *
     * @return list<ApInvoice>
     */
    public function page(ApInvoiceQuery $query): array;

    /** One invoice, live or binned; null when there is none. */
    public function find(string $id): ?ApInvoice;

    /**
     * Several invoices at once, keyed by id; an id with no invoice is left out.
     *
     * @param list<string> $ids
     * @return array<string, ApInvoice>
     */
    public function findMany(array $ids): array;

    /** True when an invoice (live or binned) has this id. */
    public function exists(string $id): bool;

    public function insert(ApInvoice $invoice): void;

    /**
     * Writes the named columns of one invoice and `updated_date`. The names must be in {@see ApInvoice::PATCHABLE}: anything
     * else is a `LogicException`, so a caller cannot write a column this way that a PATCH may not.
     *
     * @param array<string, mixed> $columns column name => value, as the table holds it (`''` for none of a text column)
     */
    public function update(string $id, array $columns, DateTimeImmutable $at): void;

    /** Moves a live invoice to the Bin; one already there is left as it is. */
    public function bin(string $id, ?int $byUserId, string $by, DateTimeImmutable $at): void;

    /** Clears `deleted_at` and the person; harmless on an invoice that was not binned. */
    public function restore(string $id, DateTimeImmutable $at): void;

    /**
     * Deletes an invoice for good, children first, in the caller's transaction: its approval items and outbound-message links;
     * the scanned attachments' links to it (`invoice_id`, `duplicate_of_invoice_id`, and `file_id` where it is this invoice's
     * file); other invoices' `duplicate_of_invoice_id`; then the tombstone `(emailId, skipKey)` when both are given (an
     * existing one is left); then the row. The audit rows stay.
     */
    public function purge(string $id, ?string $emailId, ?string $skipKey, DateTimeImmutable $at): void;

    /** The oldest (`created_date`) invoice with this content hash, in any state; null for none or a blank hash. */
    public function findByContentHash(string $hash): ?ApInvoice;

    /**
     * Rule 2 (D74): the oldest invoice with the same invoice number and vendor (trimmed, any case), currency (blank reads
     * AUD) and total (the same cent; a missing total reads 0), in any state. The caller has trimmed and checked its inputs.
     */
    public function findBusinessDuplicate(string $invoiceNo, string $vendor, string $currency, float $total): ?ApInvoice;

    /**
     * The invoice already captured from this attachment of this message (`find_captured_invoice`): matched on Graph's
     * attachment id; an invoice stored without one matches by file name.
     */
    public function findCaptured(string $emailId, ?string $attachmentId, string $name): ?ApInvoice;

    /** True when a capture of this attachment was deliberately deleted for good (`scan_skipped`): by attachment id or by name. */
    public function scanSkipped(string $emailId, ?string $attachmentId, string $name): bool;

    /**
     * The ids of the invoices captured from each email (by `email_record_id`), oldest first, for the AP Mailbox's
     * `invoice_ids`. An email with none is left out.
     *
     * @param list<string> $emailRecordIds
     * @return array<string, list<string>>
     */
    public function invoiceIdsByEmail(array $emailRecordIds): array;

    /** Writes the five vendor columns and who chose them. Touches nothing else but `updated_date`. */
    public function setVendorMatch(string $id, ApVendorMatch $match, ?int $byUserId, DateTimeImmutable $at): void;

    /**
     * Live (not binned) invoices per matched Odoo vendor, for the Vendors page: `odoo vendor id => count`. Vendors with none
     * are left out.
     *
     * @return array<int, int>
     */
    public function liveCountsByVendor(): array;

    /**
     * Sets `approval_status` on the invoices (binned ones too, as the source did). Only the approval code calls this.
     *
     * @param list<string> $ids
     */
    public function setApprovalStatus(array $ids, string $status, DateTimeImmutable $at): void;
}
