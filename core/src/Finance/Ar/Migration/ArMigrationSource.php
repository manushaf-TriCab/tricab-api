<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Migration;

/**
 * The old Tri-AR app's data, read only (T5.15). Rows come as the source stores them (text, numbers and nulls),
 * in the source's own `rowid` order; a column the source's file does not have reads as absent. Nothing here
 * converts or checks a value: that is {@see ArRowMapper}'s.
 */
interface ArMigrationSource
{
    /** The source's tables the tool knows about, and how many rows each holds. */
    public const TABLES = ['email_records', 'email_attachments', 'remittance_lines', 'scan_runs', 'option_lists', 'deleted_messages', 'outbound_messages', 'credit_note_requests'];

    /** @return array<string, int> rows per table, for every name in {@see TABLES} */
    public function counts(): array;

    /** @return iterable<array<string, mixed>> */
    public function records(): iterable;

    /** @return list<array{id: mixed, mailbox: mixed, source_message_id: mixed}> */
    public function messageRefs(): array;

    /** @return list<array<string, mixed>> */
    public function attachmentsOf(string $recordId): array;

    /** @return list<array<string, mixed>> in `rowid` order */
    public function linesOf(string $attachmentId): array;

    /** @return iterable<array<string, mixed>> */
    public function scanRuns(): iterable;

    /** @return iterable<array<string, mixed>> */
    public function optionRows(): iterable;

    /** @return iterable<array<string, mixed>> */
    public function deletedMessages(): iterable;

    /** @return list<string> attachment rows whose record is not in the file */
    public function orphanAttachmentIds(): array;

    /** @return list<string> remittance lines whose attachment is not in the file */
    public function orphanLineIds(): array;
}
