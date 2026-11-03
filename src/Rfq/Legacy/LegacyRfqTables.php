<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

/**
 * The old app's RFQ tables as the platform holds them (create-rfq-schema.sql,
 * create-rfq-accessory-tables.sql): primary keys, in foreign-key order, parents
 * first.
 */
final class LegacyRfqTables
{
    /** Table => primary-key columns. */
    public const TABLES = [
        'rfq_roles' => ['role'],
        'rfq_user_roles' => ['email'],
        'rfq_identities' => ['email'],
        'rfq_draft_quotes' => ['id'],
        'rfq_draft_rows' => ['id'],
        'rfq_draft_activity' => ['id'],
        'rfq_draft_row_edits' => ['id'],
        'rfq_notification_outbox' => ['id'],
        'rfq_reader_jobs' => ['id'],
        'rfq_sync_attempts' => ['id'],
        'rfq_sessions' => ['id'],
        'rfq_entries' => ['id'],
        'rfq_correction_memory' => ['normalized_text', 'product_code'],
        'rfq_mail_audit' => ['internet_message_id'],
        'rfq_activity_log' => ['id'],
        'rfq_quote_relationships' => ['id'],
        'rfq_user_accessory_preferences' => ['user_email'],
    ];

    /**
     * Table => columns only the platform has. The refresh neither compares, reads, writes
     * nor hashes them: the database fills them (`rfq_number` takes the sequence's next value).
     */
    public const PLATFORM_ONLY = [
        'rfq_draft_quotes' => ['rfq_number'],
    ];

    /**
     * Child table => [column, parent table], for the foreign keys inside
     * `[rfq]`. `rfq_documents` is the platform's own (no source rows), but it
     * points at drafts, so a draft it references is never deleted.
     */
    public const PARENTS = [
        'rfq_draft_rows' => ['draft_id', 'rfq_draft_quotes'],
        'rfq_draft_activity' => ['draft_id', 'rfq_draft_quotes'],
        'rfq_draft_row_edits' => ['draft_id', 'rfq_draft_quotes'],
        'rfq_entries' => ['session_id', 'rfq_sessions'],
        'rfq_documents' => ['draft_id', 'rfq_draft_quotes'],
    ];

    /** An RFQ's header and lines move as one: never some lines from each side. */
    public const DRAFTS = 'rfq_draft_quotes';
    public const LINES = 'rfq_draft_rows';
}
