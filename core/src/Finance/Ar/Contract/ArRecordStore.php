<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ar\Domain\ArExtraction;
use TriCab\Core\Finance\Ar\Domain\ArKeptAttachment;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArRecordQuery;

/**
 * The register's records (`ar_email_records`) and what hangs off them. Storage only: the rules (what
 * may be edited, which options are valid, the Bin's order of events, the overdue date) are
 * `ArRecordService`'s, so both adapters behave the same. Nothing here writes the derived "overdue".
 */
interface ArRecordStore
{
    /**
     * Runs `$work` as one unit: commits when it returns, rolls back when it throws, and rethrows. A
     * call inside another joins it.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;

    /**
     * One page of records and how many match in all, filtered, searched and sorted as the query says
     * (the filter is applied before the page). A row has no `body` unless the query is for a single
     * record; the search may still look in it.
     *
     * @return array{0: list<ArRecord>, 1: int}
     */
    public function page(ArRecordQuery $query): array;

    /** One record with its body, live or binned; null when there is none. */
    public function find(string $id): ?ArRecord;

    /**
     * The record's attachments as the API shows them: the row's metadata (file name, type and size from the
     * file store, the payment header, the extraction columns), each with its remittance `line_items` in
     * order. No blob path.
     *
     * @return list<array<string, mixed>>
     */
    public function attachmentsOf(string $recordId): array;

    /**
     * Adds a record (the scan's ingest, T5.7). A second record with the same message id is an `ArConflict`.
     */
    public function insert(ArRecord $record): void;

    /**
     * Writes what a person may edit (category, method, both statuses, assignee, due date, outcome,
     * customer name, summary, proposed response, sales rep address), the reminder marker and
     * `updated_date`. Nothing else of the row changes.
     */
    public function update(ArRecord $record): void;

    /** Moves a live record to the Bin; a record already there is left as it is. */
    public function bin(string $id, ?int $byUserId, string $by, DateTimeImmutable $at): void;

    public function restore(string $id, DateTimeImmutable $at): void;

    /**
     * Deletes a record for good, children first: its remittance lines, attachment rows and
     * outbound-message rows; then writes its message id (when it has one) to `ar_deleted_messages`,
     * replacing an older tombstone; then the record. Call it inside a transaction.
     */
    public function purge(string $id, DateTimeImmutable $at): void;

    /**
     * The dashboard's counts over the live records in the range (either end may be null): `total`, a
     * count per derived status, a count per category; plus the whole Bin and the credit-note requests
     * by status, which the period never bounds.
     *
     * @return array{total: int, statuses: array<string, int>, categories: array<string, int>, in_bin: int, credit_notes: array<string, int>}
     */
    public function metrics(?DateTimeImmutable $start, ?DateTimeImmutable $end, string $today): array;

    /** True when a record (live or binned) has this id. Cheaper than {@see find()}: it reads no body. */
    public function exists(string $id): bool;

    /**
     * The `finance.attachments` id of the file behind an attachment row, only when the row belongs to the record;
     * null for an unknown row or another record's row.
     */
    public function attachmentFileId(string $recordId, string $attachmentId): ?string;

    /** Adds the row for a file already in the file store (T5.5). */
    public function addAttachment(ArKeptAttachment $attachment): void;

    /** True when a record (live or binned) already has this Graph message id, whatever its case. */
    public function messageIdKnown(string $messageId): bool;

    /** True when the message was deleted for good (`ar_deleted_messages`), so a scan must not bring it back. */
    public function isTombstoned(string $messageId): bool;

    /**
     * Stores what the model read from an attachment: the payment header on its row (status `extracted`) and one
     * remittance line per invoice, numbered in order, with the ids given. In one transaction.
     *
     * @param list<string> $lineIds one new id per line of the extraction
     */
    public function saveExtraction(string $attachmentId, string $recordId, ArExtraction $extraction, DateTimeImmutable $at, array $lineIds): void;

    /** Marks an attachment's extraction `failed` with a short reason (the scan carries on). */
    public function markExtractionFailed(string $attachmentId, string $error, DateTimeImmutable $at): void;

    /**
     * The records an overdue reminder is due for (T5.11): live (not in the Bin), stored `needs_review` or `in_progress`,
     * with a due date before `$today` (`yyyy-MM-dd`, the Tri-AR zone's) and no reminder sent yet; oldest due first, then
     * by id, at most `$limit`. Rows carry no body.
     *
     * @return list<ArRecord>
     */
    public function overdueAwaitingReminder(string $today, int $limit): array;

    /**
     * Records that a reminder went, only while none has: true for the caller that set it, false when one was already
     * there (or the record is gone). Touches nothing else, not even the update time.
     */
    public function markReminderSent(string $id, DateTimeImmutable $at): bool;
}
