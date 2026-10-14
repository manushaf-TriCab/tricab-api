<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product\Persistence;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Domain\ValueObject\ProductPageContent;

/**
 * Static review of database/scripts/development/alter-product-page-content.sql (+ rollback) against the repository SQL and the value object.
 * The migration itself is NOT executed anywhere by this test.
 */
final class ProductPageContentSqlSchemaTest extends TestCase
{
    private const TABLE = '[product].[ProductPageContentSettings]';

    private static function sql(string $file): string
    {
        $root = dirname(__DIR__, 3);
        $raw = (string) file_get_contents($root . '/database/scripts/development/' . $file);
        $noBlock = (string) preg_replace('~/\*.*?\*/~s', '', $raw);

        return (string) preg_replace('~--[^\n]*~', '', $noBlock);
    }

    public function test_migration_only_creates_the_new_table_guarded_by_an_existence_check(): void
    {
        $sql = self::sql('alter-product-page-content.sql');
        self::assertStringContainsString("IF OBJECT_ID(N'" . self::TABLE . "', N'U') IS NULL", $sql);
        self::assertSame(1, preg_match_all('/\bCREATE\s+TABLE\b/i', $sql), 'exactly one CREATE TABLE');
        self::assertStringContainsString('CREATE TABLE ' . self::TABLE, $sql);
        foreach (['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'MERGE', 'ALTER'] as $verb) {
            self::assertSame(0, preg_match('/\b' . $verb . '\b/i', $sql), "migration must not contain $verb");
        }
        self::assertStringNotContainsString('ProductPageMediaSettings', $sql, 'existing settings table is untouched');
        self::assertStringNotContainsString('[product].[Products] SET', $sql);
        self::assertStringContainsString('BEGIN TRANSACTION', $sql);
        self::assertStringContainsString('ROLLBACK TRANSACTION', $sql);
        self::assertStringContainsString('SET XACT_ABORT ON', $sql);
    }

    public function test_columns_defaults_and_keys_match_the_api(): void
    {
        $sql = self::sql('alter-product-page-content.sql');
        self::assertSame(1, preg_match('/CREATE TABLE \[product\]\.\[ProductPageContentSettings\]\s*\((.*)\);\s*END;/s', $sql, $m));
        $body = $m[1] ?? '';
        preg_match_all('/^\s*\[([A-Za-z]+)\]\s+([A-Z0-9()]+)/m', $body, $cols, PREG_SET_ORDER);
        $columns = [];
        foreach ($cols as $c) { $columns[$c[1]] = $c[2]; }
        self::assertSame(
            ['ProductId' => 'BIGINT', 'ShowProductMarking' => 'BIT', 'CoreColoursNote' => 'NVARCHAR(500)', 'ShowCoreColoursNote' => 'BIT', 'CreatedAtUtc' => 'DATETIME2(7)', 'CreatedByUserId' => 'BIGINT', 'UpdatedAtUtc' => 'DATETIME2(7)', 'UpdatedByUserId' => 'BIGINT'],
            $columns,
        );
        self::assertStringContainsString('NVARCHAR(' . ProductPageContent::NOTE_MAX_LENGTH . ')', $body, 'column length equals the API limit');
        self::assertMatchesRegularExpression('/\[ShowProductMarking\] BIT NOT NULL\s+CONSTRAINT \[DF_product_ProductPageContentSettings_ShowMarking\] DEFAULT \(0\)/', $body, 'marking defaults OFF');
        self::assertMatchesRegularExpression('/\[ShowCoreColoursNote\] BIT NOT NULL\s+CONSTRAINT \[DF_product_ProductPageContentSettings_ShowNote\] DEFAULT \(1\)/', $body);
        self::assertMatchesRegularExpression('/\[CoreColoursNote\] NVARCHAR\(500\) NULL/', $body, 'note is optional');
        self::assertSame(ProductPageContent::defaults()->showProductMarking(), false);
        self::assertStringContainsString('PRIMARY KEY CLUSTERED ([ProductId])', $body);
        self::assertStringContainsString('FOREIGN KEY ([ProductId]) REFERENCES [product].[Products] ([ProductId])', $body);
        self::assertStringContainsString('FOREIGN KEY ([CreatedByUserId]) REFERENCES [identity].[Users] ([UserId])', $body);
        self::assertStringContainsString('FOREIGN KEY ([UpdatedByUserId]) REFERENCES [identity].[Users] ([UserId])', $body);
        self::assertStringContainsString('DEFAULT (SYSUTCDATETIME())', $body);
    }

    public function test_every_column_the_repository_uses_exists_in_the_migration(): void
    {
        $root = dirname(__DIR__, 3);
        $repo = (string) file_get_contents($root . '/src/Product/Persistence/AzureSqlProductPageContentRepository.php');
        preg_match_all('/(?:s|target)\.\[([A-Za-z]+)\]/', $repo, $m);
        $used = array_unique($m[1]);
        preg_match_all('/INSERT\s*\(\s*(.*?)\)\s*VALUES/s', $repo, $ins);
        foreach (explode(',', (string) ($ins[1][0] ?? '')) as $c) { $used[] = trim(trim($c), '[]'); }
        $ddl = self::sql('alter-product-page-content.sql');
        foreach (array_unique(array_filter($used)) as $column) {
            self::assertStringContainsString('[' . $column . ']', $ddl, "column $column used by the repository is missing in the migration");
        }
        self::assertStringContainsString('[product].[ProductPageContentSettings]', $repo);
        self::assertStringNotContainsString('ProductPageMediaSettings', $repo);
    }

    public function test_rollback_drops_only_the_new_table_and_refuses_when_rows_exist(): void
    {
        $sql = self::sql('rollback-product-page-content.sql');
        self::assertSame(1, preg_match_all('/\bDROP\s+TABLE\b/i', $sql));
        self::assertStringContainsString('DROP TABLE ' . self::TABLE, $sql);
        self::assertStringContainsString('DECLARE @AllowDropWithRows BIT = 0', $sql, 'safe by default');
        self::assertStringContainsString('THROW 50001', $sql);
        foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'ALTER', 'MERGE'] as $verb) {
            self::assertSame(0, preg_match('/\b' . $verb . '\b/i', $sql), "rollback must not contain $verb");
        }
        self::assertStringNotContainsString('ProductPageMediaSettings', $sql);
        self::assertStringNotContainsString('[product].[Products]', $sql);
    }
}
