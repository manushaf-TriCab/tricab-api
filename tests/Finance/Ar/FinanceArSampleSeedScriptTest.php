<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Finance\Ar\ArSampleFiles;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;

/**
 * The Tri-AR sample seed (T5.12) read as text, and the invented sample PDFs it names: local only, invented only,
 * idempotent by construction, whole, and the files' ids, sizes and hashes in the script equal what `ArSampleFiles` makes.
 * The seed itself is run against a real local SQL Server in `AzureSqlArSampleSeedTest`.
 */
final class FinanceArSampleSeedScriptTest extends TestCase
{
    private static function sql(): string
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/seed-finance-ar-sample-local.sql');
        self::assertIsString($sql);

        return $sql;
    }

    public function test_it_is_headed_local_only_and_refuses_azure_before_the_first_write(): void
    {
        $sql = self::sql();

        self::assertStringContainsString('LOCAL ONLY. NOT FOR ANY SHARED DATABASE', $sql);
        $guard = (int) strpos($sql, "EngineEdition') AS INT) NOT IN (2, 3, 4)");
        self::assertGreaterThan(0, $guard);
        self::assertStringContainsString("@@SERVERNAME LIKE N'%database.windows.net%'", $sql);
        self::assertStringContainsString("@@SERVERNAME LIKE N'%tricab-mel%'", $sql);
        self::assertStringContainsString('THROW 54130', $sql);
        self::assertLessThan((int) strpos($sql, 'BEGIN TRY'), $guard, 'the guard runs before the transaction opens');
        $firstWrite = (int) strpos($sql, 'INSERT INTO');
        self::assertLessThan($firstWrite, $guard);
    }

    public function test_every_person_and_address_is_invented(): void
    {
        $sql = self::sql();

        preg_match_all('/[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+/', $sql, $matches);
        foreach (array_unique($matches[0]) as $address) {
            self::assertMatchesRegularExpression('/(@sample\.local|\.example\.test|@tricab\.com)$/', $address, $address . ' is not an invented address');
            if (str_ends_with($address, '@tricab.com')) {
                self::assertSame('ar@tricab.com', $address, 'the only real-looking address is the mailbox the register already names');
            }
        }
        self::assertStringContainsString('(This message is invented sample data.)', $sql);
    }

    public function test_it_is_idempotent_by_construction(): void
    {
        $sql = self::sql();

        foreach (['DELETE ', 'DROP ', 'TRUNCATE ', 'ALTER ', 'UPDATE '] as $verb) {
            self::assertStringNotContainsString($verb, $sql, 'the seed never ' . trim($verb) . 's anything');
        }
        $inserts = substr_count($sql, 'INSERT INTO [');
        $guards = substr_count($sql, 'NOT EXISTS');
        self::assertGreaterThanOrEqual(10, $inserts);
        self::assertSame($inserts, $guards, 'every insert into a real table is guarded by a NOT EXISTS');
    }

    public function test_no_literal_calendar_date_so_overdue_cannot_go_stale(): void
    {
        self::assertSame(0, preg_match('/\b20\d\d-\d\d-\d\d\b/', preg_replace('~/\*.*?\*/~s', '', self::sql()) ?? ''), 'dates are relative to the run');
    }

    public function test_the_dataset_is_whole_and_the_script_checks_it(): void
    {
        $sql = self::sql();

        foreach (['<> 15', '<> 1', '<> 5', '<> 9', '<> 2', '<> 3'] as $count) {
            self::assertStringContainsString($count, $sql);
        }
        self::assertStringContainsString('THROW 54133', $sql);
        self::assertSame(16, preg_match_all("/\\('5a0e[0-9a-f]{28}', \\d+, N'/", $sql), 'sixteen records');
        self::assertSame(3, preg_match_all("/'5a13[0-9a-f]{28}', (?:NULL|'5a0e)/", $sql), 'three credit note requests');
        self::assertSame(6, substr_count($sql, 'sample-token-0'), 'three sample tokens, each hashed into the table and printed once for a local form');
    }

    public function test_only_the_hash_of_each_sample_token_is_stored(): void
    {
        $sql = self::sql();

        self::assertStringContainsString("HASHBYTES('SHA2_256', v.[token])", $sql);
        self::assertStringNotContainsString('[token]) VALUES', $sql);
        self::assertSame(1, substr_count($sql, 'INSERT INTO [finance].[ar_credit_note_requests]'));
        self::assertStringContainsString('[token_hash]', $sql);
    }

    public function test_the_files_named_in_the_script_are_exactly_what_the_sample_files_class_makes(): void
    {
        $sql = self::sql();
        $files = ArSampleFiles::all();

        self::assertCount(5, $files);
        foreach ($files as $id => $file) {
            self::assertStringContainsString("('" . $id . "', '5a0e", $sql, $id . ' is in the script');
            self::assertStringContainsString("N'ar/sample/" . $id . "'", $sql);
            self::assertStringContainsString(", " . strlen($file['bytes']) . ", '" . $file['sha256'] . "')", $sql, $file['name'] . ': size and SHA-256 equal the class');
            self::assertStringContainsString(self::sqlQuote($file['name']), $sql);
            self::assertSame(ArSampleFiles::blobPath($id), 'ar/sample/' . $id);
        }
    }

    private static function sqlQuote(string $text): string
    {
        return "N'" . str_replace("'", "''", $text) . "'";
    }

    public function test_the_sample_pdfs_are_valid_small_invented_documents(): void
    {
        foreach (ArSampleFiles::all() as $file) {
            self::assertStringStartsWith('%PDF-1.4', $file['bytes']);
            self::assertStringEndsWith("%%EOF\n", $file['bytes']);
            self::assertLessThan(2000, strlen($file['bytes']));
            self::assertStringContainsString('sample', $file['bytes'], $file['name'] . ' says it is a sample');
            self::assertSame(hash('sha256', $file['bytes']), $file['sha256']);
            // The xref offsets point at the objects they name.
            self::assertSame(1, preg_match('/startxref\n(\d+)\n/', $file['bytes'], $m));
            self::assertStringStartsWith('xref', substr($file['bytes'], (int) ($m[1] ?? 0), 4));
        }
        self::assertSame(ArSampleFiles::all(), ArSampleFiles::all(), 'deterministic: the same bytes every time');
    }

    public function test_text_in_a_pdf_line_is_escaped(): void
    {
        $pdf = ArSampleFiles::pdf(['A (b) \\ c']);

        self::assertStringContainsString('(A \\(b\\) \\\\ c) Tj', $pdf);
    }

    public function test_loading_puts_every_file_into_the_blob_store_under_the_seeded_path(): void
    {
        $blobs = new InMemoryProductBlobStore('', 'finance-attachments');

        ArSampleFiles::load($blobs, 'finance-attachments');

        foreach (ArSampleFiles::all() as $file) {
            self::assertSame($file['bytes'], $blobs->getObjectBinary('finance-attachments', $file['blobPath']));
        }
    }

    public function test_the_sample_files_setting_is_refused_in_production_and_with_a_real_blob_store(): void
    {
        $base = ['APP_ENV' => 'development', 'DEV_AUTH_ENABLED' => 'true', 'DEV_AUTH_DEFAULT_TENANT' => 't', 'DEV_AUTH_DEFAULT_SUBJECT' => 's', 'DEV_AUTH_DEFAULT_EMAIL' => 'a@example.test', 'USER_REPOSITORY' => 'memory', 'DB_AUTH_MODE' => 'sql-login'];
        $on = ['FINANCE_AR_SAMPLE_FILES' => 'true'];

        self::assertTrue(Settings::fromEnvironment($base + $on + ['BLOB_AUTH_MODE' => 'memory'])->financeArSampleFiles);
        self::assertTrue(Settings::fromEnvironment($base + $on)->financeArSampleFiles, 'blob mode disabled is the memory store outside production');
        self::assertFalse(Settings::fromEnvironment($base)->financeArSampleFiles, 'off by default');
        try {
            Settings::fromEnvironment($base + $on + ['BLOB_AUTH_MODE' => 'connection-string', 'BLOB_ACCOUNT_NAME' => 'devaccount', 'BLOB_ACCOUNT_KEY' => 'ZGV2a2V5', 'BLOB_ENDPOINT' => 'https://devaccount.blob.example.test']);
            self::fail('Expected the sample files to be refused with a real blob store.');
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString('FINANCE_AR_SAMPLE_FILES', $refused->getMessage());
        }
        // In production the app does not start with the in-memory settings used here at all, and the sample files must not be what lets it.
        $this->expectException(InvalidArgumentException::class);
        Settings::fromEnvironment(['APP_ENV' => 'production'] + $on + $base);
    }
}
