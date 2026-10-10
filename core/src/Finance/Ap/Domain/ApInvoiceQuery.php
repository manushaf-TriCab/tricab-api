<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * What `GET /invoices` asks of the store, already validated: the Bin or the live rows, the exact filters, the period as UTC
 * instants (`start` inclusive, `end` exclusive, already widened by a day each side), the person's mailbox scope, a sort key
 * from {@see SORTS}, and a limit. `today` is the date Overdue is judged against (the Tri-AP zone's today).
 *
 * The scope, the period and the limit are all applied in the query, in that order of importance: a limit counts only
 * the rows the person may see and the period admits.
 */
final readonly class ApInvoiceQuery
{
    /**
     * The sort keys the register accepts (question Q5): bounded columns only. `status` is the derived status. The long text
     * (`notes`, `review_reason`, `duplicate_reason`, `vendor_match_note`) and the id and hash columns are not sortable except `id`.
     */
    public const SORTS = [
        'id', 'created_date', 'updated_date', 'email_date', 'invoice_date', 'due_date', 'deleted_at', 'status', 'vendor',
        'invoice_no', 'mailbox', 'company', 'currency', 'net_amount', 'tax_amount', 'total_amount', 'cost_centre',
        'account_category', 'approval_status', 'duplicate_status', 'vendor_match', 'odoo_vendor_name', 'po_number', 'tax_id',
        'vendor_email', 'sender', 'subject', 'attachment',
    ];

    public const DEFAULT_SORT = 'created_date';
    public const DEFAULT_LIMIT = 200;
    public const MAX_LIMIT = 1000;

    /**
     * @param list<string>|null $mailboxes lower-case addresses the person may see (hand captures too); null is unrestricted
     */
    public function __construct(
        public bool $deleted,
        public ?string $id,
        public ?string $status,
        public ?DateTimeImmutable $start,
        public ?DateTimeImmutable $end,
        public ?array $mailboxes,
        public string $sortBy,
        public bool $descending,
        public int $limit,
        public string $today,
    ) {
    }
}
