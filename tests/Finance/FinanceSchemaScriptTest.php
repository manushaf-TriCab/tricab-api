<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance;

use PHPUnit\Framework\TestCase;

/**
 * `create-finance-schema.sql` is written, not applied, to any shared database.
 * These checks read the script; they need no database. Running it twice against
 * the local database is the F1.3 evidence in `FINANCE-PHASE-1.md`.
 */
final class FinanceSchemaScriptTest extends TestCase
{
    private const TABLES = ['mailboxes', 'mailbox_grants', 'mail_deliveries', 'audit_events'];

    private static function script(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/database/scripts/development/' . $name;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_it_creates_exactly_the_four_shared_tables_each_only_if_missing(): void
    {
        $sql = self::script('create-finance-schema.sql');

        preg_match_all('/CREATE TABLE \[finance\]\.\[([a-z_]+)\]/', $sql, $created);
        self::assertSame(self::TABLES, $created[1]);

        foreach (self::TABLES as $table) {
            self::assertStringContainsString("IF OBJECT_ID(N'[finance].[" . $table . "]', N'U') IS NULL", $sql, $table);
        }
    }

    public function test_the_schema_is_created_plainly_and_only_if_missing(): void
    {
        $sql = self::script('create-finance-schema.sql');

        self::assertStringContainsString("IF SCHEMA_ID(N'finance') IS NULL", $sql);
        self::assertStringContainsString("EXEC (N'CREATE SCHEMA [finance];');", $sql);
        // AUTHORIZATION [dbo] is refused for the account that applies scripts to tricab-mel.
        self::assertStringNotContainsString('AUTHORIZATION', preg_replace('~/\*.*?\*/~s', '', $sql) ?? '');
    }

    public function test_it_says_it_is_not_applied_and_destroys_nothing(): void
    {
        $sql = self::script('create-finance-schema.sql');

        self::assertStringContainsString('NOT APPLIED to any shared database', $sql);
        $code = preg_replace('~/\*.*?\*/~s', '', $sql) ?? '';
        // A grant goes with its mailbox, as in the portal: the one DELETE the script may contain.
        $code = str_replace('ON DELETE CASCADE', '', $code);
        self::assertDoesNotMatchRegularExpression('/\\b(DROP|DELETE|TRUNCATE|ALTER)\\b/i', $code);
    }

    public function test_it_follows_the_conventions_and_has_no_key_outside_the_finance_schema(): void
    {
        $code = preg_replace('~/\*.*?\*/~s', '', self::script('create-finance-schema.sql')) ?? '';

        self::assertStringNotContainsString('DATETIMEOFFSET', $code);
        self::assertStringNotContainsString('DATETIME2(7)', $code);
        self::assertDoesNotMatchRegularExpression('/\\bVARCHAR\\(/i', preg_replace('/NVARCHAR\\(/i', '', $code) ?? '');
        self::assertDoesNotMatchRegularExpression('/REFERENCES\\s+\\[(?!finance\\])/i', $code);
    }

    public function test_the_suppressed_status_script_only_replaces_the_one_check_and_only_when_it_is_old(): void
    {
        $sql = self::script('alter-finance-mail-status-suppressed.sql');
        $code = preg_replace('~/\*.*?\*/~s', '', $sql) ?? '';

        self::assertStringContainsString('NOT APPLIED to any shared database', $sql);
        self::assertStringContainsString("NOT LIKE N'%suppressed%'", $code);
        self::assertSame(1, substr_count($code, 'DROP CONSTRAINT [CK_finance_mail_deliveries_status]'));
        self::assertSame(1, preg_match_all('/\bDROP\b/i', $code));
        self::assertDoesNotMatchRegularExpression('/\b(DELETE|TRUNCATE|UPDATE|INSERT)\b/i', $code);
        self::assertStringContainsString("N'pending', N'sent', N'failed', N'suppressed'", self::script('create-finance-schema.sql'));
    }

    public function test_an_idempotency_key_is_unique_per_app(): void
    {
        $sql = self::script('create-finance-schema.sql');

        self::assertMatchesRegularExpression(
            '/CREATE UNIQUE INDEX \\[UX_finance_mail_deliveries_idempotency\\]\\s+ON \\[finance\\]\\.\\[mail_deliveries\\] \\(\\[application_key\\], \\[idempotency_key\\]\\)\\s+WHERE \\[idempotency_key\\] IS NOT NULL;/',
            $sql,
        );
    }

    public function test_it_is_part_of_the_local_rebuild_after_the_rfq_schema(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');

        self::assertStringContainsString("'create-finance-schema.sql'", $rebuild);
        self::assertLessThan(
            strpos($rebuild, "'create-finance-schema.sql'"),
            strpos($rebuild, "'create-rfq-schema.sql'"),
        );
    }
}
