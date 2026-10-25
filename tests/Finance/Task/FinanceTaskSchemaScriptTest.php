<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;

/**
 * `create-finance-task-schema.sql` is written, not applied, to any shared
 * database (T2.2). These checks read the script and need no database. Running it
 * twice against the local database, and the rolled-back inserts that prove each
 * unique index and check, are the T2.2 evidence in `FINANCE-PHASE-2.md`.
 */
final class FinanceTaskSchemaScriptTest extends TestCase
{
    private const TABLES = [
        'task_entities',
        'task_projects',
        'task_statement_areas',
        'task_templates',
        'task_tasks',
        'task_dependencies',
        'task_history',
        'task_member_profiles',
        'task_report_recipients',
        'task_mail_links',
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
        return preg_replace('~/\*.*?\*/~s', '', self::script('create-finance-task-schema.sql')) ?? '';
    }

    public function test_it_creates_exactly_the_ten_tables_each_only_if_missing(): void
    {
        $sql = self::script('create-finance-task-schema.sql');

        preg_match_all('/CREATE TABLE \[finance\]\.\[([a-z_]+)\]/', $sql, $created);
        self::assertSame(self::TABLES, $created[1]);

        foreach (self::TABLES as $table) {
            self::assertStringContainsString("IF OBJECT_ID(N'[finance].[" . $table . "]', N'U') IS NULL", $sql, $table);
        }
    }

    public function test_it_says_it_is_not_applied_and_destroys_nothing(): void
    {
        $sql = self::script('create-finance-task-schema.sql');

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
        self::assertDoesNotMatchRegularExpression('/\bDECIMAL|MONEY|FLOAT\b/i', $code);
        self::assertStringNotContainsString('legacy_id', $code, 'Tri-Task starts empty (question Q3).');
        self::assertStringNotContainsString('task_outbound_messages', $code, 'Replaced by the thin link table (question Q5).');
    }

    public function test_no_foreign_key_leaves_the_finance_schema_and_none_cascades(): void
    {
        $code = self::code();

        self::assertDoesNotMatchRegularExpression('/REFERENCES\s+\[(?!finance\])/i', $code);
        self::assertStringNotContainsString('CASCADE', $code);
        preg_match_all('/REFERENCES \[finance\]\.\[([a-z_]+)\]/', $code, $targets);
        self::assertSame([], array_values(array_diff(array_unique($targets[1]), self::TABLES)));
    }

    public function test_every_person_column_is_a_platform_user_id_and_ids_are_hex(): void
    {
        $code = self::code();

        foreach (['preparer_user_id', 'reviewer_user_id', 'created_by_user_id', 'deleted_by', 'changed_by_user_id', 'user_id'] as $column) {
            self::assertMatchesRegularExpression('/\[' . $column . '\]\s+BIGINT\s/', $code, $column);
            self::assertDoesNotMatchRegularExpression('/\[' . $column . '\]\s+(N?VARCHAR|CHAR)/i', $code, $column);
        }
        self::assertSame(count(self::TABLES), preg_match_all('/\[id\]\s+CHAR\(32\)\s+NOT NULL/', $code));
        self::assertStringContainsString('[changed_by_name]     NVARCHAR(200)', $code);
    }

    public function test_the_uniqueness_the_source_enforced_in_code_is_now_an_index(): void
    {
        $code = self::code();

        self::assertMatchesRegularExpression(
            '/CREATE UNIQUE INDEX \[UX_finance_task_projects_name_live\]\s+ON \[finance\]\.\[task_projects\] \(\[name\]\)\s+WHERE \[deleted_at\] IS NULL;/',
            $code,
        );
        self::assertMatchesRegularExpression('/CREATE UNIQUE INDEX \[UX_finance_task_statement_areas_name\]\s+ON \[finance\]\.\[task_statement_areas\] \(\[name\]\);/', $code);
        self::assertMatchesRegularExpression('/CREATE UNIQUE INDEX \[UX_finance_task_entities_name\]\s+ON \[finance\]\.\[task_entities\] \(\[name\]\);/', $code);
        self::assertMatchesRegularExpression('/CREATE UNIQUE INDEX \[UX_finance_task_member_profiles_user\]\s+ON \[finance\]\.\[task_member_profiles\] \(\[user_id\]\);/', $code);
        self::assertMatchesRegularExpression('/CREATE UNIQUE INDEX \[UX_finance_task_report_recipients_email\]/', $code);
        self::assertStringContainsString('CONSTRAINT [UQ_finance_task_dependencies_pair] UNIQUE ([task_id], [depends_on_task_id])', $code);
    }

    public function test_statuses_categories_and_ranges_are_check_constraints(): void
    {
        $code = self::code();

        self::assertStringContainsString("CHECK ([status] IN (N'in_progress', N'completed'))", $code);
        self::assertStringNotContainsString("N'overdue'", $code, 'Overdue is derived, never stored.');
        self::assertStringContainsString("CHECK ([status] IN (N'planning', N'active', N'completed', N'on_hold'))", $code);
        self::assertStringContainsString("N'balance_sheet', N'income_statement', N'cash_flow', N'equity', N'notes', N'other'", $code);
        self::assertSame(2, substr_count($code, "CHECK ([category] IN (N'routine', N'ad_hoc'))"));
        self::assertStringContainsString('CHECK ([progress] BETWEEN 0 AND 100)', $code);
        self::assertStringContainsString('CHECK ([default_progress] BETWEEN 0 AND 100)', $code);
        self::assertStringContainsString("N'task_email', N'reminder', N'daily_summary'", $code);
        self::assertStringContainsString('CHECK ([task_id] <> [depends_on_task_id])', $code);
    }

    public function test_the_mail_link_keeps_no_body_and_no_key_to_the_task_or_the_ledger(): void
    {
        $code = self::code();
        $start = strpos($code, 'CREATE TABLE [finance].[task_mail_links]');
        self::assertIsInt($start);
        $table = substr($code, $start);

        self::assertStringNotContainsString('[body]', $table);
        self::assertStringNotContainsString('REFERENCES', $table);
        self::assertStringContainsString('[delivery_id]  CHAR(32)      NOT NULL', $table);
        self::assertStringContainsString('[task_id]      CHAR(32)      NULL', $table);
    }

    public function test_it_is_part_of_the_local_rebuild_after_the_shared_schema(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');

        self::assertStringContainsString("'create-finance-task-schema.sql'", $rebuild);
        self::assertLessThan(
            strpos($rebuild, "'create-finance-task-schema.sql'"),
            strpos($rebuild, "'create-finance-access-schema.sql'"),
        );
        self::assertLessThan(
            strpos($rebuild, "'create-finance-task-schema.sql'"),
            strpos($rebuild, "'create-finance-schema.sql'"),
        );
    }

    public function test_the_assignees_script_is_idempotent_additive_and_says_it_must_run_first(): void
    {
        $sql = self::script('alter-finance-task-assignees.sql');
        $code = preg_replace('~/\*.*?\*/~s', '', $sql) ?? '';

        self::assertStringContainsString('NOT APPLIED to any shared database', $sql);
        self::assertStringContainsString('RUN IT BEFORE THE API', $sql);
        self::assertDoesNotMatchRegularExpression('/\b(DROP|TRUNCATE|DELETE|UPDATE)\b/i', $code, 'nothing existing is changed or removed');
        foreach (['task_assignees', 'task_template_assignees'] as $table) {
            self::assertStringContainsString("IF OBJECT_ID(N'[finance].[" . $table . "]', N'U') IS NULL", $code, $table);
        }
        self::assertSame(4, substr_count($code, 'WHERE NOT EXISTS') + substr_count($code, 'AND NOT EXISTS'), 'each backfill skips what is already linked');
        self::assertStringContainsString("CHECK ([role] IN (N'preparer', N'reviewer'))", $code);
        self::assertStringNotContainsString('ON DELETE CASCADE', $code);
        self::assertSame(1, substr_count($code, 'BEGIN TRANSACTION;'));
    }
}
