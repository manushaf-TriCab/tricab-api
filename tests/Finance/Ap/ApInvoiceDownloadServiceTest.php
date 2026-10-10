<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Service\ApDownload;

/** `POST /invoices/download` (T6.5 F3, the 64th route) on the in-memory twin: the single file, the zip, the filing names, the refusals. */
final class ApInvoiceDownloadServiceTest extends TestCase
{
    private ApInvoiceWorld $world;

    protected function setUp(): void
    {
        $this->world = new ApInvoiceWorld();
    }

    /**
     * @param class-string<\Throwable> $class
     */
    private static function refused(string $class, string $message, callable $call): void
    {
        try {
            $call();
        } catch (\Throwable $failure) {
            self::assertInstanceOf($class, $failure);
            self::assertSame($message, $failure->getMessage());

            return;
        }
        self::fail('Expected ' . $class . ': ' . $message);
    }

    private function download(mixed $ids): ApDownload
    {
        return $this->world->downloads->download($this->world->actor(), $ids);
    }

    public function test_one_invoice_downloads_as_the_file_with_the_filing_name(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', vendor: 'DHL Express (Australia) Pty Ltd', invoiceDate: '2026-10-02', totalAmount: 162.10, costCentre: '890', attachment: 'scan 1.PDF'), '%PDF invented bytes');

        $download = $this->download(['a']);

        self::assertSame('%PDF invented bytes', $download->bytes);
        self::assertSame('DHL Express (Australia) Pty Ltd - 2026-10-02 - 162.10 - 890.pdf', $download->fileName);
        self::assertSame('application/pdf', $download->contentType);
        self::assertSame(0, $download->withoutFile);
    }

    public function test_several_invoices_download_as_a_zip_with_twins_numbered_and_the_ones_without_a_file_counted(): void
    {
        $same = ['vendor' => 'Smith Freight', 'invoiceDate' => '2026-10-02', 'totalAmount' => 50.0, 'costCentre' => '890', 'attachment' => 'a.pdf'];
        $this->world->stored(ApInvoiceFixtures::invoice('first', ...$same), 'first bytes');
        $this->world->stored(ApInvoiceFixtures::invoice('twin', ...$same), 'twin bytes');
        $this->world->stored(ApInvoiceFixtures::invoice('thirdTwin', ...[...$same, 'vendor' => 'SMITH FREIGHT']), 'third bytes');
        $this->world->stored(ApInvoiceFixtures::invoice('photo', vendor: 'Smith/Jones: "Freight"', invoiceDate: null, totalAmount: null, costCentre: '', attachment: 'photo.JPG'), 'jpeg bytes', 'image/jpeg');
        $this->world->stored(ApInvoiceFixtures::invoice('nofile', attachment: 'x.pdf'));

        $download = $this->download(['first', 'twin', 'nofile', 'photo', 'thirdTwin']);

        self::assertSame('application/zip', $download->contentType);
        self::assertSame('AP invoices 2026-10-09 (4).zip', $download->fileName, 'the date in the app zone and the number of files, not of ids');
        self::assertSame(1, $download->withoutFile);
        self::assertSame(
            [
                'Smith Freight - 2026-10-02 - 50.00 - 890.pdf' => 'first bytes',
                'Smith Freight - 2026-10-02 - 50.00 - 890 (2).pdf' => 'twin bytes',
                'Smith Jones Freight - no date - no total.jpg' => 'jpeg bytes',
                'SMITH FREIGHT - 2026-10-02 - 50.00 - 890 (3).pdf' => 'third bytes',
            ],
            ApInvoiceRulesTest::unzip($download->bytes),
            'names compare without regard to case, so the third is the next twin; the order is the order of the ids',
        );
    }

    public function test_a_single_document_among_many_ids_is_the_file_itself_and_the_rest_are_counted(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', attachment: 'a.pdf', vendor: 'V'), 'bytes');
        $this->world->stored(ApInvoiceFixtures::invoice('b'));
        $this->world->stored(ApInvoiceFixtures::invoice('c'));

        $download = $this->download(['b', 'a', 'c', 'a', 'a']);

        self::assertSame('bytes', $download->bytes);
        self::assertSame(2, $download->withoutFile, 'duplicate ids are dropped, first occurrence kept');
        self::assertStringEndsWith('.pdf', $download->fileName);
    }

    public function test_the_matched_odoo_vendor_replaces_the_vendor_in_the_name(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', vendor: 'What The Invoice Says', odooVendorName: 'Matched Vendor Pty Ltd', invoiceDate: '2026-10-02', totalAmount: 1.0, costCentre: '', attachment: 'a.pdf'), 'x');

        self::assertSame('Matched Vendor Pty Ltd - 2026-10-02 - 1.00.pdf', $this->download(['a'])->fileName);
    }

    public function test_an_unknown_type_is_octet_stream_and_a_binned_invoice_is_not_refused(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', attachment: 'noextension', deletedAt: ApInvoiceFixtures::utc('2026-10-04 00:00:00')), 'bytes');

        $download = $this->download(['a']);

        self::assertSame('application/octet-stream', $download->contentType);
        self::assertStringEndsNotWith('.', $download->fileName);
    }

    public function test_the_refusals_are_422_with_the_sources_words(): void
    {
        foreach ([null, [], 'a', 5, true] as $ids) {
            self::refused(ApInvalid::class, 'Select the invoices to download', fn () => $this->download($ids));
        }
        $many = array_map(static fn (int $n): string => 'id' . $n, range(1, 501));
        self::refused(ApInvalid::class, 'Download up to 500 invoices at a time', fn () => $this->download($many));
        // 501 ids that are only 3 once the duplicates are dropped are fine; and exactly 500 distinct ones are not too many.
        $this->world->stored(ApInvoiceFixtures::invoice('a'));
        self::refused(ApNotFound::class, 'None of the selected invoices has a document to download', fn () => $this->download(array_merge(array_fill(0, 498, 'a'), ['a', 'a', 'a'])));
        self::refused(ApNotFound::class, 'Not found', fn () => $this->download(array_slice($many, 0, 500)));
    }

    public function test_any_missing_or_out_of_scope_id_is_a_404_for_the_whole_request(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('mine', mailbox: 'christine@example.test', attachment: 'a.pdf'), 'bytes');
        $this->world->stored(ApInvoiceFixtures::invoice('hand', mailbox: null, attachment: 'b.pdf'), 'bytes');
        $this->world->stored(ApInvoiceFixtures::invoice('theirs', mailbox: 'ap@example.test', attachment: 'c.pdf'), 'bytes');
        $scoped = ApInvoiceFixtures::actor(['christine@example.test']);

        self::assertSame(2, count(ApInvoiceRulesTest::unzip($this->world->downloads->download($scoped, ['mine', 'hand'])->bytes)), 'a hand capture is always in scope');
        self::refused(ApNotFound::class, 'Not found', fn () => $this->world->downloads->download($scoped, ['mine', 'hand', 'theirs']));
        self::refused(ApNotFound::class, 'Not found', fn () => $this->world->downloads->download($scoped, ['theirs']));
        self::refused(ApNotFound::class, 'Not found', fn () => $this->download(['mine', 'ghost']));
    }

    public function test_none_with_a_document_is_a_404_with_its_own_words(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a'));
        $this->world->stored(ApInvoiceFixtures::invoice('lost', fileId: 'a-file-the-store-lost'));

        self::refused(ApNotFound::class, 'None of the selected invoices has a document to download', fn () => $this->download(['a', 'lost']));
    }

    public function test_the_total_size_is_capped(): void
    {
        $world = new ApInvoiceWorld(null, 20);
        $world->stored(ApInvoiceFixtures::invoice('a', attachment: 'a.pdf'), str_repeat('a', 12));
        $world->stored(ApInvoiceFixtures::invoice('b', attachment: 'b.pdf'), str_repeat('b', 12));

        self::assertSame(12, strlen($world->downloads->download($world->actor(), ['a'])->bytes));
        self::refused(ApInvalid::class, 'The selected documents are too large to download at once; select fewer invoices', fn () => $world->downloads->download($world->actor(), ['a', 'b']));
    }
}
