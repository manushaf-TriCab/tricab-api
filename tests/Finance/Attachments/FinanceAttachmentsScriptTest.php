<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Attachments;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Core\Finance\Module;

/**
 * `create-finance-attachments-schema.sql` is written, not applied, to any shared
 * database. These checks read the script and the setting; they need no database
 * (the table itself is exercised by `AzureSqlFinanceAttachmentStoreTest`).
 */
final class FinanceAttachmentsScriptTest extends TestCase
{
    private static function code(): string
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/create-finance-attachments-schema.sql');
        self::assertIsString($sql);

        return $sql;
    }

    public function test_it_says_it_is_not_applied_and_creates_one_table_only_if_missing_and_destroys_nothing(): void
    {
        $sql = self::code();
        $code = preg_replace('~/\*.*?\*/~s', '', $sql) ?? '';

        self::assertStringContainsString('NOT APPLIED to any shared database', $sql);
        preg_match_all('/CREATE TABLE \[finance\]\.\[([a-z_]+)\]/', $code, $created);
        self::assertSame(['attachments'], $created[1]);
        self::assertStringContainsString("IF OBJECT_ID(N'[finance].[attachments]', N'U') IS NULL", $code);
        self::assertDoesNotMatchRegularExpression('/\b(DROP|DELETE|TRUNCATE|ALTER|UPDATE)\b/i', $code);
        self::assertDoesNotMatchRegularExpression('/REFERENCES\s+\[(?!finance\])/i', $code);
        self::assertStringNotContainsString('DATETIME2(7)', $code);
    }

    public function test_the_apps_the_table_allows_are_the_five_the_module_defines(): void
    {
        $code = self::code();
        preg_match("/CK_finance_attachments_application_key\\]\\s+CHECK \\(\\[application_key\\] IN \\(([^)]*)\\)\\)/", $code, $match);
        self::assertArrayHasKey(1, $match);
        preg_match_all("/N'([A-Z0-9]+)'/", $match[1] ?? '', $keys);

        self::assertSame(array_keys(Module::APPS), $keys[1]);
    }

    public function test_the_container_setting_defaults_to_a_private_finance_container_and_can_be_named(): void
    {
        self::assertSame('finance-attachments', Settings::fromEnvironment([])->blobContainerFinance);
        self::assertSame('fin-files', Settings::fromEnvironment(['BLOB_CONTAINER_FINANCE' => 'fin-files'])->blobContainerFinance);
    }
}
