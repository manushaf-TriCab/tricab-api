<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;

/**
 * `create-finance-invoice-schema.sql` (Phase 6, task T6.2): what the script says, read as text, and what the database
 * then enforces, on a real local SQL Server (skipped without one). Same approach as `FinanceArSchemaScriptTest`.
 */
final class FinanceApSchemaScriptTest extends TestCase
{
    private const SCRIPT = 'create-finance-invoice-schema.sql';

    /** In the order the script creates them: a table comes after the ones it keys to. */
    private const TABLES = [
        'invoice_companies',
        'invoice_cost_centres',
        'invoice_account_categories',
        'invoice_signing_limits',
        'invoice_scan_runs',
        'invoice_emails',
        'invoice_invoices',
        'invoice_email_attachments',
        'invoice_scan_skips',
        'invoice_approval_batches',
        'invoice_approval_items',
        'invoice_approval_decisions',
        'invoice_outbound_messages',
        'invoice_audit_events',
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
        return preg_replace('~/\*.*?\*/~s', '', self::script(self::SCRIPT)) ?? '';
    }

    /** The text of one table's block, up to the next table. */
    private static function table(string $name): string
    {
        $code = self::code();
        $start = strpos($code, 'CREATE TABLE [finance].[' . $name . ']');
        self::assertIsInt($start, $name);
        $next = strpos($code, 'IF OBJECT_ID(', $start);
        $end = $next === false ? strlen($code) : $next;

        return substr($code, $start, $end - $start);
    }

    public function test_it_creates_exactly_the_fourteen_tables_each_only_if_missing(): void
    {
        $sql = self::script(self::SCRIPT);

        preg_match_all('/CREATE TABLE \[finance\]\.\[([a-z_]+)\]/', $sql, $created);
        self::assertSame(self::TABLES, $created[1]);
        foreach (self::TABLES as $table) {
            self::assertStringContainsString("IF OBJECT_ID(N'[finance].[" . $table . "]', N'U') IS NULL", $sql, $table);
        }
        // Question Q14: people and mailboxes are the platform's.
        self::assertNotContains('invoice_users', $created[1]);
        self::assertNotContains('invoice_mailboxes', $created[1]);
    }

    public function test_it_says_it_is_not_applied_and_destroys_nothing(): void
    {
        $sql = self::script(self::SCRIPT);
        $code = self::code();

        self::assertStringContainsString('STATUS: NOT APPLIED to any shared database', $sql);
        foreach (['ALTER', 'DROP', 'DELETE', 'UPDATE', 'TRUNCATE', 'INSERT', 'MERGE'] as $verb) {
            self::assertDoesNotMatchRegularExpression('/\b' . $verb . '\s+(TABLE|INDEX|FROM|INTO|\[)/i', $code, $verb);
        }
        self::assertStringContainsString('BEGIN TRANSACTION', $code);
        self::assertStringContainsString('THROW 54231', $code, 'it refuses without the file store it keys to');
    }

    public function test_it_follows_the_conventions(): void
    {
        $code = self::code();

        self::assertDoesNotMatchRegularExpression('/\]\s+(VARCHAR|TEXT|NTEXT|FLOAT|REAL|MONEY|DATETIME|DATETIMEOFFSET)\b/i', $code, 'unicode text, decimal money, DATETIME2');
        self::assertDoesNotMatchRegularExpression('/DATETIME2(?!\(6\))/', $code, 'six-digit time everywhere');
        preg_match_all('/\[id\]\s+(\S+)/', $code, $ids);
        self::assertSame(['CHAR(32)'], array_values(array_unique($ids[1])), 'every id is 32 hex characters');
        foreach (['net_amount', 'tax_amount', 'total_amount', 'po_inventory', 'po_non_inventory', 'expense_claim', 'customer_order', 'credit_notes'] as $money) {
            self::assertMatchesRegularExpression('/\[' . $money . '\]\s+DECIMAL\(18,2\)\s+NULL/', $code, $money);
        }
    }

    public function test_no_foreign_key_leaves_the_finance_schema_and_none_cascades(): void
    {
        $code = self::code();

        preg_match_all('/REFERENCES\s+\[([a-z_]+)\]\./i', $code, $schemas);
        self::assertNotSame([], $schemas[1]);
        self::assertSame(['finance'], array_values(array_unique($schemas[1])));
        self::assertDoesNotMatchRegularExpression('/ON\s+(DELETE|UPDATE)\s+CASCADE/i', $code);
    }

    public function test_overdue_is_not_a_stored_status_and_the_page_values_keep_their_capitals(): void
    {
        $invoices = self::table('invoice_invoices');

        self::assertStringContainsString("CHECK ([status] IN (N'Needs Review', N'In Progress', N'Completed', N'Exported'))", $invoices);
        self::assertStringNotContainsString('Overdue', $invoices, 'overdue is derived from the due date on read (question Q4)');
        self::assertStringContainsString("N'Clear', N'Potential', N'Cleared by user'", $invoices);
        self::assertStringContainsString("N'Not requested', N'Pending approval', N'Approved', N'Rejected'", $invoices);
        self::assertStringContainsString("N'matched', N'confirmed', N'unmatched', N'new'", $invoices);
        self::assertMatchesRegularExpression('/\[due_date\]\s+DATE\s+NULL/', $invoices);
        self::assertMatchesRegularExpression('/\[invoice_date\]\s+DATE\s+NULL/', $invoices);
    }

    public function test_the_register_can_be_filtered_and_sorted_on_indexed_bounded_columns(): void
    {
        $invoices = self::table('invoice_invoices');

        foreach (['deleted_at', 'status', 'mailbox', 'due_date', 'email_date', 'content_hash', 'invoice_no'] as $column) {
            self::assertMatchesRegularExpression('/CREATE INDEX \[IX_[a-z_]+\]\s+ON \[finance\]\.\[invoice_invoices\] \(\[' . $column . '\]/', $invoices, $column);
        }
        foreach (['notes', 'review_reason'] as $long) {
            self::assertMatchesRegularExpression('/\[' . $long . '\]\s+NVARCHAR\(MAX\)/', $invoices, $long);
            self::assertDoesNotMatchRegularExpression('/ON \[finance\]\.\[invoice_invoices\] \([^)]*\[' . $long . '\]/', $invoices, $long . ' is never indexed or sorted on');
        }
        foreach (['vendor', 'subject', 'sender'] as $bounded) {
            self::assertMatchesRegularExpression('/\[' . $bounded . '\]\s+NVARCHAR\(400\)/', $invoices, $bounded);
        }
    }

    public function test_a_file_is_a_row_of_the_file_store_and_no_address_is_kept(): void
    {
        $code = self::code();

        self::assertStringNotContainsString('attachment_url', $code);
        self::assertStringNotContainsString('stored_url', $code);
        foreach (['invoice_invoices', 'invoice_email_attachments'] as $table) {
            $block = self::table($table);
            self::assertMatchesRegularExpression('/\[file_id\]\s+CHAR\(32\)\s+NULL/', $block, $table);
            self::assertStringContainsString('FOREIGN KEY ([file_id]) REFERENCES [finance].[attachments] ([id])', $block, $table);
            self::assertMatchesRegularExpression('/\[content_hash\]\s+CHAR\(64\)\s+NULL/', $block, $table . ' keeps the hash duplicates are found by');
        }
    }

    public function test_every_person_column_is_a_platform_user_id(): void
    {
        $code = self::code();

        preg_match_all('/\[([a-z_]*user_id)\]\s+(\S+)\s+(NOT NULL|NULL)/', $code, $people, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(9, count($people));
        foreach ($people as $column) {
            self::assertSame(['BIGINT', 'NULL'], [$column[2], $column[3]], $column[1]);
        }
        // The source's portal-id columns are gone.
        foreach (['[prepared_by] ', '[decided_by] ', '[approver_id]', '[password_hash]', '[allowed_mailboxes]'] as $gone) {
            self::assertStringNotContainsString($gone, $code, $gone);
        }
    }

    public function test_the_outbound_link_keeps_the_body_and_takes_the_rest_from_the_ledger(): void
    {
        $outbound = self::table('invoice_outbound_messages');

        self::assertMatchesRegularExpression('/\[delivery_id\]\s+CHAR\(32\)\s+NOT NULL/', $outbound);
        self::assertMatchesRegularExpression('/\[body\]\s+NVARCHAR\(MAX\)/', $outbound);
        foreach (['to_addresses', 'cc_addresses', '[subject]', '[status]', '[error]', 'sent_at', 'from_mailbox', 'graph_message_id'] as $ledger) {
            self::assertStringNotContainsString($ledger, $outbound, $ledger . ' is the mail ledger\'s');
        }
    }

    public function test_a_scan_holds_one_claim_for_the_app(): void
    {
        $runs = self::table('invoice_scan_runs');

        self::assertStringContainsString("CHECK ([status] IN (N'Listing', N'Running', N'Complete', N'Failed'))", $runs);
        self::assertMatchesRegularExpression("/CREATE UNIQUE INDEX \\[UX_finance_invoice_scan_runs_one_open\\]\\s+ON \\[finance\\]\\.\\[invoice_scan_runs\\] \\(\\[claim_scope\\]\\)\\s+WHERE \\[status\\] IN \\(N'Listing', N'Running'\\)/", $runs);
        self::assertMatchesRegularExpression('/\[claim_expires_at\]\s+DATETIME2\(6\)\s+NULL/', $runs);
    }

    public function test_it_is_part_of_the_local_rebuild_after_the_tables_it_keys_to(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');

        $last = -1;
        foreach (['create-finance-schema.sql', 'create-finance-attachments-schema.sql', self::SCRIPT] as $name) {
            $at = strpos($rebuild, "'" . $name . "'");
            self::assertIsInt($at, $name);
            self::assertGreaterThan($last, $at, $name);
            $last = $at;
        }
    }

    // ---- on a real server ------------------------------------------------------------------------

    private static function refused(PDO $pdo, string $sql): bool
    {
        try {
            $pdo->exec($sql);
        } catch (PDOException) {
            return true;
        }

        return false;
    }

    private static function scalar(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);
        $value = $statement->fetchColumn();
        self::assertIsNumeric($value);

        return (int) $value;
    }

    #[Group('local-sql-server')]
    public function test_on_a_real_server_it_runs_twice_and_the_database_enforces_the_rules(): void
    {
        // connect() applies every finance schema script, this one among them; a second connect is the second run.
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $tables = "SELECT COUNT(*) FROM sys.tables t JOIN sys.schemas s ON s.[schema_id] = t.[schema_id] WHERE s.[name] = N'finance' AND t.[name] LIKE N'invoice[_]%'";
        $indexes = "SELECT COUNT(*) FROM sys.indexes i JOIN sys.tables t ON t.[object_id] = i.[object_id] JOIN sys.schemas s ON s.[schema_id] = t.[schema_id] WHERE s.[name] = N'finance' AND t.[name] LIKE N'invoice[_]%' AND i.[index_id] > 0";
        $before = [self::scalar($pdo, $tables), self::scalar($pdo, $indexes)];
        self::assertSame(14, $before[0]);
        self::assertNotNull(LocalFinanceSqlServer::connect());
        self::assertSame($before, [self::scalar($pdo, $tables), self::scalar($pdo, $indexes)], 'a second run changes nothing');

        LocalFinanceSqlServer::truncate($pdo);
        // The schema scripts leave XACT_ABORT on for the connection; with it on, the first refusal would roll the whole probe back.
        $pdo->exec('SET XACT_ABORT OFF');
        $pdo->beginTransaction();
        try {
            $id = static fn (int $n): string => str_pad((string) $n, 32, '0', STR_PAD_LEFT);
            $pdo->exec("INSERT INTO [finance].[invoice_invoices] ([id], [vendor], [total_amount], [due_date]) VALUES ('{$id(1)}', N'Société Générale 株式会社', 1234.56, '2026-10-01')");

            self::assertSame(123456, self::scalar($pdo, "SELECT CAST([total_amount] * 100 AS INT) FROM [finance].[invoice_invoices] WHERE [id] = '{$id(1)}'"), 'money to the cent');
            self::assertSame(1, self::scalar($pdo, "SELECT COUNT(*) FROM [finance].[invoice_invoices] WHERE [status] = N'Needs Review' AND [duplicate_status] = N'Clear' AND [approval_status] = N'Not requested' AND [vendor_match] IS NULL AND [mailbox] IS NULL"), 'the defaults are the source\'s');
            self::assertSame(1, self::scalar($pdo, "SELECT COUNT(*) FROM [finance].[invoice_invoices] WHERE [vendor] = N'Société Générale 株式会社'"), 'unicode comes back');
            self::assertTrue(self::refused($pdo, "UPDATE [finance].[invoice_invoices] SET [status] = N'Overdue' WHERE [id] = '{$id(1)}'"), 'overdue is never stored');
            self::assertTrue(self::refused($pdo, "UPDATE [finance].[invoice_invoices] SET [vendor_match] = N'guessed' WHERE [id] = '{$id(1)}'"));
            self::assertTrue(self::refused($pdo, "UPDATE [finance].[invoice_invoices] SET [extraction_confidence] = 1.5 WHERE [id] = '{$id(1)}'"));
            self::assertTrue(self::refused($pdo, "UPDATE [finance].[invoice_invoices] SET [file_id] = '{$id(99)}' WHERE [id] = '{$id(1)}'"), 'a file must be in the file store');

            // One open scan for the app; finished ones do not hold the claim.
            $pdo->exec("INSERT INTO [finance].[invoice_scan_runs] ([id], [status]) VALUES ('{$id(10)}', N'Listing')");
            self::assertTrue(self::refused($pdo, "INSERT INTO [finance].[invoice_scan_runs] ([id], [status]) VALUES ('{$id(11)}', N'Running')"), 'a second open scan is refused');
            $pdo->exec("UPDATE [finance].[invoice_scan_runs] SET [status] = N'Complete' WHERE [id] = '{$id(10)}'");
            $pdo->exec("INSERT INTO [finance].[invoice_scan_runs] ([id], [status]) VALUES ('{$id(11)}', N'Running')");
            $pdo->exec("UPDATE [finance].[invoice_scan_runs] SET [status] = N'Failed' WHERE [id] = '{$id(11)}'");
            self::assertSame(2, self::scalar($pdo, 'SELECT COUNT(*) FROM [finance].[invoice_scan_runs]'));

            // A message is stored once per mailbox; the same id in another mailbox is another message.
            $pdo->exec("INSERT INTO [finance].[invoice_emails] ([id], [graph_message_id], [mailbox]) VALUES ('{$id(20)}', N'MSG-1', N'ap@example.test')");
            self::assertTrue(self::refused($pdo, "INSERT INTO [finance].[invoice_emails] ([id], [graph_message_id], [mailbox]) VALUES ('{$id(21)}', N'msg-1', N'ap@example.test')"), 'the same message, in any case');
            $pdo->exec("INSERT INTO [finance].[invoice_emails] ([id], [graph_message_id], [mailbox]) VALUES ('{$id(21)}', N'MSG-1', N'other@example.test')");
            self::assertTrue(self::refused($pdo, "UPDATE [finance].[invoice_emails] SET [classification] = N'Maybe' WHERE [id] = '{$id(20)}'"));

            // Two files with no Graph id on one email are two files; the same Graph id twice is refused.
            $pdo->exec("INSERT INTO [finance].[invoice_email_attachments] ([id], [email_record_id]) VALUES ('{$id(30)}', '{$id(20)}'), ('{$id(31)}', '{$id(20)}')");
            $pdo->exec("INSERT INTO [finance].[invoice_email_attachments] ([id], [email_record_id], [graph_attachment_id]) VALUES ('{$id(32)}', '{$id(20)}', N'ATT-1')");
            self::assertTrue(self::refused($pdo, "INSERT INTO [finance].[invoice_email_attachments] ([id], [email_record_id], [graph_attachment_id]) VALUES ('{$id(33)}', '{$id(20)}', N'ATT-1')"));
            self::assertTrue(self::refused($pdo, "INSERT INTO [finance].[invoice_email_attachments] ([id], [email_record_id]) VALUES ('{$id(34)}', '{$id(98)}')"), 'an attachment needs its email');

            // A tombstone once; an invoice once in a batch; a position once; a company name once.
            $pdo->exec("INSERT INTO [finance].[invoice_scan_skips] ([id], [email_id], [attachment]) VALUES ('{$id(40)}', N'MSG-1', N'invoice.pdf')");
            self::assertTrue(self::refused($pdo, "INSERT INTO [finance].[invoice_scan_skips] ([id], [email_id], [attachment]) VALUES ('{$id(41)}', N'MSG-1', N'invoice.pdf')"));
            $pdo->exec("INSERT INTO [finance].[invoice_approval_batches] ([id]) VALUES ('{$id(50)}')");
            $pdo->exec("INSERT INTO [finance].[invoice_approval_items] ([id], [batch_id], [invoice_id]) VALUES ('{$id(51)}', '{$id(50)}', '{$id(1)}')");
            self::assertTrue(self::refused($pdo, "INSERT INTO [finance].[invoice_approval_items] ([id], [batch_id], [invoice_id]) VALUES ('{$id(52)}', '{$id(50)}', '{$id(1)}')"));
            self::assertTrue(self::refused($pdo, "UPDATE [finance].[invoice_approval_batches] SET [status] = N'Maybe' WHERE [id] = '{$id(50)}'"));
            self::assertTrue(self::refused($pdo, "DELETE FROM [finance].[invoice_invoices] WHERE [id] = '{$id(1)}'"), 'nothing cascades: an invoice in a batch cannot simply be deleted');
            $pdo->exec("INSERT INTO [finance].[invoice_signing_limits] ([id], [d365_position], [po_inventory]) VALUES ('{$id(60)}', N'CFO', 99999999999)");
            self::assertTrue(self::refused($pdo, "INSERT INTO [finance].[invoice_signing_limits] ([id], [d365_position]) VALUES ('{$id(61)}', N'CFO')"));
            self::assertSame(1, self::scalar($pdo, "SELECT COUNT(*) FROM [finance].[invoice_signing_limits] WHERE [po_inventory] = 99999999999 AND [expense_claim] IS NULL"), '"unlimited" fits, and no authority is NULL, not 0');
            $pdo->exec("INSERT INTO [finance].[invoice_companies] ([id], [name]) VALUES ('{$id(70)}', N'TriCab (Australia) Pty Ltd')");
            self::assertTrue(self::refused($pdo, "INSERT INTO [finance].[invoice_companies] ([id], [name]) VALUES ('{$id(71)}', N'TriCab (Australia) Pty Ltd')"));
        } finally {
            $pdo->rollBack();
        }
        self::assertSame(0, self::scalar($pdo, 'SELECT COUNT(*) FROM [finance].[invoice_invoices]'), 'nothing is left behind');
    }
}
