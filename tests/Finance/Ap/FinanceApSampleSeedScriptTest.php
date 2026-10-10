<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Config\Settings;
use TriCab\Api\Finance\Ap\ApSampleFiles;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;

/**
 * The Tri-AP sample seed (T6.12) read as text, and the invented sample documents it names: local only, invented only,
 * idempotent by construction, whole, in the rebuild after what it depends on, and the files' ids, sizes and hashes in the
 * script equal what `ApSampleFiles` makes. The seed itself is run against a real local SQL Server in `AzureSqlApSampleSeedTest`.
 */
final class FinanceApSampleSeedScriptTest extends TestCase
{
    private static function sql(): string
    {
        $sql = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/seed-finance-ap-sample-local.sql');
        self::assertIsString($sql);

        return $sql;
    }

    private static function file(string $relative): string
    {
        $text = file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
        self::assertIsString($text);

        return $text;
    }

    public function test_it_is_headed_local_only_and_refuses_azure_before_the_first_write(): void
    {
        $sql = self::sql();

        self::assertStringContainsString('LOCAL ONLY. NOT FOR ANY SHARED DATABASE', $sql);
        self::assertStringContainsString('INVENTED data for a developer\'s own database', $sql);
        $guard = (int) strpos($sql, "EngineEdition') AS INT) NOT IN (2, 3, 4)");
        self::assertGreaterThan(0, $guard);
        self::assertStringContainsString("@@SERVERNAME LIKE N'%database.windows.net%'", $sql);
        self::assertStringContainsString("@@SERVERNAME LIKE N'%tricab-mel%'", $sql);
        self::assertStringContainsString('THROW 54240', $sql);
        self::assertStringContainsString('local Docker database only. Refusing to run against Azure SQL', $sql);
        self::assertLessThan((int) strpos($sql, 'BEGIN TRY'), $guard, 'the guard runs before the transaction opens');
        self::assertLessThan((int) strpos($sql, 'INSERT INTO'), $guard);
        self::assertSame(1, substr_count($sql, 'BEGIN TRANSACTION;'), 'one transaction');
        self::assertSame(1, substr_count($sql, 'COMMIT TRANSACTION;'));
    }

    public function test_every_person_and_address_is_invented(): void
    {
        $sql = self::sql();

        preg_match_all('/[A-Za-z0-9._+-]+@[A-Za-z0-9.-]+/', $sql, $matches);
        $addresses = array_unique($matches[0]);
        self::assertNotEmpty($addresses);
        foreach ($addresses as $address) {
            self::assertMatchesRegularExpression('/(@sample\.local|\.example\.test|@tricab\.com)$/', $address, $address . ' is not an invented address');
            if (str_ends_with($address, '@tricab.com')) {
                self::assertSame('ap@tricab.com', $address, 'the only real-looking address is the mailbox the registry already names');
            }
        }
        self::assertDoesNotMatchRegularExpression('~https?://(?!mail\.example\.test/)~', $sql, 'the only address of a page is a made-up one');
    }

    public function test_it_is_idempotent_by_construction(): void
    {
        $sql = self::sql();

        foreach (['DELETE ', 'DROP ', 'TRUNCATE ', 'ALTER ', 'UPDATE '] as $verb) {
            self::assertStringNotContainsString($verb, $sql, 'the seed never ' . trim($verb) . 's anything');
        }
        $inserts = substr_count($sql, 'INSERT INTO [');
        $guards = substr_count($sql, 'NOT EXISTS');
        self::assertGreaterThanOrEqual(20, $inserts);
        self::assertSame($inserts, $guards, 'every insert into a real table is guarded by a NOT EXISTS');
    }

    public function test_no_literal_calendar_date_so_overdue_cannot_go_stale(): void
    {
        self::assertSame(0, preg_match('/\b20\d\d-\d\d-\d\d\b/', preg_replace('~/\*.*?\*/~s', '', self::sql()) ?? ''), 'dates are relative to the run');
        self::assertStringContainsString('@Today', self::sql());
        self::assertStringContainsString('AUS Eastern Standard Time', self::sql());
    }

    public function test_every_id_is_32_lower_case_hex_in_the_seeds_own_families(): void
    {
        $sql = self::sql();

        preg_match_all("/'([0-9A-Za-z]{32})'/", $sql, $matches);
        self::assertGreaterThan(200, count($matches[1]));
        foreach (array_unique($matches[1]) as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
            self::assertMatchesRegularExpression('/^(a9[0-9a-f]{2}|0c1[ab])0*[0-9]+$/', $id, $id . ' is in one of the seed\'s id families');
        }
    }

    public function test_the_dataset_is_whole_and_the_script_checks_it(): void
    {
        $sql = self::sql();

        self::assertStringContainsString('THROW 54243', $sql);
        foreach (['<> 28', '<> 4', '<> 26', '<> 24', '<> 22', '<> 3', '<> 8', '<> 2', '<> 5', '<> 10', '<> 1', '<> 13'] as $count) {
            self::assertStringContainsString($count, $sql);
        }
        self::assertSame(32, preg_match_all("/\\('a901[0-9a-f]{28}', (?:N'AAMkSAMPLEmsg\\d{4}'|NULL), /", $sql), 'thirty-two invoices');
        self::assertSame(26, preg_match_all("/\\('a902[0-9a-f]{28}', N'AAMkSAMPLEmsg\\d{4}', N'/", $sql), 'twenty-six emails');
        self::assertSame(3, preg_match_all("/\\('a906[0-9a-f]{28}', \\(SELECT/", $sql), 'three approval batches');
        self::assertSame(5, preg_match_all("/\\('a909[0-9a-f]{28}', /", $sql), 'five sent-mail links');
        foreach (['Needs Review', 'In Progress', 'Completed', 'Exported', 'Clear', 'Potential', 'Cleared by user', 'Pending approval', 'Rejected'] as $word) {
            self::assertStringContainsString("N'" . $word . "'", $sql);
        }
        foreach (['matched', 'confirmed', 'unmatched', 'new'] as $match) {
            self::assertStringContainsString("N'" . $match . "'", $sql);
        }
        self::assertStringContainsString('Possible duplicate: same vendor, invoice number, currency, and total', $sql);
        self::assertStringContainsString('Low extraction confidence (62%); Amounts do not add up: 4,300.00 + 430.00 = 4,730.00, but the total reads 5,000.00', $sql);
        self::assertStringContainsString('Could not read the vendor, total; Low extraction confidence (41%)', $sql);
        self::assertStringContainsString('99999999999.00', $sql);
        self::assertStringContainsString("N'succeeded'", $sql);
        self::assertStringNotContainsString("N'Running'", $sql);
        self::assertStringNotContainsString("N'Listing'", $sql, 'a scan run left open would block scanning');
    }

    public function test_the_people_cover_the_four_roles_and_one_is_scoped_to_a_single_mailbox(): void
    {
        $sql = self::sql();

        foreach (['FINANCE_AP_ADMIN', 'FINANCE_AP_OPERATOR', 'FINANCE_AP_PREPARER', 'FINANCE_AP_APPROVER'] as $role) {
            self::assertStringContainsString("N'" . $role . "'", $sql);
        }
        self::assertStringContainsString('THROW 54242', $sql);
        self::assertStringContainsString("N'mailbox', N'ap.projects@sample.local'", $sql);
        self::assertSame(1, substr_count($sql, 'INSERT INTO [finance].[access_scopes]'));
        self::assertStringContainsString("(N'noah',  N'noah.hale@sample.local'", $sql);
    }

    public function test_the_files_named_in_the_script_are_exactly_what_the_sample_files_class_makes(): void
    {
        $sql = self::sql();
        $files = ApSampleFiles::all();

        self::assertCount(22, $files);
        foreach ($files as $id => $file) {
            $invoiceId = 'a901' . str_pad((string) $file['number'], 28, '0', STR_PAD_LEFT);
            self::assertSame($id, 'a904' . str_pad((string) $file['number'], 28, '0', STR_PAD_LEFT));
            self::assertStringContainsString("('" . $id . "', N'AP', N'invoice', '" . $invoiceId . "', N'ap/sample/" . $id . "', " . self::sqlQuote($file['name']) . ', N\'' . $file['contentType'] . "', " . strlen($file['bytes']) . ", '" . $file['sha256'] . "'", $sql, $file['name'] . ': the file row equals the class');
            self::assertGreaterThanOrEqual(2, substr_count($sql, "'" . $file['sha256'] . "'"), $file['name'] . ': the invoice carries the same hash as the file');
            self::assertSame(ApSampleFiles::blobPath($id), 'ap/sample/' . $id);
            self::assertSame(ApSampleFiles::fileId($file['number']), $id);
        }
    }

    private static function n(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }

    private static function sqlQuote(string $text): string
    {
        return "N'" . str_replace("'", "''", $text) . "'";
    }

    public function test_the_rebuild_does_not_run_it(): void
    {
        // Opt-in, as Tri-AR's and Tri-Task's sample seeds are: a rebuild gives an empty Tri-AP, and a developer runs the
        // seed by hand when they want the sample.
        self::assertStringNotContainsString('seed-finance-ap-sample-local.sql', self::file('database/scripts/development/rebuild-local-db.ps1'));
    }

    public function test_the_sample_documents_are_valid_small_invented_files(): void
    {
        $kinds = ['application/pdf' => 0, 'image/png' => 0];
        $hashes = [];
        foreach (ApSampleFiles::all() as $file) {
            $kinds[$file['contentType']] = ($kinds[$file['contentType']] ?? 0) + 1;
            $hashes[] = $file['sha256'];
            self::assertSame(hash('sha256', $file['bytes']), $file['sha256']);
            self::assertNotSame('', $file['name']);
            if ($file['contentType'] === 'application/pdf') {
                self::assertStringStartsWith('%PDF-1.4', $file['bytes']);
                self::assertStringEndsWith("%%EOF\n", $file['bytes']);
                self::assertLessThan(2000, strlen($file['bytes']));
                self::assertStringContainsString('sample', $file['bytes'], $file['name'] . ' says it is a sample');
                // The xref offsets point at the objects they name.
                self::assertSame(1, preg_match('/startxref\n(\d+)\n/', $file['bytes'], $m));
                self::assertStringStartsWith('xref', substr($file['bytes'], (int) ($m[1] ?? 0), 4));
            }
        }
        self::assertSame(['application/pdf' => 20, 'image/png' => 2], $kinds);
        self::assertSame(count($hashes), count(array_unique($hashes)), 'no two documents are byte for byte the same file: that would be an exact duplicate');
        self::assertSame(ApSampleFiles::all(), ApSampleFiles::all(), 'deterministic: the same bytes every time');
    }

    public function test_the_png_is_a_well_formed_image_whose_chunks_check_out(): void
    {
        $png = ApSampleFiles::png();

        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $offset = 8;
        $types = [];
        $idat = '';
        while ($offset < strlen($png)) {
            $unpacked = unpack('Nlength', substr($png, $offset, 4));
            self::assertIsArray($unpacked);
            $length = self::n($unpacked['length']);
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);
            $crc = unpack('Ncrc', substr($png, $offset + 8 + $length, 4));
            self::assertIsArray($crc);
            self::assertSame(crc32($type . $data), self::n($crc['crc']), $type . ' has the right CRC');
            $types[] = $type;
            if ($type === 'IDAT') {
                $idat .= $data;
            }
            if ($type === 'IHDR') {
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolour', $data);
                self::assertIsArray($header);
                self::assertSame([200, 130, 8, 3], [self::n($header['width']), self::n($header['height']), self::n($header['depth']), self::n($header['colour'])]);
            }
            $offset += 12 + $length;
        }
        self::assertSame(['IHDR', 'PLTE', 'IDAT', 'IEND'], $types);
        $pixels = gzuncompress($idat);
        self::assertIsString($pixels);
        self::assertSame(130 * (1 + 200), strlen($pixels), 'one filter byte and 200 pixels for each of 130 rows');
        self::assertNotSame(ApSampleFiles::png(1), $png, 'the seed varies the drawing');
    }

    public function test_each_pdf_says_what_the_invoice_it_belongs_to_says(): void
    {
        foreach (ApSampleFiles::all() as $file) {
            $facts = ApSampleFiles::facts($file['number']);
            self::assertNotNull($facts);
            if ($file['contentType'] !== 'application/pdf') {
                continue;
            }
            // The poor scan (invoice 13) is the one document that says nothing legible.
            if ($facts['number'] !== '') {
                self::assertStringContainsString(strtoupper($facts['vendor']), $file['bytes']);
                self::assertStringContainsString('Invoice number: ' . $facts['number'], $file['bytes']);
                self::assertStringContainsString($facts['currency'] . ' ' . $facts['total'], $file['bytes']);
            }
        }
        self::assertNull(ApSampleFiles::facts(11), 'invoice 11 has no document');
    }

    public function test_text_in_a_pdf_line_is_escaped(): void
    {
        $pdf = ApSampleFiles::pdf(['A (b) \\ c']);

        self::assertStringContainsString('(A \\(b\\) \\\\ c) Tj', $pdf);
    }

    public function test_loading_puts_every_file_into_the_blob_store_under_the_seeded_path(): void
    {
        $blobs = new InMemoryProductBlobStore('', 'finance-attachments');

        ApSampleFiles::load($blobs, 'finance-attachments');

        foreach (ApSampleFiles::all() as $file) {
            self::assertSame($file['bytes'], $blobs->getObjectBinary('finance-attachments', $file['blobPath']));
        }
    }

    public function test_the_sample_files_setting_is_declared_documented_wired_and_off_by_default(): void
    {
        self::assertStringContainsString("FINANCE_AP_SAMPLE_FILES=false\n", str_replace("\r\n", "\n", self::file('.env.example')));
        self::assertStringContainsString('ApSampleFiles::load($blobs, $settings->blobContainerFinance)', self::file('src/Bootstrap/ContainerFactory.php'));
        self::assertStringContainsString('if ($settings->financeApSampleFiles)', self::file('src/Bootstrap/ContainerFactory.php'));
    }

    public function test_the_sample_files_setting_is_refused_in_production_and_with_a_real_blob_store(): void
    {
        $base = ['APP_ENV' => 'development', 'DEV_AUTH_ENABLED' => 'true', 'DEV_AUTH_DEFAULT_TENANT' => 't', 'DEV_AUTH_DEFAULT_SUBJECT' => 's', 'DEV_AUTH_DEFAULT_EMAIL' => 'a@example.test', 'USER_REPOSITORY' => 'memory', 'DB_AUTH_MODE' => 'sql-login'];
        $on = ['FINANCE_AP_SAMPLE_FILES' => 'true'];

        self::assertTrue(Settings::fromEnvironment($base + $on + ['BLOB_AUTH_MODE' => 'memory'])->financeApSampleFiles);
        self::assertTrue(Settings::fromEnvironment($base + $on)->financeApSampleFiles, 'blob mode disabled is the memory store outside production');
        self::assertFalse(Settings::fromEnvironment($base)->financeApSampleFiles, 'off by default');
        self::assertFalse(Settings::fromEnvironment($base + $on)->financeArSampleFiles, 'the two switches are separate');
        try {
            Settings::fromEnvironment($base + $on + ['BLOB_AUTH_MODE' => 'connection-string', 'BLOB_ACCOUNT_NAME' => 'devaccount', 'BLOB_ACCOUNT_KEY' => 'ZGV2a2V5', 'BLOB_ENDPOINT' => 'https://devaccount.blob.example.test']);
            self::fail('Expected the sample files to be refused with a real blob store.');
        } catch (InvalidArgumentException $refused) {
            self::assertStringContainsString('FINANCE_AP_SAMPLE_FILES', $refused->getMessage());
        }
        // In production the app does not start with the in-memory settings used here at all, and the sample files must not be what lets it.
        $this->expectException(InvalidArgumentException::class);
        Settings::fromEnvironment(['APP_ENV' => 'production'] + $on + $base);
    }
}
