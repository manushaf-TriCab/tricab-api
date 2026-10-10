<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The follow-up alter script for Tri-AP's tables (10 Oct 2026), read as text beside the schema script it catches an
 * older database up with: both must describe the same check and the same column. It is not executed here.
 */
final class FinanceApSchemaFollowupsScriptTest extends TestCase
{
    private static function script(string $file): string
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/' . $file);
        if ($script === false) {
            throw new RuntimeException($file . ' is missing.');
        }

        return $script;
    }

    public function test_it_is_headed_not_applied_guarded_and_changes_no_data(): void
    {
        $script = self::script('alter-finance-invoice-followups.sql');

        self::assertStringContainsString('STATUS: NOT APPLIED', $script);
        self::assertStringContainsString('SET XACT_ABORT ON;', $script);
        self::assertStringContainsString('THROW 54261', $script);
        self::assertStringContainsString('THROW 54262', $script);
        self::assertDoesNotMatchRegularExpression('/^\s*(INSERT|UPDATE|DELETE|MERGE|TRUNCATE|DROP)\b/mi', $script);
    }

    public function test_each_change_is_made_only_where_it_is_missing(): void
    {
        $script = self::script('alter-finance-invoice-followups.sql');

        self::assertMatchesRegularExpression('/IF NOT EXISTS \(\s*SELECT 1 FROM sys\.check_constraints\s+WHERE \[name\] = N\'CK_finance_invoice_approval_decisions_decision\'/', $script);
        self::assertMatchesRegularExpression('/AND \[name\] = N\'attachment\'\s+AND \[max_length\] < 800\)/', $script);
    }

    public function test_the_schema_script_creates_new_tables_the_same_way(): void
    {
        $check = "CONSTRAINT [CK_finance_invoice_approval_decisions_decision]";
        $values = "CHECK ([decision] IN (N'Approved', N'Rejected'))";

        foreach (['alter-finance-invoice-followups.sql', 'create-finance-invoice-schema.sql'] as $file) {
            $script = self::script($file);
            self::assertStringContainsString($check, $script, $file);
            self::assertStringContainsString($values, $script, $file);
            self::assertMatchesRegularExpression('/\[attachment\]\s+NVARCHAR\(400\)\s+NOT NULL/', $script, $file);
        }
    }
}
