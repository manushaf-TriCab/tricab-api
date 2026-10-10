<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The production seed for Tri-AP's settings lists (Phase 6, T6.17), read as text: what it may touch, that it only
 * ever inserts, and that the rows it carries are the counts its own guard expects. It is not executed here.
 */
final class FinanceApSettingsProductionScriptTest extends TestCase
{
    private const TABLES = [
        'invoice_companies' => ['@Companies', 7],
        'invoice_cost_centres' => ['@CostCentres', 26],
        'invoice_account_categories' => ['@AccountCategories', 82],
    ];

    private static function script(): string
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/seed-finance-ap-settings-production.sql');
        if ($script === false) {
            throw new RuntimeException('seed-finance-ap-settings-production.sql is missing.');
        }

        return $script;
    }

    public function test_it_is_headed_not_applied_and_is_one_guarded_transaction(): void
    {
        $script = self::script();

        self::assertStringContainsString('STATUS: NOT APPLIED', $script);
        self::assertStringContainsString('SET XACT_ABORT ON;', $script);
        self::assertSame(1, substr_count($script, 'BEGIN TRANSACTION;'));
        self::assertSame(1, substr_count($script, 'COMMIT TRANSACTION;'));
        self::assertStringContainsString('THROW 54251', $script);
        self::assertStringContainsString('THROW 54252', $script);
    }

    public function test_it_only_inserts_and_only_into_the_three_settings_tables(): void
    {
        $script = self::script();

        self::assertDoesNotMatchRegularExpression('/^\s*(UPDATE|DELETE|MERGE|TRUNCATE|DROP|ALTER)\b/mi', $script);
        preg_match_all('/INSERT INTO \[finance\]\.\[(\w+)\]/', $script, $matches);
        self::assertSame(array_keys(self::TABLES), $matches[1]);
    }

    public function test_each_list_carries_the_rows_its_guard_counts_and_inserts_only_what_is_missing(): void
    {
        $script = self::script();

        foreach (self::TABLES as $table => [$variable, $expected]) {
            $start = strpos($script, 'INSERT INTO ' . $variable . ' ');
            $end = strpos($script, 'INSERT INTO [finance].[' . $table . ']');
            self::assertNotFalse($start, $variable);
            self::assertNotFalse($end, $table);
            $values = substr($script, $start, $end - $start);

            self::assertSame($expected, preg_match_all("/^\s+\('[0-9a-f]{32}', /m", $values), $table);
            self::assertMatchesRegularExpression(
                '/FROM ' . preg_quote($variable, '/') . ' v\s+WHERE NOT EXISTS \(SELECT 1 FROM \[finance\]\.\[' . $table . '\] e WHERE /',
                $script,
            );
            self::assertMatchesRegularExpression(
                '/FROM ' . preg_quote($variable, '/') . ' v JOIN \[finance\]\.\[' . $table . '\] e ON [^)]+\) <> ' . $expected . '\b/',
                $script,
            );
        }
    }

    public function test_signing_limits_are_kept_out_of_the_repository(): void
    {
        self::assertStringNotContainsString('@SigningLimits', self::script());
        self::assertStringNotContainsString('INSERT INTO [finance].[invoice_signing_limits]', self::script());
    }

    public function test_the_inactive_duplicate_company_is_not_carried(): void
    {
        self::assertStringNotContainsString("N'TRICAB (AUSTRALIA) PTY LTD'", self::script());
        self::assertStringContainsString("N'TriCab (Australia) Pty Ltd'", self::script());
    }
}
