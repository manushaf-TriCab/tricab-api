<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Contract\ArCreditNoteStore;
use TriCab\Core\Finance\Ar\Contract\ArOutboundStore;
use TriCab\Core\Finance\Ar\Contract\ArRecordStore;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArCreditNote;
use TriCab\Core\Finance\Ar\Domain\ArExtraction;
use TriCab\Core\Finance\Ar\Domain\ArKeptAttachment;
use TriCab\Core\Finance\Ar\Domain\ArOption;
use TriCab\Core\Finance\Ar\Domain\ArOutboundMessage;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArRecordQuery;
use TriCab\Core\Finance\Ar\Service\ArPresenter;

/**
 * The register held in memory, serving the option lists (through its parent) and the records together,
 * so an option's usage count sees the records. It filters, searches, sorts and pages as the SQL does:
 * case-insensitive "contains", the derived status in the filter and the sort, the message id unique where
 * present, the page applied after the filter, and a transaction that restores a snapshot on failure.
 *
 * Attachment rows, outbound-message rows and credit-note counts are set by tests; the scan (T5.7), the
 * attachments (T5.5), the composer (T5.9) and the credit-note requests (T5.10) will fill them for real.
 */
class InMemoryArStore extends InMemoryArOptionStore implements ArRecordStore, ArOutboundStore, ArCreditNoteStore
{
    /** @var array<string, ArRecord> */
    protected array $records = [];

    /** @var array<string, list<array<string, mixed>>> attachment metadata by record id, each with `line_items` */
    protected array $attachmentRows = [];

    /** @var array<string, array{0: string, 1: string}> attachment row id => [record id, file id] */
    protected array $attachmentFiles = [];

    /** @var array<string, list<string>> outbound-message ids by record id */
    protected array $outboundRows = [];

    /** @var array<string, ArOutboundMessage> the link rows written by the composer, by id */
    protected array $outboundMessages = [];

    /** @var array<string, ArCreditNote> the credit-note requests, by id */
    protected array $creditNotes = [];

    /** @var array<string, DateTimeImmutable> message id => when it was deleted for good */
    protected array $tombstones = [];

    /** @var array<string, int> credit-note request count by status */
    protected array $creditNoteCounts = [];

    // ---- test helpers -------------------------------------------------------------------

    /** @param list<array<string, mixed>> $rows */
    public function setAttachmentRows(string $recordId, array $rows): void
    {
        $this->attachmentRows[$recordId] = $rows;
    }

    public function addOutboundRow(string $recordId, string $id): void
    {
        $this->outboundRows[$recordId][] = $id;
    }

    /** @param array<string, int> $counts */
    public function setCreditNoteCounts(array $counts): void
    {
        $this->creditNoteCounts = $counts;
    }

    /** @return array<string, DateTimeImmutable> */
    public function tombstones(): array
    {
        return $this->tombstones;
    }

    public function outboundCount(string $recordId): int
    {
        return count($this->outboundRows[$recordId] ?? []);
    }

    public function attachmentCount(string $recordId): int
    {
        return count($this->attachmentRows[$recordId] ?? []);
    }

    /** The stored row, as the SQL would hold it (the stored status, never the derived one). */
    public function stored(string $id): ?ArRecord
    {
        return $this->records[$id] ?? null;
    }

    // ---- ArOptionStore ------------------------------------------------------------------

    protected function usageCounts(string $listName): array
    {
        $out = [];
        foreach ($this->records as $record) {
            $value = $listName === ArOption::LIST_CATEGORY ? $record->followUpCategory : ($listName === ArOption::LIST_METHOD ? $record->proposedResponseType : null);
            if ($value !== null) {
                $out[$value] = ($out[$value] ?? 0) + 1;
            }
        }

        return $out;
    }

    // ---- ArRecordStore ------------------------------------------------------------------

    public function page(ArRecordQuery $query): array
    {
        $matches = [];
        foreach ($this->records as $record) {
            if ($this->matches($record, $query)) {
                $matches[] = $record;
            }
        }
        usort($matches, function (ArRecord $a, ArRecord $b) use ($query): int {
            $order = [self::sortKey($a, $query), $a->id] <=> [self::sortKey($b, $query), $b->id];

            return $query->descending ? -$order : $order;
        });
        $page = array_slice($matches, $query->offset, $query->limit);

        return [array_map(static fn (ArRecord $r): ArRecord => $r->with(body: null), $page), count($matches)];
    }

    public function find(string $id): ?ArRecord
    {
        return $this->records[$id] ?? null;
    }

    public function attachmentsOf(string $recordId): array
    {
        return $this->attachmentRows[$recordId] ?? [];
    }

    public function exists(string $id): bool
    {
        return isset($this->records[$id]);
    }

    public function attachmentFileId(string $recordId, string $attachmentId): ?string
    {
        $found = $this->attachmentFiles[$attachmentId] ?? null;

        return $found !== null && $found[0] === $recordId ? $found[1] : null;
    }

    public function addAttachment(ArKeptAttachment $attachment): void
    {
        $this->attachmentFiles[$attachment->id] = [$attachment->recordId, $attachment->fileId];
        $stamp = ArPresenter::iso($attachment->at);
        $this->attachmentRows[$attachment->recordId][] = [
            'id' => $attachment->id,
            'email_record_id' => $attachment->recordId,
            'file_name' => $attachment->fileName,
            'content_type' => $attachment->contentType,
            'size_bytes' => $attachment->sizeBytes,
            'is_remittance' => false,
            'payer_name' => '',
            'payment_date' => null,
            'payment_reference' => null,
            'currency' => 'AUD',
            'total_amount' => null,
            'extraction_confidence' => null,
            'reconciles' => null,
            'extraction_status' => $attachment->extractionStatus,
            'extraction_error' => null,
            'notes' => '',
            'extracted_at' => null,
            'created_date' => $stamp,
            'updated_date' => $stamp,
            'line_items' => [],
        ];
    }

    public function addOutbound(ArOutboundMessage $message): void
    {
        $this->outboundMessages[$message->id] = $message;
        $this->outboundRows[$message->recordId][] = $message->id;
    }

    public function outboundOf(string $recordId): array
    {
        $rows = array_values(array_filter($this->outboundMessages, static fn (ArOutboundMessage $m): bool => $m->recordId === $recordId));
        usort($rows, static fn (ArOutboundMessage $a, ArOutboundMessage $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);

        return $rows;
    }

    // ---- ArCreditNoteStore ---------------------------------------------------------------

    public function insertCreditNote(ArCreditNote $note): void
    {
        foreach ($this->creditNotes as $other) {
            if ($other->tokenHash === $note->tokenHash) {
                throw new ArConflict('That token is already in use');
            }
        }
        $this->creditNotes[$note->id] = $note;
    }

    public function findCreditNote(string $id): ?ArCreditNote
    {
        return $this->creditNotes[$id] ?? null;
    }

    public function pageCreditNotes(?string $status, ?string $customerName, ?string $salesRepEmail, int $limit, int $offset): array
    {
        $rows = array_values(array_filter($this->creditNotes, static function (ArCreditNote $n) use ($status, $customerName, $salesRepEmail): bool {
            return ($status === null || $n->status === $status)
                && ($customerName === null || mb_stripos($n->customerName, $customerName) !== false)
                && ($salesRepEmail === null || strcasecmp($n->salesRepEmail, $salesRepEmail) === 0);
        }));
        usort($rows, static fn (ArCreditNote $a, ArCreditNote $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);

        return [array_slice($rows, $offset, $limit), count($rows)];
    }

    public function updateCreditNote(ArCreditNote $note): void
    {
        $stored = $this->creditNotes[$note->id] ?? null;
        if ($stored === null) {
            return;
        }
        // Only what a person may edit: never the token, the expiry or the dates the flow owns.
        $this->creditNotes[$note->id] = $stored->with(
            customerName: $note->customerName,
            customerEmail: $note->customerEmail,
            salesRepName: $note->salesRepName,
            salesRepEmail: $note->salesRepEmail,
            invoiceNumber: $note->invoiceNumber,
            creditAmount: $note->creditAmount,
            reason: $note->reason,
            originalRequest: $note->originalRequest,
            status: $note->status,
            reviewedAt: $note->reviewedAt,
            updatedAt: $note->updatedAt,
        );
    }

    public function replaceCreditNoteToken(string $id, string $tokenHash, DateTimeImmutable $expiresAt, DateTimeImmutable $at): bool
    {
        $stored = $this->creditNotes[$id] ?? null;
        if ($stored === null || !$stored->isOpen()) {
            return false;
        }
        $this->creditNotes[$id] = $stored->with(tokenHash: $tokenHash, tokenExpiresAt: $expiresAt, formSentDate: $at, updatedAt: $at);

        return true;
    }

    public function submitCreditNote(string $id, string $invoiceNumber, float $creditAmount, string $reason, string $salesRepName, DateTimeImmutable $at): bool
    {
        $stored = $this->creditNotes[$id] ?? null;
        if ($stored === null || !$stored->isOpen()) {
            return false;
        }
        $this->creditNotes[$id] = $stored->with(
            invoiceNumber: $invoiceNumber,
            creditAmount: $creditAmount,
            reason: $reason,
            salesRepName: $salesRepName,
            status: ArCreditNote::SUBMITTED,
            submittedDate: $at,
            updatedAt: $at,
        );

        return true;
    }

    public function overdueAwaitingReminder(string $today, int $limit): array
    {
        $due = array_values(array_filter($this->records, static fn (ArRecord $r): bool => $r->deletedAt === null
            && $r->reminderSentAt === null
            && $r->effectiveStatus($today) === ArRecord::STATUS_OVERDUE));
        usort($due, static fn (ArRecord $a, ArRecord $b): int => [$a->dueDate, $a->id] <=> [$b->dueDate, $b->id]);

        return array_map(static fn (ArRecord $r): ArRecord => $r->with(body: null), array_slice($due, 0, $limit));
    }

    public function markReminderSent(string $id, DateTimeImmutable $at): bool
    {
        $record = $this->records[$id] ?? null;
        if ($record === null || $record->reminderSentAt !== null) {
            return false;
        }
        $this->records[$id] = $record->with(reminderSentAt: $at);

        return true;
    }

    public function messageIdKnown(string $messageId): bool
    {
        foreach ($this->records as $record) {
            if ($record->sourceMessageId !== null && strtolower($record->sourceMessageId) === strtolower($messageId)) {
                return true;
            }
        }

        return false;
    }

    public function isTombstoned(string $messageId): bool
    {
        return isset($this->tombstones[$messageId]);
    }

    public function saveExtraction(string $attachmentId, string $recordId, ArExtraction $extraction, DateTimeImmutable $at, array $lineIds): void
    {
        $lines = [];
        foreach ($extraction->lines as $n => $line) {
            $lines[] = [
                'id' => $lineIds[$n] ?? 'line-' . $n,
                'attachment_id' => $attachmentId,
                'email_record_id' => $recordId,
                'invoice_number' => $line['invoice_number'],
                'invoice_date' => $line['invoice_date'],
                'gross_amount' => $line['gross_amount'],
                'discount_amount' => $line['discount_amount'],
                'paid_amount' => $line['paid_amount'],
                'created_date' => ArPresenter::iso($at),
            ];
        }
        foreach ($this->attachmentRows[$recordId] ?? [] as $i => $row) {
            if ($row['id'] === $attachmentId) {
                $this->attachmentRows[$recordId][$i] = array_merge($row, [
                    'is_remittance' => $extraction->isRemittance,
                    'payer_name' => $extraction->payerName,
                    'payment_date' => $extraction->paymentDate,
                    'payment_reference' => $extraction->paymentReference,
                    'currency' => $extraction->currency,
                    'total_amount' => $extraction->totalAmount,
                    'extraction_confidence' => $extraction->confidence,
                    'reconciles' => $extraction->reconciles(),
                    'extraction_status' => 'extracted',
                    'extraction_error' => null,
                    'notes' => $extraction->notes,
                    'extracted_at' => ArPresenter::iso($at),
                    'updated_date' => ArPresenter::iso($at),
                    'line_items' => $lines,
                ]);
            }
        }
    }

    public function markExtractionFailed(string $attachmentId, string $error, DateTimeImmutable $at): void
    {
        foreach ($this->attachmentRows as $recordId => $rows) {
            foreach ($rows as $i => $row) {
                if ($row['id'] === $attachmentId) {
                    $this->attachmentRows[$recordId][$i] = array_merge($row, ['extraction_status' => 'failed', 'extraction_error' => mb_substr($error, 0, 1000), 'updated_date' => ArPresenter::iso($at)]);
                }
            }
        }
    }

    public function insert(ArRecord $record): void
    {
        if ($record->sourceMessageId !== null) {
            foreach ($this->records as $other) {
                if ($other->sourceMessageId !== null && strtolower($other->sourceMessageId) === strtolower($record->sourceMessageId)) {
                    throw new ArConflict('That message has already been recorded');
                }
            }
        }
        $this->records[$record->id] = $record;
    }

    public function update(ArRecord $record): void
    {
        $current = $this->records[$record->id] ?? null;
        if ($current === null) {
            return;
        }
        // Only what the SQL writes; the rest of the row stays as it was.
        $this->records[$record->id] = $current->with(
            followUpCategory: $record->followUpCategory,
            proposedResponseType: $record->proposedResponseType,
            followUpStatus: $record->followUpStatus,
            status: $record->status,
            assignedUserId: $record->assignedUserId,
            assignedName: $record->assignedName,
            dueDate: $record->dueDate,
            finalOutcome: $record->finalOutcome,
            customerName: $record->customerName,
            aiSummary: $record->aiSummary,
            proposedResponse: $record->proposedResponse,
            assignedSalesRepEmail: $record->assignedSalesRepEmail,
            creditNoteRequestId: $record->creditNoteRequestId,
            reminderSentAt: $record->reminderSentAt,
            updatedAt: $record->updatedAt,
        );
    }

    public function bin(string $id, ?int $byUserId, string $by, DateTimeImmutable $at): void
    {
        $record = $this->records[$id] ?? null;
        if ($record !== null && $record->deletedAt === null) {
            $this->records[$id] = $record->with(deletedAt: $at, deletedByUserId: $byUserId, deletedBy: $by, updatedAt: $at);
        }
    }

    public function restore(string $id, DateTimeImmutable $at): void
    {
        $record = $this->records[$id] ?? null;
        if ($record !== null) {
            $this->records[$id] = $record->with(deletedAt: null, deletedByUserId: null, deletedBy: '', updatedAt: $at);
        }
    }

    public function purge(string $id, DateTimeImmutable $at): void
    {
        $record = $this->records[$id] ?? null;
        if ($record === null) {
            return;
        }
        if ($record->sourceMessageId !== null) {
            $this->tombstones[$record->sourceMessageId] = $at;
        }
        foreach ($this->attachmentFiles as $rowId => [$ownerId]) {
            if ($ownerId === $id) {
                unset($this->attachmentFiles[$rowId]);
            }
        }
        foreach ($this->outboundRows[$id] ?? [] as $outboundId) {
            unset($this->outboundMessages[$outboundId]);
        }
        unset($this->attachmentRows[$id], $this->outboundRows[$id], $this->records[$id]);
    }

    public function metrics(?DateTimeImmutable $start, ?DateTimeImmutable $end, string $today): array
    {
        $total = 0;
        $statuses = [];
        $categories = [];
        $bin = 0;
        foreach ($this->records as $record) {
            if ($record->deletedAt !== null) {
                ++$bin;
                continue;
            }
            if (($start !== null && $record->emailDate < $start) || ($end !== null && $record->emailDate >= $end)) {
                continue;
            }
            ++$total;
            $status = $record->effectiveStatus($today);
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
            $categories[$record->followUpCategory] = ($categories[$record->followUpCategory] ?? 0) + 1;
        }

        $credit = $this->creditNoteCounts;
        if ($this->creditNotes !== []) {
            $credit = [];
            foreach ($this->creditNotes as $note) {
                $credit[$note->status] = ($credit[$note->status] ?? 0) + 1;
            }
        }

        return ['total' => $total, 'statuses' => $statuses, 'categories' => $categories, 'in_bin' => $bin, 'credit_notes' => $credit];
    }

    // ---- the filter, as the SQL has it --------------------------------------------------

    private function matches(ArRecord $r, ArRecordQuery $q): bool
    {
        if (($r->deletedAt !== null) !== $q->deleted) {
            return false;
        }
        if ($q->category !== null && strtolower($r->followUpCategory) !== strtolower($q->category)) {
            return false;
        }
        if ($q->followUpStatus !== null && strtolower($r->effectiveStatus($q->today)) !== strtolower($q->followUpStatus)) {
            return false;
        }
        if ($q->status !== null && strtolower($r->status) !== strtolower($q->status)) {
            return false;
        }
        if ($q->customerName !== null && !self::contains($r->customerName, $q->customerName)) {
            return false;
        }
        if ($q->assignedPerson !== null && !self::contains($r->assignedName, $q->assignedPerson)) {
            return false;
        }
        if (($q->start !== null && $r->emailDate < $q->start) || ($q->end !== null && $r->emailDate >= $q->end)) {
            return false;
        }
        if ($q->search !== null) {
            $fields = [$r->subject, $r->customerName, $r->senderEmail, $r->senderName, $r->aiSummary];
            if ($q->searchBody) {
                $fields[] = $r->body ?? '';
            }
            foreach ($fields as $field) {
                if (self::contains($field, $q->search)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }

    private static function sortKey(ArRecord $r, ArRecordQuery $q): string|float
    {
        return match ($q->sortBy) {
            'customer_name' => mb_strtolower($r->customerName),
            'sender_email' => mb_strtolower($r->senderEmail),
            'subject' => mb_strtolower($r->subject),
            'follow_up_category' => mb_strtolower($r->followUpCategory),
            'assigned_person' => mb_strtolower($r->assignedName),
            'proposed_response_type' => mb_strtolower($r->proposedResponseType),
            'follow_up_status' => $r->effectiveStatus($q->today),
            'due_date' => $r->dueDate ?? '',
            'classification_confidence' => $r->classificationConfidence ?? -1.0,
            'status' => mb_strtolower($r->status),
            'created_date' => $r->createdAt->format('Y-m-d H:i:s.u'),
            'deleted_at' => $r->deletedAt?->format('Y-m-d H:i:s.u') ?? '',
            default => $r->emailDate->format('Y-m-d H:i:s.u'),
        };
    }
}
