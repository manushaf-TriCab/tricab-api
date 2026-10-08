<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Ar\Domain\ArKeptAttachment;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArScanRun;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentInvalid;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentRules;

/**
 * One source row to one target row, column by column, by the rules of T5.15: ids kept when they fit `CHAR(32)`
 * lower-case hex and derived otherwise; timestamps to UTC; `due_date` to a real `yyyy-MM-dd` or null; `overdue`
 * to `in_progress`; text cut to the column (counted); money to two places. A value the target's check or column
 * would refuse throws {@see ArMigrationBadValue} and the row is not written. What was changed on the way is
 * counted in the report's `findings`, by code word, never by value.
 */
final class ArRowMapper
{
    public const DEFAULT_MAILBOX = 'ar@tricab.com';

    private const FOLLOW_UP_STATUSES = ['needs_review', 'in_progress', 'completed'];

    private const FILE_TYPES = [
        'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'tif' => 'image/tiff', 'tiff' => 'image/tiff',
    ];

    public function __construct(
        private readonly ArMigrationReport $report,
        private readonly ArMigrationUsers $users,
        private readonly ArAttachmentFolder $folder,
    ) {
    }

    /** The id a source row keeps or is given in the target. */
    public function id(string $table, mixed $sourceId): string
    {
        $text = is_scalar($sourceId) ? (string) $sourceId : '';
        if ($text === '') {
            throw new ArMigrationBadValue('id_missing');
        }
        if (preg_match('/^[0-9a-f]{32}$/', $text) === 1) {
            return $text;
        }
        $this->report->add('findings', 'ids_derived.' . $table);

        return substr(hash('sha256', 'tri-ar:' . $table . ':' . $text), 0, 32);
    }

    /** The `finance.attachments` id for a source attachment row: derived from its id, so a repeat finds the same file. */
    public static function fileId(string $attachmentRowId): string
    {
        return substr(hash('sha256', 'tri-ar:file:' . $attachmentRowId), 0, 32);
    }

    /**
     * @param array<string, mixed> $r
     * @param string|null $messageId the immutable id, or null
     */
    public function record(array $r, string $id, ?string $messageId, bool $unreachable): ArRecord
    {
        $created = $this->time($r, 'created_date') ?? throw new ArMigrationBadValue('created_date_invalid');
        $assigned = $this->text($r, 'assigned_person') ?? '';
        $deletedBy = $this->text($r, 'deleted_by') ?? '';
        $creditNote = $this->text($r, 'credit_note_request_id');
        $creditNoteKept = $creditNote !== null && preg_match('/^[0-9a-f]{32}$/', $creditNote) === 1;
        if ($creditNote !== null && $creditNote !== '' && !$creditNoteKept) {
            $this->report->add('findings', 'credit_note_link_dropped');
        }
        $this->assignee($assigned);

        return new ArRecord(
            id: $id,
            emailDate: $this->time($r, 'email_date') ?? throw new ArMigrationBadValue('email_date_invalid'),
            customerName: $this->cut('customer_name', $this->text($r, 'customer_name'), 400),
            senderEmail: $this->cut('sender_email', $this->text($r, 'sender_email'), 320),
            senderName: $this->cut('sender_name', $this->text($r, 'sender_name'), 400),
            subject: $this->cut('subject', $this->text($r, 'subject'), 400),
            body: $this->text($r, 'body'),
            aiSummary: $this->text($r, 'ai_summary') ?? '',
            followUpCategory: $this->cut('follow_up_category', $this->blankTo($this->text($r, 'follow_up_category'), 'other'), 100),
            assignedUserId: $this->users->resolve($assigned),
            assignedName: $this->cut('assigned_person', $assigned, 200),
            proposedResponseType: $this->cut('proposed_response_type', $this->blankTo($this->text($r, 'proposed_response_type'), 'email'), 100),
            proposedResponse: $this->text($r, 'proposed_response') ?? '',
            followUpStatus: $this->followUpStatus($this->text($r, 'follow_up_status')),
            dueDate: $this->day($this->text($r, 'due_date')),
            finalOutcome: $this->text($r, 'final_outcome') ?? '',
            sourceMessageId: $messageId,
            messageUnreachable: $unreachable,
            mailbox: $this->cut('mailbox', $this->blankTo($this->text($r, 'mailbox'), self::DEFAULT_MAILBOX), 320),
            classificationConfidence: $this->confidence($r, 'classification_confidence'),
            status: $this->oneOf($this->blankTo($this->text($r, 'status'), 'new'), ArRecord::STATUSES, 'status_not_allowed'),
            assignedSalesRepEmail: $this->cut('assigned_sales_rep_email', $this->text($r, 'assigned_sales_rep_email'), 320),
            creditNoteRequestId: $creditNoteKept ? $creditNote : null,
            reminderSentAt: $this->time($r, 'reminder_sent_at'),
            deletedAt: $this->time($r, 'deleted_at'),
            deletedByUserId: $this->users->resolve($deletedBy),
            deletedBy: $this->cut('deleted_by', $deletedBy, 320),
            createdAt: $created,
            updatedAt: $this->time($r, 'updated_date') ?? $created,
        );
    }

    /** @param array<string, mixed> $r */
    public function attachment(array $r, string $id, string $recordId): ArMigratedAttachment
    {
        $created = $this->time($r, 'created_date') ?? throw new ArMigrationBadValue('created_date_invalid');
        $stored = $this->text($r, 'stored_name') ?? '';
        $file = $this->folder->inspect($stored);

        return new ArMigratedAttachment(
            id: $id,
            recordId: $recordId,
            fileId: self::fileId($id),
            isRemittance: $this->flag($r, 'is_remittance') ?? false,
            payerName: $this->cutOrNull('payer_name', $this->text($r, 'payer_name'), 400),
            paymentDate: $this->cutOrNull('payment_date', $this->text($r, 'payment_date'), 40),
            paymentReference: $this->cutOrNull('payment_reference', $this->text($r, 'payment_reference'), 200),
            currency: $this->cut('currency', $this->blankTo($this->text($r, 'currency'), 'AUD'), 10),
            totalAmount: $this->money($r, 'total_amount'),
            extractionConfidence: $this->confidence($r, 'extraction_confidence'),
            reconciles: $this->flag($r, 'reconciles'),
            extractionStatus: $this->oneOf($this->blankTo($this->text($r, 'extraction_status'), 'pending'), ArKeptAttachment::STATUSES, 'extraction_status_not_allowed'),
            extractionError: $this->cutOrNull('extraction_error', $this->text($r, 'extraction_error'), 1000),
            notes: $this->text($r, 'notes'),
            extractedAt: $this->lenientTime($r, 'extracted_at'),
            createdAt: $created,
            updatedAt: $this->time($r, 'updated_date') ?? $created,
            fileName: $this->fileName($this->text($r, 'file_name'), $stored),
            contentType: $this->contentType($this->text($r, 'content_type'), $stored),
            filePath: $file['path'],
            fileSize: $file['size'],
        );
    }

    /** @param array<string, mixed> $r */
    public function line(array $r, string $id, string $attachmentId, string $recordId, int $lineNo, DateTimeImmutable $fallbackCreated): ArMigratedLine
    {
        return new ArMigratedLine(
            id: $id,
            attachmentId: $attachmentId,
            recordId: $recordId,
            lineNo: $lineNo,
            invoiceNumber: $this->cutOrNull('invoice_number', $this->text($r, 'invoice_number'), 100),
            invoiceDate: $this->cutOrNull('invoice_date', $this->text($r, 'invoice_date'), 40),
            grossAmount: $this->money($r, 'gross_amount'),
            discountAmount: $this->money($r, 'discount_amount'),
            paidAmount: $this->money($r, 'paid_amount'),
            createdAt: $this->time($r, 'created_date') ?? $fallbackCreated,
        );
    }

    /** @param array<string, mixed> $r */
    public function scanRun(array $r, string $id): ArMigratedScanRun
    {
        $started = $this->time($r, 'started_at') ?? throw new ArMigrationBadValue('started_at_invalid');
        $status = $this->oneOf($this->text($r, 'status') ?? '', [ArScanRun::RUNNING, ArScanRun::COMPLETED, ArScanRun::FAILED, ArScanRun::STOPPED], 'scan_status_not_allowed');
        if ($status === ArScanRun::RUNNING) {
            // A run still "running" in a copy of the data is a dead one; kept as running it would hold the mailbox's claim.
            $this->report->add('findings', 'running_scan_run_closed');
            $status = ArScanRun::FAILED;
        }
        $requestedBy = $this->text($r, 'requested_by') ?? '';
        $watermark = $this->time($r, 'watermark');
        if ($status === ArScanRun::COMPLETED && $watermark === null) {
            $this->report->add('findings', 'completed_scan_run_without_watermark');
        }

        return new ArMigratedScanRun(
            id: $id,
            startedAt: $started,
            finishedAt: $this->lenientTime($r, 'finished_at'),
            mailbox: $this->cut('mailbox', $this->blankTo($this->text($r, 'mailbox'), self::DEFAULT_MAILBOX), 320),
            status: $status,
            watermark: $watermark,
            totalFetched: $this->counter($r, 'total_fetched'),
            newRecords: $this->counter($r, 'new_records'),
            skippedRecords: $this->counter($r, 'skipped_records'),
            failedEnrichments: $this->counter($r, 'failed_enrichments'),
            errors: $this->text($r, 'errors'),
            fromDate: $this->day($this->text($r, 'from_date')),
            toDate: $this->day($this->text($r, 'to_date')),
            requestedByUserId: $this->users->resolve($requestedBy),
            requestedBy: $this->cut('requested_by', $requestedBy, 320),
            totalMessages: $this->counter($r, 'total_messages'),
            processedMessages: $this->counter($r, 'processed_messages'),
            createdAt: $this->time($r, 'created_date') ?? $started,
        );
    }

    /** @param array<string, mixed> $r */
    public function option(array $r): ArOption
    {
        $value = $this->text($r, 'value') ?? '';
        if ($value === '') {
            throw new ArMigrationBadValue('option_value_missing');
        }
        if (mb_strlen($value) > 100) {
            // The value is the option's identity and what records refer to; it is not cut.
            throw new ArMigrationBadValue('option_value_too_long');
        }
        $created = $this->time($r, 'created_date') ?? throw new ArMigrationBadValue('created_date_invalid');

        return new ArOption(
            listName: $this->oneOf($this->text($r, 'list_name') ?? '', ArOption::LISTS, 'list_name_not_allowed'),
            value: $value,
            label: $this->cut('label', $this->text($r, 'label'), 200),
            description: $this->cut('description', $this->text($r, 'description'), 2000),
            color: $this->cut('color', $this->blankTo($this->text($r, 'color'), 'slate'), 20),
            sortOrder: $this->counter($r, 'sort_order'),
            active: $this->flag($r, 'active') ?? true,
            isSystem: $this->flag($r, 'is_system') ?? false,
            createdAt: $created,
            updatedAt: $this->time($r, 'updated_date') ?? $created,
        );
    }

    /** @param array<string, mixed> $r */
    public function tombstoneDate(array $r): DateTimeImmutable
    {
        return $this->time($r, 'deleted_date') ?? throw new ArMigrationBadValue('deleted_date_invalid');
    }

    // ---- columns ------------------------------------------------------------------------

    /** @param array<string, mixed> $r */
    private function text(array $r, string $column): ?string
    {
        $value = $r[$column] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        return mb_scrub((string) $value, 'UTF-8');
    }

    private function blankTo(?string $value, string $default): string
    {
        return $value === null || trim($value) === '' ? $default : $value;
    }

    private function assignee(string $assigned): void
    {
        if (trim($assigned) === '') {
            $this->report->add('findings', 'assignee_blank');

            return;
        }
        $this->report->add('findings', $this->users->resolve($assigned) === null ? 'assignee_not_resolved' : 'assignee_resolved');
    }

    /** Text cut to a column's width (counted in UTF-16 units, as SQL Server counts), never in the middle of a pair. */
    private function cut(string $column, ?string $value, int $max): string
    {
        $value ??= '';
        if (strlen($value) <= $max) {
            return $value;
        }
        $utf16 = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        if (strlen($utf16) <= $max * 2) {
            return $value;
        }
        $this->report->add('findings', 'text_cut.' . $column);
        $utf16 = substr($utf16, 0, $max * 2);
        $last = unpack('v', substr($utf16, -2));
        if (is_array($last) && $last[1] >= 0xD800 && $last[1] <= 0xDBFF) {
            $utf16 = substr($utf16, 0, -2);
        }

        return mb_convert_encoding($utf16, 'UTF-8', 'UTF-16LE');
    }

    private function cutOrNull(string $column, ?string $value, int $max): ?string
    {
        return $value === null ? null : $this->cut($column, $value, $max);
    }

    /** @param array<string, mixed> $r */
    private function time(array $r, string $column): ?DateTimeImmutable
    {
        $text = $this->text($r, $column);
        if ($text === null || trim($text) === '') {
            return null;
        }
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,9}))?)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/i';
        if (preg_match($pattern, trim($text), $m) !== 1) {
            throw new ArMigrationBadValue($column . '_invalid');
        }
        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        [$h, $mi, $s] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        if ($y < 1 || !checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            throw new ArMigrationBadValue($column . '_invalid');
        }
        $fraction = substr(str_pad($m[7] ?? '', 6, '0'), 0, 6);
        $zone = $m[8] ?? 'Z';
        $zone = strtoupper($zone) === 'Z' ? 'UTC' : (strlen($zone) === 5 ? substr($zone, 0, 3) . ':' . substr($zone, 3) : $zone);
        $at = DateTimeImmutable::createFromFormat('Y-n-j G:i:s.u', sprintf('%d-%d-%d %d:%02d:%02d.%s', $y, $mo, $d, $h, $mi, $s, $fraction), new DateTimeZone($zone));
        if ($at === false) {
            throw new ArMigrationBadValue($column . '_invalid');
        }

        return $at->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * An optional timestamp of no consequence (when an extraction finished): unreadable reads as absent, counted.
     *
     * @param array<string, mixed> $r
     */
    private function lenientTime(array $r, string $column): ?DateTimeImmutable
    {
        try {
            return $this->time($r, $column);
        } catch (ArMigrationBadValue) {
            $this->report->add('findings', 'timestamp_unreadable.' . $column);

            return null;
        }
    }

    /** A calendar day from the first ten characters, or null (counted) when they are not a real date. */
    private function day(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $text = trim($value);
        $day = substr($text, 0, 10);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) !== 1 || (int) $m[1] < 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $this->report->add('findings', 'date_not_a_date');

            return null;
        }
        if (strlen($text) > 10) {
            $this->report->add('findings', 'date_cut_to_ten_characters');
        }

        return $day;
    }

    private function followUpStatus(?string $value): string
    {
        $value = $this->blankTo($value, 'in_progress');
        if ($value === ArRecord::STATUS_OVERDUE) {
            // Overdue is derived on read on the platform (question Q8); the source stored it.
            $this->report->add('findings', 'overdue_to_in_progress');

            return ArRecord::STATUS_IN_PROGRESS;
        }

        return $this->oneOf($value, self::FOLLOW_UP_STATUSES, 'follow_up_status_not_allowed');
    }

    /** @param list<string> $allowed */
    private function oneOf(string $value, array $allowed, string $kind): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new ArMigrationBadValue($kind);
        }

        return $value;
    }

    /** @param array<string, mixed> $r */
    private function flag(array $r, string $column): ?bool
    {
        $value = $r[$column] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value !== 0.0 : in_array(strtolower(is_scalar($value) ? (string) $value : ''), ['true', 'yes'], true);
    }

    /** @param array<string, mixed> $r */
    private function confidence(array $r, string $column): ?float
    {
        $value = $r[$column] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new ArMigrationBadValue($column . '_not_a_number');
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < 0.0 || $number > 1.0) {
            throw new ArMigrationBadValue($column . '_out_of_range');
        }

        return round($number, 8);
    }

    /** @param array<string, mixed> $r */
    private function money(array $r, string $column): ?string
    {
        $value = $r[$column] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new ArMigrationBadValue($column . '_not_a_number');
        }
        $number = (float) $value;
        if (!is_finite($number) || abs($number) >= 1.0E+15) {
            throw new ArMigrationBadValue($column . '_out_of_range');
        }
        $rounded = round($number, 2);
        if (abs($rounded - $number) > 1.0E-9) {
            $this->report->add('findings', 'amount_rounded_to_cents');
        }

        return number_format($rounded, 2, '.', '');
    }

    /** @param array<string, mixed> $r */
    private function counter(array $r, string $column): int
    {
        $value = $r[$column] ?? null;
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 2147483647) {
            throw new ArMigrationBadValue($column . '_out_of_range');
        }

        return (int) $value;
    }

    private function fileName(?string $name, string $storedName): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1f\x7f]/', '_', $name ?? ''));
        if ($clean === '') {
            $clean = trim((string) preg_replace('/[\x00-\x1f\x7f]/', '_', basename(str_replace('\\', '/', $storedName))));
        }
        if ($clean === '') {
            $clean = 'attachment';
        }
        if (mb_strlen($clean) > FinanceAttachmentRules::MAX_NAME_LENGTH) {
            $clean = mb_substr($clean, 0, FinanceAttachmentRules::MAX_NAME_LENGTH);
        }
        if ($clean !== $name) {
            $this->report->add('findings', 'file_name_adjusted');
        }

        return $clean;
    }

    private function contentType(?string $type, string $storedName): string
    {
        if ($type !== null && trim($type) !== '') {
            try {
                return FinanceAttachmentRules::cleanContentType($type);
            } catch (FinanceAttachmentInvalid) {
                // fall through to the extension
            }
        }
        $this->report->add('findings', 'content_type_adjusted');
        $extension = strtolower(pathinfo($storedName, PATHINFO_EXTENSION));

        return self::FILE_TYPES[$extension] ?? FinanceAttachmentRules::DEFAULT_CONTENT_TYPE;
    }
}
