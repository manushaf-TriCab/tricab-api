<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use DateTimeImmutable;
use Throwable;
use TriCab\Core\Finance\Ap\Contract\ApAuditStore;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApAuditEvent;
use TriCab\Core\Finance\Ap\Domain\ApConflict;
use TriCab\Core\Finance\Ap\Domain\ApForbidden;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Domain\ApInvoiceCapture;
use TriCab\Core\Finance\Ap\Domain\ApInvoiceQuery;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Domain\ApVendorInvoice;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditLog;

/**
 * The register's server side (`app.py` routes 7 to 12, `store.py` invoices), without HTTP or SQL: list, one invoice, manual
 * capture, edit, the Bin, restore and permanent delete (T6.4), with the decided changes of Phase 6:
 *
 *  - Overdue is derived on read and never written (Q4); the `status` filter and sort use the derived value;
 *  - the sort list is bounded (Q5) and a bad `limit` is a 422, never "no limit" (R1);
 *  - a create or PATCH writes only a fixed list of columns (R2), `status` is checked on both (R3);
 *  - every route applies the person's mailbox scope: out of scope is a 404 (Q11);
 *  - a permanent delete removes the children first, in one transaction, writes the scan's tombstone, then removes the file.
 *
 * For the scan (T6.7) it also offers {@see findExactDuplicate()}, {@see findBusinessDuplicate()} and
 * {@see createFromScan()}, which take no person and no HTTP.
 */
final class ApInvoiceService
{
    public const NOT_FOUND = 'Not found';
    public const BAD_DATE = 'Dates must use YYYY-MM-DD';
    public const NOT_IN_BIN = 'Move the invoice to the Bin before deleting it permanently';
    public const FORBIDDEN_PERMANENT = 'You do not have permission to delete invoices permanently';
    public const FILES_ORPHANED_ACTION = 'ap.invoice.files_orphaned';

    /** The widest text the scan's tombstone key may be: `invoice_scan_skips.attachment` is `NVARCHAR(255)`. */
    private const SKIP_KEY_WIDTH = 255;

    public function __construct(
        private readonly ApInvoiceStore $invoices,
        private readonly ApAuditStore $audit,
        private readonly ApAttachmentService $attachments,
        private readonly ApTime $time,
        private readonly ?FinanceAuditLog $platformAudit = null,
        private readonly float $confidenceThreshold = ApGrading::DEFAULT_THRESHOLD,
        private readonly ?ApVendorService $vendors = null,
    ) {
    }

    // ---- the routes ----------------------------------------------------------------------

    /**
     * `GET /invoices`. Query: `sort` (default `-created_date`), `limit` (default 200, at most 1,000), `id`, `status` (the
     * derived one), `deleted` (`1` or `true` for the Bin), `fromDate` and `toDate` (`yyyy-MM-dd`).
     *
     * The period keeps an invoice when `COALESCE(email_date, created_date)` lies from the start of the day before `fromDate`
     * to the end of the day after `toDate`, in UTC: a deliberate superset of what the browser's own cut keeps, with no time
     * zone on the server. The scope, the period and the limit are all applied in the query.
     *
     * @param array<string, mixed> $params the query string
     * @return list<array<string, mixed>>
     */
    public function list(ApActor $actor, array $params): array
    {
        $limit = self::limit($params['limit'] ?? null);
        $from = self::day($params['fromDate'] ?? null);
        $to = self::day($params['toDate'] ?? null);
        $sort = is_string($params['sort'] ?? null) && $params['sort'] !== '' ? $params['sort'] : '-' . ApInvoiceQuery::DEFAULT_SORT;
        $descending = str_starts_with($sort, '-');
        $key = $descending ? substr($sort, 1) : $sort;
        $today = $this->time->today();

        $rows = $this->invoices->page(new ApInvoiceQuery(
            self::flag($params['deleted'] ?? null),
            self::filter($params['id'] ?? null),
            self::filter($params['status'] ?? null),
            $from === null ? null : ApDates::utcMidnight($from, -1),
            $to === null ? null : ApDates::utcMidnight($to, 2),
            $actor->mailboxScope->mailboxes(),
            in_array($key, ApInvoiceQuery::SORTS, true) ? $key : ApInvoiceQuery::DEFAULT_SORT,
            $descending,
            $limit,
            $today,
        ));

        return array_map(static fn (ApInvoice $row): array => ApInvoicePresenter::invoice($row, $today), $rows);
    }

    /**
     * `GET /invoices/{id}`: the invoice, live or binned. 404 `Not found` for a missing id or one outside the person's scope.
     *
     * @return array<string, mixed>
     */
    public function get(ApActor $actor, string $id): array
    {
        return $this->present($this->visible($actor, $id));
    }

    /**
     * `GET /invoices/{id}/file`: the invoice's document, through the mailbox scope. A binned invoice is served. 404 `Not found`
     * for a missing or out-of-scope invoice, `This invoice has no document`, or `The file is missing`.
     */
    public function document(ApActor $actor, string $id): ApAttachmentFile
    {
        return $this->attachments->file($actor, $this->visible($actor, $id));
    }

    /**
     * `POST /invoices`: a manual capture. The body is the capture dialog's: the editable fields, `attachment`,
     * `extraction_confidence` and `upload_id` (the file `POST /uploads` kept, put on the invoice here).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(ApActor $actor, array $body): array
    {
        $columns = ApInvoiceInput::columns($body, true);
        $grade = ApGrading::forManualCapture($body, $this->reasonOf($columns), $this->confidenceThreshold);

        $upload = null;
        $uploadId = $body['upload_id'] ?? null;
        if (ApRules::truthy($uploadId)) {
            if (!is_string($uploadId)) {
                throw new ApInvalid('upload_id must be text');
            }
            $upload = $this->attachments->claim($actor, $uploadId);
        }
        $attachment = $upload !== null
            ? mb_substr($upload->originalName, 0, ApInvoice::TEXT_LIMITS['attachment'])
            : (ApInvoiceInput::text('attachment', $body['attachment'] ?? null, ApInvoice::TEXT_LIMITS['attachment']) ?? '');

        $now = $this->time->now();
        $invoice = (new ApInvoice(ApRules::newId(), $now, $now))
            ->withColumns($columns)
            ->with(
                status: $grade['status'],
                reviewReason: $grade['review_reason'],
                extractionConfidence: $grade['confidence'] === null ? null : round($grade['confidence'], 8),
                attachment: $attachment,
                fileId: $upload?->id,
                contentHash: $upload?->sha256,
            );
        $match = $this->autoMatchVendor(null, $invoice);
        if ($match !== null) {
            $invoice = $this->withVendorMatch($invoice, $match);
        }

        $this->invoices->transactional(function () use ($invoice, $upload, $actor): void {
            $this->invoices->insert($invoice);
            if ($upload !== null) {
                $this->attachments->attach($upload->id, $invoice->id, $actor->userId);
            }
        });

        return $this->present($this->stored($invoice->id));
    }

    /**
     * `PATCH /invoices/{id}`: changes the keys the body carries from the fixed list ({@see ApInvoice::PATCHABLE}); every other key
     * is dropped silently. A `Cleared by user` duplicate status writes the `duplicate_override` audit event.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(ApActor $actor, string $id, array $body): array
    {
        $existing = $this->visible($actor, $id);
        $columns = array_intersect_key(ApInvoiceInput::columns($body, false), array_flip(ApInvoice::PATCHABLE));
        $updated = $existing->withColumns($columns);
        $override = ($body['duplicate_status'] ?? null) === ApInvoice::DUPLICATE_CLEARED_BY_USER;
        $match = $columns === [] ? null : $this->autoMatchVendor($existing, $updated);
        $now = $this->time->now();

        if ($columns === [] && !$override) {
            return $this->present($existing);
        }
        $this->invoices->transactional(function () use ($actor, $id, $columns, $match, $override, $body, $now): void {
            if ($columns !== []) {
                $this->invoices->update($id, $columns, $now);
            }
            if ($match !== null) {
                $this->invoices->setVendorMatch($id, $match, null, $now);
            }
            if ($override) {
                $reason = $body['duplicate_reason'] ?? null;
                $this->record($actor, ApAuditEvent::DUPLICATE_OVERRIDE, $id, [
                    'reason' => ApRules::truthy($reason) && is_scalar($reason) ? (string) $reason : 'Marked not a duplicate',
                ], $now);
            }
        });

        return $this->present($this->stored($id));
    }

    /**
     * `DELETE /invoices/{id}`: into the Bin; with `$permanent` (`?permanent=1`, which needs the delete permission as well) for
     * good, from the Bin only.
     *
     * - A missing id is `{ok: true}` (idempotent); out of scope is 404.
     * - Into the Bin: `{ok, in_bin: true, invoice}`; an invoice already there is left as it is and writes no audit event.
     * - For good: an invoice not in the Bin is a 409 `Move the invoice to the Bin before deleting it permanently`. Otherwise,
     *   in one transaction: the audit event, then the children, the scan's tombstone and the row; then, after the commit, the
     *   file. A file that cannot be removed does not undo the delete: `files_orphaned: true`, and an audit row says where.
     *
     * @return array<string, mixed>
     */
    public function delete(ApActor $actor, string $id, bool $permanent): array
    {
        if ($permanent && !$actor->can(Module::PERMISSION_AP_INVOICES_DELETE)) {
            throw new ApForbidden(self::FORBIDDEN_PERMANENT);
        }
        $invoice = $this->invoices->find($id);
        if ($invoice === null) {
            return ['ok' => true];
        }
        $actor->mailboxScope->requireRecord($invoice->mailbox, self::NOT_FOUND);
        $now = $this->time->now();

        if (!$permanent) {
            if (!$invoice->isBinned()) {
                $this->invoices->transactional(function () use ($actor, $id, $invoice, $now): void {
                    $this->invoices->bin($id, $actor->userId, $actor->who(), $now);
                    $this->record($actor, ApAuditEvent::INVOICE_BINNED, $id, self::identity($invoice), $now);
                });
            }

            return ['ok' => true, 'in_bin' => true, 'invoice' => $this->present($this->stored($id))];
        }
        if (!$invoice->isBinned()) {
            throw new ApConflict(self::NOT_IN_BIN);
        }
        [$emailId, $skipKey] = self::tombstone($invoice);
        $this->invoices->transactional(function () use ($actor, $id, $invoice, $emailId, $skipKey, $now): void {
            $this->record($actor, ApAuditEvent::INVOICE_DELETED_PERMANENTLY, $id, self::identity($invoice), $now);
            $this->invoices->purge($id, $emailId, $skipKey, $now);
        });

        try {
            $this->attachments->removeInvoiceFiles($id, $invoice->fileId);
        } catch (Throwable $failure) {
            $this->noteOrphanedFiles($actor, $id, $failure);

            return ['ok' => true, 'in_bin' => false, 'files_orphaned' => true];
        }

        return ['ok' => true, 'in_bin' => false];
    }

    /**
     * `POST /invoices/{id}/restore`: clears the Bin marks, even on an invoice that was not binned, and always writes
     * `invoice_restored` (the source did).
     *
     * @return array<string, mixed>
     */
    public function restore(ApActor $actor, string $id): array
    {
        $invoice = $this->visible($actor, $id);
        $now = $this->time->now();
        $this->invoices->transactional(function () use ($actor, $id, $invoice, $now): void {
            $this->invoices->restore($id, $now);
            $this->record($actor, ApAuditEvent::INVOICE_RESTORED, $id, ['invoice_no' => self::blank($invoice->invoiceNo)], $now);
        });

        return $this->present($this->stored($id));
    }

    // ---- for the scan (T6.7) --------------------------------------------------------------

    /**
     * Rule 1, the exact file (D74): the oldest invoice, in any state, whose `content_hash` is this SHA-256 hex. The scan
     * checks it BEFORE the model is called and, when it finds one, files no invoice and stores no file.
     */
    public function findExactDuplicate(string $contentHash): ?ApInvoice
    {
        $hash = strtolower(trim($contentHash));

        return $hash === '' ? null : $this->invoices->findByContentHash($hash);
    }

    /**
     * Rule 2, the same business document (D74), on the model's RAW fields before `Unknown Vendor` or `INV-<epoch>` fill a
     * gap: the oldest invoice, in any state, with the same invoice number and vendor (trimmed, any case), currency (blank
     * reads AUD) and total equal to the cent. No invoice number, no vendor or no total means no duplicate.
     */
    public function findBusinessDuplicate(?string $invoiceNo, ?string $vendor, ?string $currency, ?float $total): ?ApInvoice
    {
        $number = mb_strtolower(ApRules::strip($invoiceNo ?? ''));
        $name = mb_strtolower(ApRules::strip($vendor ?? ''));
        if ($number === '' || $name === '' || $total === null || !is_finite($total)) {
            return null;
        }
        $code = strtoupper(ApRules::strip($currency ?? ''));

        return $this->invoices->findBusinessDuplicate($number, $name, $code === '' ? 'AUD' : $code, ApGrading::cents($total) / 100);
    }

    /**
     * Files the invoice the scan read from one attachment: the business-duplicate check (rule 2) on the raw fields, the
     * fallbacks (`Unknown Vendor`, `INV-<epoch>`), text cut to its column (a scan never fails on length), and the insert,
     * with the kept file put on the invoice in the same transaction. No audit event: the scan writes none.
     *
     * The returned invoice says the verdict: `duplicateStatus` Potential with `duplicateOfInvoiceId` and the exact reason
     * (`Possible duplicate: same vendor, invoice number, currency, and total`), or Clear.
     *
     * @param int|null $uploaderUserId who kept the file (the file store only lets that person put it on a record)
     */
    public function createFromScan(ApInvoiceCapture $capture, ?int $uploaderUserId): ApInvoice
    {
        $x = $capture->extraction;
        $duplicate = $this->findBusinessDuplicate($x->invoiceNo, $x->vendor, $x->currency, $x->totalAmount);
        $now = $this->time->now();
        $attachmentId = $capture->attachmentId;

        $invoice = new ApInvoice(
            ApRules::newId(),
            $now,
            $now,
            emailId: self::cut($capture->emailId, 200),
            mailbox: self::cut(strtolower(trim($capture->mailbox)), 320),
            emailDate: $capture->emailDate,
            sender: self::cut($capture->sender, ApInvoice::TEXT_LIMITS['sender']),
            subject: self::cut($capture->subject, ApInvoice::TEXT_LIMITS['subject']),
            emailRecordId: $capture->emailRecordId,
            // An id too long for its column is not stored cut: a cut id never matches, so the file name finds the capture instead.
            attachmentId: $attachmentId !== null && $attachmentId !== '' && mb_strlen($attachmentId) <= 400 ? $attachmentId : null,
            attachment: self::cut($capture->attachmentName, ApInvoice::TEXT_LIMITS['attachment']),
            fileId: $capture->fileId,
            contentHash: $capture->contentHash,
            invoiceNo: self::cut($x->invoiceNoOrGenerated($now->getTimestamp()), ApInvoice::TEXT_LIMITS['invoice_no']),
            vendor: self::cut($x->vendorOrUnknown(), ApInvoice::TEXT_LIMITS['vendor']),
            vendorEmail: self::cut($capture->vendorEmail ?? '', ApInvoice::TEXT_LIMITS['vendor_email']),
            taxId: self::cut($x->taxId ?? '', ApInvoice::TEXT_LIMITS['tax_id']),
            invoiceDate: ApDates::normalize($x->invoiceDate),
            dueDate: ApDates::normalize($x->dueDate),
            poNumber: self::cut($x->poNumber ?? '', ApInvoice::TEXT_LIMITS['po_number']),
            currency: self::cut($x->currency, ApInvoice::TEXT_LIMITS['currency']),
            netAmount: self::amountOrNull($x->netAmount),
            taxAmount: self::amountOrNull($x->taxAmount),
            totalAmount: self::amountOrNull($x->totalAmount),
            company: self::cut($x->company ?? '', ApInvoice::TEXT_LIMITS['company']),
            status: in_array($capture->status, ApInvoice::STORED_STATUSES, true) ? $capture->status : $x->status(),
            notes: $x->notes,
            duplicateStatus: $duplicate === null ? ApInvoice::DUPLICATE_CLEAR : ApInvoice::DUPLICATE_POTENTIAL,
            duplicateOfInvoiceId: $duplicate?->id,
            duplicateReason: $duplicate === null ? '' : ApInvoice::BUSINESS_DUPLICATE_REASON,
            extractionConfidence: round(min(max($x->extractionConfidence, 0.0), 1.0), 8),
            reviewReason: $capture->reviewReason,
        );
        $match = $this->autoMatchVendor(null, $invoice);
        if ($match !== null) {
            $invoice = $this->withVendorMatch($invoice, $match);
        }

        $this->invoices->transactional(function () use ($invoice, $uploaderUserId): void {
            $this->invoices->insert($invoice);
            if ($invoice->fileId !== null) {
                $this->attachments->attach($invoice->fileId, $invoice->id, $uploaderUserId);
            }
        });

        return $this->stored($invoice->id);
    }

    // ---- vendor matching on capture and on edit (T6.9, B5) ----------------------------------------

    /**
     * The vendor columns an invoice should get when it is created, or edited so that a vendor input changed (B5). Create, PATCH
     * and the scan all call it, and write what it returns (the columns before the insert, or `setVendorMatch` after a PATCH).
     *
     * - A new invoice (`$before` null) is always matched.
     * - An edit is matched only when the stored `vendor_match` is not one a person decided (`ApVendorMatch::DECIDED`, confirmed
     *   or new) AND at least one of `ApVendorMatch::INPUT_FIELDS` (`vendor`, `vendor_email`, `tax_id`, `company`) now differs
     *   from what was stored. `matched`, `unmatched` and never-tried are all matched again.
     * - Null leaves the five columns as they were: nothing to do, or no vendors are synced (the invoice stays untried, never
     *   "unmatched", so a later run picks it up). The matcher is given the invoice as it will be stored.
     */
    private function autoMatchVendor(?ApInvoice $before, ApInvoice $after): ?ApVendorMatch
    {
        if ($this->vendors === null) {
            return null;
        }
        if ($before !== null) {
            if (in_array($before->vendorMatch, ApVendorMatch::DECIDED, true)) {
                return null;
            }
            $changed = false;
            foreach (ApVendorMatch::INPUT_FIELDS as $field) {
                $property = ApInvoice::property($field);
                if ($before->{$property} !== $after->{$property}) {
                    $changed = true;
                    break;
                }
            }
            if (!$changed) {
                return null;
            }
        }

        return $this->vendors->autoMatch(ApVendorInvoice::fromInvoice($after));
    }

    private function withVendorMatch(ApInvoice $invoice, ApVendorMatch $match): ApInvoice
    {
        return $invoice->with(
            odooVendorId: $match->odooVendorId,
            vendorRef: $match->vendorRef ?? '',
            odooVendorName: $match->odooVendorName ?? '',
            vendorMatch: $match->vendorMatch,
            vendorMatchNote: $match->vendorMatchNote,
        );
    }

    // ---- helpers ---------------------------------------------------------------------------------

    /** The invoice, or `Not found` when it is missing or out of the person's scope (the same answer, so nothing leaks). */
    private function visible(ApActor $actor, string $id): ApInvoice
    {
        $invoice = $this->invoices->find($id) ?? throw new ApNotFound(self::NOT_FOUND);
        $actor->mailboxScope->requireRecord($invoice->mailbox, self::NOT_FOUND);

        return $invoice;
    }

    private function stored(string $id): ApInvoice
    {
        return $this->invoices->find($id) ?? throw new ApNotFound(self::NOT_FOUND);
    }

    /** @return array<string, mixed> */
    private function present(ApInvoice $invoice): array
    {
        return ApInvoicePresenter::invoice($invoice, $this->time->today());
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(ApActor $actor, string $action, string $id, array $details, DateTimeImmutable $at): void
    {
        $this->audit->record(new ApAuditEvent($action, ApAuditEvent::ENTITY_INVOICE, $id, $details, $at, $actor->userId, $actor->name));
    }

    /**
     * @return array{invoice_no: ?string, vendor: ?string}
     */
    private static function identity(ApInvoice $invoice): array
    {
        return ['invoice_no' => self::blank($invoice->invoiceNo), 'vendor' => self::blank($invoice->vendor)];
    }

    private static function blank(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * The scan's tombstone for a permanently deleted capture: its message and the attachment id (the file name when there is
     * none). Only an invoice that came from a message has one. A key wider than the table's column is never cut (a cut key
     * would match the wrong attachment): the file name stands in when it fits, and otherwise there is no tombstone.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function tombstone(ApInvoice $invoice): array
    {
        if ($invoice->emailId === null || $invoice->emailId === '') {
            return [null, null];
        }
        foreach ([$invoice->attachmentId, $invoice->attachment] as $key) {
            if ($key !== null && $key !== '' && mb_strlen($key) <= self::SKIP_KEY_WIDTH) {
                return [$invoice->emailId, $key];
            }
        }

        return [null, null];
    }

    /** An audit row (facts only: the invoice id, the error class and a short message) so the files can be found and removed. */
    private function noteOrphanedFiles(ApActor $actor, string $invoiceId, Throwable $failure): void
    {
        $message = mb_substr(preg_replace('/\s+/', ' ', $failure->getMessage()) ?? '', 0, 200);
        if ($this->platformAudit === null) {
            error_log('Tri-AP: files of purged invoice ' . $invoiceId . ' were not removed (' . $failure::class . ')');

            return;
        }
        try {
            $this->platformAudit->record(new FinanceAuditEvent(
                self::FILES_ORPHANED_ACTION,
                ApAttachmentService::RECORD_TYPE,
                $invoiceId,
                ['error' => $failure::class, 'message' => $message],
                $this->time->now(),
                $actor->userId,
                Module::APP_AP,
            ));
        } catch (Throwable $auditFailure) {
            error_log('Tri-AP: files of purged invoice ' . $invoiceId . ' were not removed (' . $failure::class . ') and the audit row failed (' . $auditFailure::class . ')');
        }
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function reasonOf(array $columns): ?string
    {
        $reason = $columns['review_reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /** `limit`: a whole number from 1; absent is 200; more than 1,000 is 1,000. Never "no limit". */
    private static function limit(mixed $value): int
    {
        if ($value === null || $value === '') {
            return ApInvoiceQuery::DEFAULT_LIMIT;
        }
        if (is_string($value) && preg_match('/^[+-]?\d{1,9}$/', trim($value)) === 1) {
            $number = (int) trim($value);
        } elseif (is_int($value)) {
            $number = $value;
        } else {
            throw new ApInvalid('limit must be a whole number');
        }
        if ($number < 1) {
            throw new ApInvalid('limit must be at least 1');
        }

        return min($number, ApInvoiceQuery::MAX_LIMIT);
    }

    /** A `yyyy-MM-dd` day from the query string; empty is none. */
    private static function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (is_string($value) ? ApDates::strict($value) : null) ?? throw new ApInvalid(self::BAD_DATE);
    }

    /** `?deleted=1` or `?deleted=true`: anything else lists the live rows. */
    private static function flag(mixed $value): bool
    {
        return $value === '1' || $value === 'true' || $value === true || $value === 1;
    }

    /** A query-string text that is present and not empty (the source's `if value:`); anything else is none. */
    private static function filter(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function cut(string $text, int $width): string
    {
        return mb_strlen($text) > $width ? mb_substr($text, 0, $width) : $text;
    }

    /** An amount the scan read, to the cent; null when there is none or the column could not hold it. */
    private static function amountOrNull(?float $value): ?float
    {
        if ($value === null || !is_finite($value) || abs($value) > 9999999999999999.0) {
            return null;
        }

        return ApGrading::cents($value) / 100;
    }
}
