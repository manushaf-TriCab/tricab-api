<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.invoice_invoices`: a vendor invoice in the register (the source's `invoices` row).
 *
 * - `status` is the STORED status, one of {@see self::STORED_STATUSES}. "Overdue" is derived on read and never
 *   stored (question Q4): use {@see self::shownStatus()}.
 * - A text column that is `NOT NULL DEFAULT ''` in the table is a string here, `''` for none; the presenter shows
 *   the source's `null` for it. A `NULL` column is nullable here.
 * - Dates are `yyyy-MM-dd` text, money is a float of whole cents, timestamps are UTC.
 * - `mailbox` null (or blank) is a hand capture: visible to everyone.
 * - `senderName` is not a column: stores fill it from `invoice_emails` on reads, `''` when unknown.
 */
final readonly class ApInvoice
{
    public const STATUS_NEEDS_REVIEW = 'Needs Review';
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_EXPORTED = 'Exported';
    public const STATUS_OVERDUE = 'Overdue';

    /** What a person may set and the column may hold (`SETTABLE_INVOICE_STATUSES`): never `Overdue`. */
    public const STORED_STATUSES = [self::STATUS_NEEDS_REVIEW, self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED, self::STATUS_EXPORTED];

    public const DUPLICATE_CLEAR = 'Clear';
    public const DUPLICATE_POTENTIAL = 'Potential';
    public const DUPLICATE_CLEARED_BY_USER = 'Cleared by user';
    public const DUPLICATE_STATUSES = [self::DUPLICATE_CLEAR, self::DUPLICATE_POTENTIAL, self::DUPLICATE_CLEARED_BY_USER];

    public const APPROVAL_NOT_REQUESTED = 'Not requested';
    public const APPROVAL_PENDING = 'Pending approval';
    public const APPROVAL_APPROVED = 'Approved';
    public const APPROVAL_REJECTED = 'Rejected';
    public const APPROVAL_STATUSES = [self::APPROVAL_NOT_REQUESTED, self::APPROVAL_PENDING, self::APPROVAL_APPROVED, self::APPROVAL_REJECTED];

    /** The reason a business duplicate carries (exact: no full stop). */
    public const BUSINESS_DUPLICATE_REASON = 'Possible duplicate: same vendor, invoice number, currency, and total';

    /**
     * The columns a generic PATCH may write (T6.4 B7), and nothing else: not the vendor-match columns, `approval_status`,
     * `deleted_*`, `content_hash`, `file_id`, any person column, the mail columns or the dates.
     */
    public const PATCHABLE = [
        'invoice_no', 'vendor', 'vendor_email', 'tax_id', 'invoice_date', 'due_date', 'po_number', 'currency',
        'net_amount', 'tax_amount', 'total_amount', 'company', 'account_category', 'cost_centre', 'status', 'notes',
        'is_revised', 'duplicate_status', 'duplicate_reason', 'review_reason',
    ];

    /** The widths of the text columns that have one (the table's `NVARCHAR(n)`). */
    public const TEXT_LIMITS = [
        'invoice_no' => 100, 'vendor' => 400, 'vendor_email' => 320, 'tax_id' => 50, 'po_number' => 100, 'currency' => 10,
        'company' => 200, 'cost_centre' => 100, 'account_category' => 100, 'attachment' => 255, 'duplicate_reason' => 1000,
        'sender' => 400, 'subject' => 400, 'vendor_ref' => 100, 'odoo_vendor_name' => 400, 'vendor_match_note' => 1000,
    ];

    public function __construct(
        public string $id,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?string $emailId = null,
        public ?string $mailbox = null,
        public ?DateTimeImmutable $emailDate = null,
        public string $sender = '',
        public string $subject = '',
        public ?string $emailRecordId = null,
        public ?string $attachmentId = null,
        public string $attachment = '',
        public ?string $fileId = null,
        public ?string $contentHash = null,
        public string $invoiceNo = '',
        public string $vendor = '',
        public string $vendorEmail = '',
        public string $taxId = '',
        public ?string $invoiceDate = null,
        public ?string $dueDate = null,
        public string $poNumber = '',
        public string $currency = '',
        public ?float $netAmount = null,
        public ?float $taxAmount = null,
        public ?float $totalAmount = null,
        public string $company = '',
        public string $accountCategory = '',
        public string $costCentre = '',
        public string $status = self::STATUS_NEEDS_REVIEW,
        public ?string $notes = null,
        public bool $isRevised = false,
        public string $duplicateStatus = self::DUPLICATE_CLEAR,
        public ?string $duplicateOfInvoiceId = null,
        public string $duplicateReason = '',
        public string $approvalStatus = self::APPROVAL_NOT_REQUESTED,
        public ?float $extractionConfidence = null,
        public ?string $reviewReason = null,
        public ?DateTimeImmutable $deletedAt = null,
        public ?int $deletedByUserId = null,
        public string $deletedBy = '',
        public ?int $odooVendorId = null,
        public string $vendorRef = '',
        public string $odooVendorName = '',
        public ?string $vendorMatch = null,
        public string $vendorMatchNote = '',
        public ?int $vendorMatchByUserId = null,
        public string $senderName = '',
    ) {
    }

    /** A copy with some properties replaced (by property name). */
    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * A copy with some columns replaced (by column name, `invoice_no`), as a PATCH or a store writes them.
     *
     * @param array<string, mixed> $columns
     */
    public function withColumns(array $columns): self
    {
        $changes = [];
        foreach ($columns as $column => $value) {
            $changes[self::property($column)] = $value;
        }

        return $this->with(...$changes);
    }

    /** `invoice_no` to `invoiceNo`. */
    public static function property(string $column): string
    {
        return lcfirst(str_replace('_', '', ucwords($column, '_')));
    }

    public function isBinned(): bool
    {
        return $this->deletedAt !== null;
    }

    /** A hand capture: it came from no mailbox. */
    public function isHandCapture(): bool
    {
        return trim((string) $this->mailbox) === '';
    }

    /**
     * The status the register shows (question Q4): a live invoice stored as Needs Review or In Progress whose due date is
     * BEFORE `$today` (a calendar date in the Tri-AP zone) is Overdue; due today is not overdue; Completed and Exported
     * never are; a binned invoice shows its stored status.
     */
    public function shownStatus(string $today): string
    {
        return self::derive($this->status, $this->dueDate, $this->deletedAt !== null, $today);
    }

    public static function derive(string $stored, ?string $dueDate, bool $binned, string $today): string
    {
        if (!$binned && ($stored === self::STATUS_NEEDS_REVIEW || $stored === self::STATUS_IN_PROGRESS) && $dueDate !== null && $dueDate < $today) {
            return self::STATUS_OVERDUE;
        }

        return $stored;
    }
}
