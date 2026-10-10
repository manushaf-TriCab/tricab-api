<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Service\ApDates;
use TriCab\Core\Finance\Ap\Service\ApDownloadName;
use TriCab\Core\Finance\Ap\Service\ApGrading;
use TriCab\Core\Finance\Ap\Service\ApInvoiceInput;
use TriCab\Core\Finance\Ap\Service\ApInvoicePresenter;
use TriCab\Core\Finance\Ap\Service\ApZipWriter;

/** The register's pure rules: dates, grade, the filing name, the zip and the Python-shaped messages. */
final class ApInvoiceRulesTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function dates(): array
    {
        return [
            'iso' => ['2026-10-01', '2026-10-01'],
            'iso without padding' => ['2026-9-1', '2026-09-01'],
            'iso with a time' => ['2026-10-01T10:20:30Z', '2026-10-01'],
            'iso with a space' => ['2026-10-01 10:20', '2026-10-01'],
            'day first, slashes' => ['01/10/2026', '2026-10-01'],
            'day first, one digit and two-digit year' => ['1/10/26', '2026-10-01'],
            'dots' => ['30.09.2026', '2026-09-30'],
            'dashes' => ['30-09-2026', '2026-09-30'],
            'day month year' => ['3 Aug 2026', '2026-08-03'],
            'full month' => ['3 August 2026', '2026-08-03'],
            'month first' => ['Aug 3 2026', '2026-08-03'],
            'month first with a comma' => ['August 3, 2026', '2026-08-03'],
            'ordinal' => ['3rd August 2026', '2026-08-03'],
            'two-digit written year' => ['3 Aug 26', '2026-08-03'],
            'spaces around' => ['  2026-10-01  ', '2026-10-01'],
            'any case' => ['3 AUGUST 2026', '2026-08-03'],
            'impossible day' => ['31/02/2026', null],
            'month 13' => ['2026-13-01', null],
            'words' => ['Net 30', null],
            'a month that is not one' => ['3 Smarch 2026', null],
            'month first without a year' => ['Aug 3', null],
            'empty' => ['', null],
            'blank' => ['   ', null],
            'null' => [null, null],
            'a number' => [20261001, null],
            'a list' => [['2026-10-01'], null],
        ];
    }

    #[DataProvider('dates')]
    public function test_a_written_date_reads_as_the_source_read_it(mixed $input, ?string $expected): void
    {
        self::assertSame($expected, ApDates::normalize($input));
    }

    public function test_the_query_string_date_is_yyyy_mm_dd_only(): void
    {
        self::assertSame('2026-10-01', ApDates::strict('2026-10-01'));
        foreach (['20261001', '2026-W40-1', '2026-10-1', '2026-02-30', '', '2026-10-01T00:00', ' 2026-10-01'] as $bad) {
            self::assertNull(ApDates::strict($bad), $bad);
        }
    }

    public function test_the_grade_gives_the_confidence_and_the_reasons_in_order(): void
    {
        self::assertSame([0.0, 'Could not read the invoice number, vendor, total; Low extraction confidence (0%)'], ApGrading::grade([]));
        self::assertSame([1.0, null], ApGrading::grade(['invoice_no' => 'A', 'vendor' => 'V', 'total_amount' => 5, 'extraction_confidence' => 2]));
        self::assertSame([0.0, 'Low extraction confidence (0%)'], ApGrading::grade(['invoice_no' => 'A', 'vendor' => 'V', 'total_amount' => 5, 'extraction_confidence' => -3]));
        self::assertSame([0.0, 'Low extraction confidence (0%)'], ApGrading::grade(['invoice_no' => 'A', 'vendor' => 'V', 'total_amount' => 5, 'extraction_confidence' => 'sure']));
        self::assertSame([0.9, null], ApGrading::grade(['invoice_no' => 'A', 'vendor' => 'V', 'total_amount' => 5, 'extraction_confidence' => '0.9']));
        // The classification confidence stands in only when the extraction one is null.
        self::assertSame([0.7, 'Low extraction confidence (70%)'], ApGrading::grade(['invoice_no' => 'A', 'vendor' => 'V', 'total_amount' => 5, 'extraction_confidence' => null, 'classification_confidence' => 0.7]));
        self::assertSame([0.95, null], ApGrading::grade(['invoice_no' => 'A', 'vendor' => 'V', 'total_amount' => 5, 'extraction_confidence' => 0.95, 'classification_confidence' => 0.1]));
        self::assertSame([0.9, 'Could not read the vendor; Amounts do not add up: 1.00 + 1.00 = 2.00, but the total reads 5.00'], ApGrading::grade(['invoice_no' => 'A', 'vendor' => '', 'net_amount' => 1, 'tax_amount' => 1, 'total_amount' => 5, 'extraction_confidence' => 0.9]));
        self::assertSame([0.5, 'Low extraction confidence (50%)'], ApGrading::grade(['invoice_no' => 'A', 'vendor' => 'V', 'total_amount' => 5, 'extraction_confidence' => 0.5], 0.8));
        self::assertSame([0.5, null], ApGrading::grade(['invoice_no' => 'A', 'vendor' => 'V', 'total_amount' => 5, 'extraction_confidence' => 0.5], 0.5), 'a threshold is strict');
    }

    public function test_money_is_formatted_with_thousands_separators_from_cents(): void
    {
        self::assertSame('1,234,567.89', ApGrading::money(123456789));
        self::assertSame('0.05', ApGrading::money(5));
        self::assertSame('-12.30', ApGrading::money(-1230));
        self::assertSame(101, ApGrading::cents(1.005));
        self::assertSame(-101, ApGrading::cents(-1.005));
    }

    public function test_the_filing_name_is_vendor_date_total_and_cost_centre(): void
    {
        $invoice = ApInvoiceFixtures::invoice('a', vendor: 'DHL Express (Australia) Pty Ltd', invoiceDate: '2026-10-02', totalAmount: 162.10, costCentre: '890');

        self::assertSame('DHL Express (Australia) Pty Ltd - 2026-10-02 - 162.10 - 890.pdf', ApDownloadName::forInvoice($invoice, '.pdf'));
        self::assertSame('Smith Jones Freight - no date - no total.jpg', ApDownloadName::forInvoice(ApInvoiceFixtures::invoice('b', vendor: 'Smith/Jones: "Freight"', invoiceDate: null, totalAmount: null, costCentre: ''), ApDownloadName::suffix('photo.JPG')));
        self::assertSame('Unknown vendor - no date - 0.00', ApDownloadName::forInvoice(ApInvoiceFixtures::invoice('c', vendor: '', invoiceDate: null, totalAmount: 0.0), ''));
        self::assertSame('V - 2026-10-02 - -5.00', ApDownloadName::forInvoice(ApInvoiceFixtures::invoice('d', vendor: 'V', totalAmount: -5.0, invoiceDate: '2026-10-02'), ''));
        self::assertSame('Odoo Name - 2026-10-02 - 1234.50', ApDownloadName::forInvoice(ApInvoiceFixtures::invoice('e', vendor: 'What It Said', odooVendorName: 'Odoo Name', totalAmount: 1234.5, invoiceDate: '2026-10-02'), ''), 'no thousands separator; the matched vendor replaces the one read');
    }

    public function test_each_part_is_cleaned_and_the_whole_is_cut_at_180_before_the_suffix(): void
    {
        $messy = ApInvoiceFixtures::invoice('a', vendor: "  ..Acme\t\tand \\ Sons? *.. ", invoiceDate: '2026-10-02', totalAmount: 1.0, costCentre: '"\\/:*?<>|"');
        self::assertSame('Acme and Sons - 2026-10-02 - 1.00.pdf', ApDownloadName::forInvoice($messy, '.pdf'), 'a cost centre that cleans to nothing is dropped');

        $long = ApInvoiceFixtures::invoice('b', vendor: str_repeat('V', 400), invoiceDate: '2026-10-02', totalAmount: 1.0, costCentre: '890');
        $name = ApDownloadName::forInvoice($long, '.pdf');
        self::assertSame(180 + 4, mb_strlen($name));
        self::assertStringEndsWith('V.pdf', $name);
        self::assertStringEndsWith(' (2).pdf', ApDownloadName::forInvoice($long, ' (2).pdf'));
        self::assertSame(180 + 8, mb_strlen(ApDownloadName::forInvoice($long, ' (2).pdf')), 'the cut comes before the counter and the extension');
        self::assertSame(180 + 4, mb_strlen(ApDownloadName::forInvoice(ApInvoiceFixtures::invoice('c', vendor: str_repeat('é', 400)), '.pdf')), 'characters, not bytes');
    }

    public function test_the_suffix_is_the_lower_case_extension_or_nothing(): void
    {
        self::assertSame('.jpg', ApDownloadName::suffix('photo.JPG'));
        self::assertSame('.pdf', ApDownloadName::suffix('dir\\sub/Invoice 5.PDF'));
        self::assertSame('', ApDownloadName::suffix('archive'));
        self::assertSame('', ApDownloadName::suffix('.hidden'));
        self::assertSame('', ApDownloadName::suffix('trailing.'));
        self::assertSame('.gz', ApDownloadName::suffix('a.tar.gz'));
    }

    public function test_the_zip_writer_makes_a_zip_that_reads_back_with_utf8_names(): void
    {
        $writer = new ApZipWriter(strtotime('2026-10-09 12:00:00 UTC'));
        $writer->add('A.pdf', str_repeat('invoice ', 500));
        $writer->add('Café - 2026-10-02 - 1.00.pdf', 'tiny');
        $writer->add('empty.pdf', '');

        $entries = self::unzip($writer->bytes());

        self::assertSame(['A.pdf' => str_repeat('invoice ', 500), 'Café - 2026-10-02 - 1.00.pdf' => 'tiny', 'empty.pdf' => ''], $entries);
        self::assertSame("PK\x05\x06", substr($writer->bytes(), -22, 4));
        self::assertSame(3, unpack('v', substr($writer->bytes(), -12, 2))[1] ?? null, 'the central directory counts three entries');
    }

    public function test_python_shaped_messages_quote_what_the_caller_sent(): void
    {
        self::assertSame("'Net 30'", ApInvoiceInput::repr('Net 30'));
        self::assertSame('"it\'s"', ApInvoiceInput::repr("it's"));
        self::assertSame("'say \"hi\" it\\'s'", ApInvoiceInput::repr('say "hi" it\'s'));
        self::assertSame('None', ApInvoiceInput::repr(null));
        self::assertSame('True', ApInvoiceInput::repr(true));
        self::assertSame('12', ApInvoiceInput::repr(12));
        self::assertSame('1.0', ApInvoiceInput::repr(1.0));
        self::assertSame('1.5', ApInvoiceInput::repr(1.5));
        self::assertSame("'a\\nb'", ApInvoiceInput::repr("a\nb"));
    }

    public function test_the_row_shape_has_the_file_flags_the_name_only_and_nulls_for_empty_text(): void
    {
        $row = ApInvoicePresenter::invoice(ApInvoiceFixtures::invoice('a', sender: '', fileId: 'file-1', senderName: 'Bill Biller', notes: null), '2026-10-09');

        self::assertSame('file-1', $row['file_id']);
        self::assertTrue($row['has_file']);
        self::assertSame('a.pdf', $row['attachment']);
        self::assertArrayNotHasKey('attachment_url', $row);
        self::assertNull($row['sender']);
        self::assertSame('Bill Biller', $row['sender_name']);
        self::assertNull($row['vendor_ref']);
        self::assertNull($row['deleted_by']);
        self::assertSame('2026-10-05T02:00:00.000000+00:00', $row['created_date']);
        self::assertSame(110.0, $row['total_amount']);
        self::assertSame('2026-11-01', $row['due_date']);
        $keys = array_keys($row);
        foreach (['id', 'created_date', 'updated_date', 'email_id', 'email_record_id', 'attachment_id', 'mailbox', 'email_date', 'sender', 'sender_name', 'subject', 'attachment', 'file_id', 'has_file', 'invoice_no', 'vendor', 'vendor_email', 'tax_id', 'invoice_date', 'due_date', 'po_number', 'currency', 'net_amount', 'tax_amount', 'total_amount', 'company', 'account_category', 'cost_centre', 'status', 'notes', 'is_revised', 'duplicate_status', 'duplicate_of_invoice_id', 'duplicate_reason', 'approval_status', 'extraction_confidence', 'review_reason', 'deleted_at', 'deleted_by', 'odoo_vendor_id', 'vendor_ref', 'odoo_vendor_name', 'vendor_match', 'vendor_match_note'] as $key) {
            self::assertContains($key, $keys);
        }
        self::assertSame('Overdue', ApInvoicePresenter::invoice(ApInvoiceFixtures::invoice('b', dueDate: '2026-10-08'), '2026-10-09')['status']);
        self::assertSame(ApInvoice::STATUS_IN_PROGRESS, ApInvoicePresenter::invoice(ApInvoiceFixtures::invoice('c', dueDate: '2026-10-09'), '2026-10-09')['status']);
    }

    /**
     * @return array<string, string>
     */
    public static function unzip(string $zip): array
    {
        $entries = [];
        $position = 0;
        while (substr($zip, $position, 4) === "PK\x03\x04") {
            $raw = unpack('vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', substr($zip, $position + 4, 26));
            self::assertIsArray($raw);
            $header = array_map(static fn (mixed $value): int => is_int($value) ? $value : 0, $raw);
            $name = substr($zip, $position + 30, $header['nameLength']);
            $data = substr($zip, $position + 30 + $header['nameLength'] + $header['extraLength'], $header['compressed']);
            $content = $header['method'] === 8 ? gzinflate($data) : $data;
            self::assertIsString($content);
            self::assertSame($header['crc'], crc32($content), $name . ' checksum');
            self::assertSame($header['size'], strlen($content), $name . ' size');
            self::assertSame(0x0800, $header['flags'] & 0x0800, 'names are flagged UTF-8');
            $entries[$name] = $content;
            $position += 30 + $header['nameLength'] + $header['extraLength'] + $header['compressed'];
        }

        return $entries;
    }
}
