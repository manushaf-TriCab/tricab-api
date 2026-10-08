<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;

/**
 * `create-finance-ar-schema.sql` is written, not applied, to any shared database (T5.2).
 * These checks read the script and need no database. Running it twice against the local
 * database, and the rolled-back inserts that prove each unique index and check, are the
 * T5.2 evidence in `FINANCE-PHASE-5.md`.
 */
final class FinanceArSchemaScriptTest extends TestCase
{
    private const TABLES = [
        'ar_email_records',
        'ar_email_attachments',
        'ar_remittance_lines',
        'ar_outbound_messages',
        'ar_credit_note_requests',
        'ar_scan_runs',
        'ar_option_lists',
        'ar_deleted_messages',
    ];

    private static function script(string $name): string
    {
        $path = dirname(__DIR__, 3) . '/database/scripts/development/' . $name;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    private static function code(): string
    {
        return preg_replace('~/\*.*?\*/~s', '', self::script('create-finance-ar-schema.sql')) ?? '';
    }

    /** The text of one table's CREATE TABLE statement, up to the next table. */
    private static function table(string $name): string
    {
        $code = self::code();
        $start = strpos($code, 'CREATE TABLE [finance].[' . $name . ']');
        self::assertIsInt($start, $name);
        $next = strpos($code, 'IF OBJECT_ID(', $start);
        $end = $next === false ? strlen($code) : $next;

        return substr($code, $start, $end - $start);
    }

    public function test_it_creates_exactly_the_eight_tables_each_only_if_missing(): void
    {
        $sql = self::script('create-finance-ar-schema.sql');

        preg_match_all('/CREATE TABLE \[finance\]\.\[([a-z_]+)\]/', $sql, $created);
        self::assertSame(self::TABLES, $created[1]);

        foreach (self::TABLES as $table) {
            self::assertStringContainsString("IF OBJECT_ID(N'[finance].[" . $table . "]', N'U') IS NULL", $sql, $table);
        }
    }

    public function test_it_says_it_is_not_applied_and_destroys_nothing(): void
    {
        $sql = self::script('create-finance-ar-schema.sql');

        self::assertStringContainsString('NOT APPLIED to any shared database', $sql);
        self::assertDoesNotMatchRegularExpression('/\b(DROP|DELETE|TRUNCATE|ALTER|UPDATE|INSERT)\b/i', self::code());
        self::assertSame(1, substr_count(self::code(), 'BEGIN TRANSACTION;'));
        self::assertStringContainsString('COMMIT TRANSACTION;', self::code());
    }

    public function test_it_follows_the_conventions(): void
    {
        $code = self::code();

        self::assertStringNotContainsString('DATETIMEOFFSET', $code);
        self::assertStringNotContainsString('DATETIME2(7)', $code);
        self::assertDoesNotMatchRegularExpression('/\bVARCHAR\(/i', preg_replace('/NVARCHAR\(/i', '', $code) ?? '');
        self::assertDoesNotMatchRegularExpression('/\b(MONEY|FLOAT|REAL)\b/i', $code);
        self::assertSame(count(self::TABLES) - 2, preg_match_all('/\[id\]\s+CHAR\(32\)\s+NOT NULL/', $code), 'every table but the option lists and the tombstones has a hex id');
        self::assertStringNotContainsString('[legacy_id]', $code);
    }

    public function test_no_foreign_key_leaves_the_finance_schema_and_none_cascades(): void
    {
        $code = self::code();

        self::assertDoesNotMatchRegularExpression('/REFERENCES\s+\[(?!finance\])/i', $code);
        self::assertStringNotContainsString('CASCADE', $code);
        preg_match_all('/REFERENCES \[finance\]\.\[([a-z_]+)\]/', $code, $targets);
        self::assertSame([], array_values(array_diff(array_unique($targets[1]), [...self::TABLES, 'attachments'])));
        self::assertContains('attachments', $targets[1], 'attachments key to the file store (decision D41)');
    }

    public function test_overdue_is_not_a_stored_status_and_the_statuses_are_checks(): void
    {
        $records = self::table('ar_email_records');

        self::assertStringContainsString("CHECK ([follow_up_status] IN (N'needs_review', N'in_progress', N'completed'))", $records);
        self::assertStringNotContainsString("N'overdue'", self::code(), 'Overdue is derived, never stored (question Q8).');
        self::assertStringContainsString("CHECK ([status] IN (N'new', N'reviewed', N'sent', N'skipped'))", $records);
        self::assertStringContainsString("CHECK ([extraction_status] IN (N'pending', N'extracted', N'failed', N'skipped'))", self::table('ar_email_attachments'));
        self::assertStringContainsString("N'form_sent', N'awaiting_sales_response', N'submitted', N'under_review', N'approved', N'rejected', N'completed'", self::table('ar_credit_note_requests'));
        self::assertStringContainsString("CHECK ([status] IN (N'running', N'completed', N'failed', N'stopped'))", self::table('ar_scan_runs'));
        self::assertStringContainsString("CHECK ([list_name] IN (N'category', N'method'))", self::table('ar_option_lists'));
    }

    public function test_the_register_columns_are_typed_so_they_can_be_sorted_and_searched(): void
    {
        $records = self::table('ar_email_records');

        self::assertMatchesRegularExpression('/\[subject\]\s+NVARCHAR\(400\)\s+NOT NULL/', $records);
        self::assertMatchesRegularExpression('/\[customer_name\]\s+NVARCHAR\(400\)/', $records);
        self::assertMatchesRegularExpression('/\[sender_name\]\s+NVARCHAR\(400\)/', $records);
        self::assertMatchesRegularExpression('/\[due_date\]\s+DATE\s+NULL/', $records);
        self::assertMatchesRegularExpression('/\[email_date\]\s+DATETIME2\(6\)\s+NOT NULL/', $records);
        foreach (['body', 'ai_summary', 'proposed_response', 'final_outcome'] as $long) {
            self::assertMatchesRegularExpression('/\[' . $long . '\]\s+NVARCHAR\(MAX\)/', $records, $long);
        }
        self::assertMatchesRegularExpression(
            '/CREATE INDEX \[IX_finance_ar_email_records_deleted_at\]\s+ON \[finance\]\.\[ar_email_records\] \(\[deleted_at\]\);/',
            $records,
        );
        self::assertDoesNotMatchRegularExpression('/INDEX[^;]*\[(body|ai_summary|proposed_response|final_outcome)\]/', $records, 'no index on a long column');
    }

    public function test_the_message_id_is_an_immutable_id_unique_only_when_present(): void
    {
        $records = self::table('ar_email_records');

        self::assertMatchesRegularExpression('/\[source_message_id\]\s+NVARCHAR\(200\)\s+NULL/', $records);
        self::assertMatchesRegularExpression(
            '/CREATE UNIQUE INDEX \[UX_finance_ar_email_records_source_message_id\]\s+ON \[finance\]\.\[ar_email_records\] \(\[source_message_id\]\)\s+WHERE \[source_message_id\] IS NOT NULL;/',
            $records,
        );
        self::assertMatchesRegularExpression('/\[message_unreachable\]\s+BIT\s+NOT NULL/', $records, 'the Q1 flag');
        self::assertMatchesRegularExpression('/\[source_message_id\]\s+NVARCHAR\(200\)\s+NOT NULL/', self::table('ar_deleted_messages'));
    }

    public function test_every_person_column_is_a_platform_user_id_with_the_name_at_the_time(): void
    {
        $code = self::code();

        foreach (['assigned_user_id', 'deleted_by_user_id', 'sender_user_id', 'requested_by_user_id'] as $column) {
            self::assertMatchesRegularExpression('/\[' . $column . '\]\s+BIGINT\s+NULL/', $code, $column);
        }
        self::assertMatchesRegularExpression('/\[assigned_name\]\s+NVARCHAR\(200\)/', self::table('ar_email_records'));
        self::assertDoesNotMatchRegularExpression('/\[assigned_person\]/', $code, 'replaced by the id and the name (decision D43)');
    }

    public function test_an_attachment_points_at_the_file_store_and_keeps_no_file_of_its_own(): void
    {
        $attachments = self::table('ar_email_attachments');

        self::assertMatchesRegularExpression('/\[attachment_id\]\s+CHAR\(32\)\s+NOT NULL/', $attachments);
        self::assertStringContainsString('REFERENCES [finance].[attachments] ([id])', $attachments);
        self::assertMatchesRegularExpression('/CREATE UNIQUE INDEX \[UX_finance_ar_email_attachments_file\]\s+ON \[finance\]\.\[ar_email_attachments\] \(\[attachment_id\]\);/', $attachments);
        foreach (['file_name', 'stored_name', 'content_type', 'size_bytes'] as $column) {
            self::assertStringNotContainsString('[' . $column . ']', $attachments, $column . ' lives in finance.attachments (decision D41)');
        }
        foreach (['payer_name', 'payment_date', 'payment_reference', 'currency', 'total_amount', 'extraction_confidence', 'reconciles', 'extraction_status', 'extraction_error', 'extracted_at'] as $column) {
            self::assertStringContainsString('[' . $column . ']', $attachments, $column);
        }
        self::assertMatchesRegularExpression('/\[reconciles\]\s+BIT\s+NULL/', $attachments, 'null is "nothing to check against"');
        self::assertMatchesRegularExpression('/\[total_amount\]\s+DECIMAL\(18,2\)/', $attachments);
        self::assertStringContainsString('[line_no]', self::table('ar_remittance_lines'));
        self::assertMatchesRegularExpression('/\[paid_amount\]\s+DECIMAL\(18,2\)/', self::table('ar_remittance_lines'));
    }

    public function test_the_outbound_link_keeps_the_body_and_has_no_key_to_the_ledger(): void
    {
        $outbound = self::table('ar_outbound_messages');

        self::assertMatchesRegularExpression('/\[body\]\s+NVARCHAR\(MAX\)\s+NOT NULL/', $outbound);
        self::assertMatchesRegularExpression('/\[delivery_id\]\s+CHAR\(32\)\s+NOT NULL/', $outbound);
        self::assertSame(1, substr_count($outbound, 'REFERENCES'), 'only the record');
        foreach (['to_addresses', 'cc_addresses', '[subject]', '[status]', '[error]', '[sent_at]', '[from_mailbox]'] as $column) {
            self::assertStringNotContainsString($column, $outbound, $column . ' is read from the mail ledger (decision D42)');
        }
    }

    public function test_the_credit_note_token_is_stored_only_as_a_hash(): void
    {
        $requests = self::table('ar_credit_note_requests');

        self::assertMatchesRegularExpression('/\[token_hash\]\s+CHAR\(64\)\s+NOT NULL/', $requests);
        self::assertDoesNotMatchRegularExpression('/\[token\]/', $requests, 'no token column (question Q6)');
        self::assertMatchesRegularExpression('/CREATE UNIQUE INDEX \[UX_finance_ar_credit_note_requests_token_hash\]\s+ON \[finance\]\.\[ar_credit_note_requests\] \(\[token_hash\]\);/', $requests);
        self::assertMatchesRegularExpression('/\[credit_amount\]\s+DECIMAL\(18,2\)/', $requests);
    }

    public function test_a_scan_run_holds_a_claim(): void
    {
        $runs = self::table('ar_scan_runs');

        self::assertMatchesRegularExpression(
            '/CREATE UNIQUE INDEX \[UX_finance_ar_scan_runs_one_running\]\s+ON \[finance\]\.\[ar_scan_runs\] \(\[mailbox\]\)\s+WHERE \[status\] = N\'running\';/',
            $runs,
        );
        self::assertStringContainsString('[claim_expires_at]', $runs);
        self::assertMatchesRegularExpression('/\[watermark\]\s+DATETIME2\(6\)\s+NULL/', $runs);
        self::assertMatchesRegularExpression('/\[from_date\]\s+DATE\s+NULL/', $runs);
    }

    public function test_the_option_lists_key_on_list_and_value(): void
    {
        $options = self::table('ar_option_lists');

        self::assertStringContainsString('PRIMARY KEY CLUSTERED ([list_name], [value])', $options);
        self::assertMatchesRegularExpression('/\[active\]\s+BIT/', $options);
        self::assertMatchesRegularExpression('/\[is_system\]\s+BIT/', $options);
    }

    public function test_it_is_part_of_the_local_rebuild_after_the_tables_it_keys_to(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');
        $at = strpos($rebuild, "'create-finance-ar-schema.sql'");

        self::assertIsInt($at);
        self::assertLessThan($at, strpos($rebuild, "'create-finance-schema.sql'"));
        self::assertLessThan($at, strpos($rebuild, "'create-finance-attachments-schema.sql'"));
    }
}
