<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Core\Finance\Ap\Domain\ApInvalid;
use TriCab\Core\Finance\Ap\Domain\ApNotFound;
use TriCab\Core\Finance\Ap\Service\ApAttachmentService;
use TriCab\Core\Finance\Module;

/** Uploads and an invoice's document (T6.5): the extension rule, the hash, the guard, and what the file route serves. */
final class ApAttachmentServiceTest extends TestCase
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

    public function test_an_upload_is_kept_unattached_with_its_hash_and_answers_its_id_and_name(): void
    {
        $answer = $this->world->attachments->upload($this->world->actor(), 'Invoice 77.PDF', '%PDF-1.4 bytes');

        self::assertSame(['upload_id', 'name'], array_keys($answer));
        self::assertSame('Invoice 77.PDF', $answer['name']);
        $row = $this->world->fileStore->find(Module::APP_AP, $answer['upload_id']);
        self::assertNotNull($row);
        self::assertFalse($row->isAttached());
        self::assertSame(hash('sha256', '%PDF-1.4 bytes'), $row->sha256);
        self::assertSame(7, $row->uploadedByUserId);
        self::assertSame('application/pdf', $row->contentType, 'the type comes from the extension, not from the client');
    }

    public function test_only_pdf_png_jpg_jpeg_tif_and_tiff_are_accepted_by_the_name(): void
    {
        foreach (['a.pdf', 'a.PNG', 'a.jpg', 'a.JPEG', 'a.tif', 'a.TIFF', 'dir\\a.pdf', 'a.b.pdf'] as $name) {
            self::assertSame($name, $this->world->attachments->upload($this->world->actor(), $name, 'x')['name'], $name);
        }
        foreach (['a.exe', 'a.docx', 'a', 'a.', '.pdf', 'a.pdf.exe', 'report.txt', 'a.gif'] as $name) {
            self::refused(ApInvalid::class, 'Unsupported file type: ' . $name, fn () => $this->world->attachments->upload($this->world->actor(), $name, 'x'));
        }
    }

    public function test_no_file_a_blank_name_or_an_empty_file_is_refused(): void
    {
        self::refused(ApInvalid::class, 'No file provided', fn () => $this->world->attachments->upload($this->world->actor(), null, ''));
        self::refused(ApInvalid::class, 'No file provided', fn () => $this->world->attachments->upload($this->world->actor(), '  ', 'x'));
        self::refused(ApInvalid::class, 'The file is empty.', fn () => $this->world->attachments->upload($this->world->actor(), 'a.pdf', ''));
        self::refused(ApInvalid::class, 'The file is larger than 32 MB.', fn () => $this->world->attachments->upload($this->world->actor(), 'a.pdf', str_repeat('x', 33554433)));
    }

    public function test_a_non_ascii_name_keeps_its_extension(): void
    {
        // The source stored 发票.pdf as "<hex>-pdf" with no extension and extraction then failed (R12).
        $answer = $this->world->attachments->upload($this->world->actor(), '发票 Invoice.pdf', '%PDF');

        self::assertSame('发票 Invoice.pdf', $answer['name']);
        self::assertSame('application/pdf', $this->world->fileStore->find(Module::APP_AP, $answer['upload_id'])?->contentType);
        self::assertSame('发票.PDF', $this->world->attachments->upload($this->world->actor(), '发票.PDF', '%PDF')['name']);
    }

    public function test_the_file_route_serves_the_document_through_the_guard_with_the_invoices_own_name(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a', attachment: 'Smith Invoice.pdf'), '%PDF-1.4 invented');
        $invoice = $this->world->invoices->find('a');
        self::assertNotNull($invoice);

        $file = $this->world->attachments->file($this->world->actor(), $invoice);

        self::assertSame('%PDF-1.4 invented', $file->bytes);
        self::assertSame('application/pdf', $file->contentType);
        self::assertSame('Smith Invoice.pdf', $file->downloadName);
        self::assertTrue($file->inlineAllowed);
    }

    public function test_the_file_name_is_never_a_path(): void
    {
        self::assertSame('x.pdf', ApAttachmentService::headerName('..\\..\\etc/x.pdf', 'fallback'));
        self::assertSame('a_b_c.pdf', ApAttachmentService::headerName('a"b<c.pdf', 'fallback'));
        self::assertSame('fallback', ApAttachmentService::headerName('', 'fallback'));
        self::assertSame('fallback', ApAttachmentService::headerName('..', 'fallback'));
        self::assertSame('fallback', ApAttachmentService::headerName('a/', 'fallback'));
    }

    public function test_a_tiff_is_never_shown_in_place_and_a_png_and_jpeg_may_be(): void
    {
        foreach (['image/tiff' => false, 'image/png' => true, 'image/jpeg' => true, 'application/pdf' => true, 'text/html' => false] as $type => $inline) {
            $this->world->stored(ApInvoiceFixtures::invoice('t' . $inline . strlen($type), attachment: 'scan'), 'bytes', $type);
            $invoice = $this->world->invoices->find('t' . $inline . strlen($type));
            self::assertNotNull($invoice);
            self::assertSame($inline, $this->world->attachments->file($this->world->actor(), $invoice)->inlineAllowed, $type);
        }
    }

    public function test_no_document_and_lost_bytes_are_404_with_their_own_words(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('none'));
        $this->world->stored(ApInvoiceFixtures::invoice('lost', fileId: 'file-the-store-lost'));
        $none = $this->world->invoices->find('none');
        $lost = $this->world->invoices->find('lost');
        self::assertNotNull($none);
        self::assertNotNull($lost);

        self::refused(ApNotFound::class, 'This invoice has no document', fn () => $this->world->attachments->file($this->world->actor(), $none));
        self::refused(ApNotFound::class, 'The file is missing', fn () => $this->world->attachments->file($this->world->actor(), $lost));
    }

    public function test_the_guard_answers_only_for_tri_ap_invoices_that_exist(): void
    {
        $this->world->stored(ApInvoiceFixtures::invoice('a'), 'bytes');
        $store = $this->world->fileStore;
        self::assertInstanceOf(InMemoryFinanceAttachmentStore::class, $store);
        $invoice = $this->world->invoices->find('a');
        self::assertNotNull($invoice);
        self::assertNotNull($invoice->fileId);
        self::assertNotNull($this->world->attachments->read(7, $invoice->fileId));

        // The invoice goes (its row only); the file is still in the store, but nothing may see it through the guard.
        $this->world->invoices->purge('a', null, null, ApInvoiceFixtures::utc('2026-10-09 00:00:00'));
        self::assertNull($this->world->attachments->read(7, $invoice->fileId));
    }

    public function test_the_scan_keeps_a_file_unattached_and_attaches_it_to_the_invoice_it_creates(): void
    {
        $file = $this->world->attachments->keep('Scanned.pdf', 'application/pdf', '%PDF scanned', 7);
        self::assertFalse($file->isAttached());

        $this->world->attachments->attach($file->id, 'inv-1', 7);

        self::assertTrue($this->world->fileStore->find(Module::APP_AP, $file->id)?->isAttached());
        // Once only, and only by the person who kept it.
        self::refused(ApInvalid::class, ApAttachmentService::UNKNOWN_UPLOAD, fn () => $this->world->attachments->attach($file->id, 'inv-2', 7));
        $other = $this->world->attachments->keep('b.pdf', 'application/pdf', '%PDF', 7);
        self::refused(ApInvalid::class, ApAttachmentService::UNKNOWN_UPLOAD, fn () => $this->world->attachments->attach($other->id, 'inv-3', 99));
    }
}
