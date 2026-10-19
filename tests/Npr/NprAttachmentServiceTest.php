<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Npr\Support\NprWorkflowWorld as W;
use TriCab\Core\Npr\Application\Service\NprAccessPolicy;
use TriCab\Core\Npr\Application\Service\NprAttachmentService as S;
use TriCab\Core\Npr\Contract\NprAttachmentStore;
use TriCab\Core\Npr\Domain\Exception\InvalidNprInput;
use TriCab\Core\Npr\Domain\Exception\NprAttachmentRejected;
use TriCab\Core\Npr\Domain\Exception\NprAttachmentStorageUnavailable;
use TriCab\Core\Npr\Domain\Exception\NprRequestNotEditable;
use TriCab\Core\Npr\Infrastructure\InMemoryNprAttachmentRepository;
use TriCab\Core\Npr\Infrastructure\InMemoryNprAttachmentStore;

/** Attachments (plan N2.3): limits, content sniffing, soft delete, and who may see or change what. */
final class NprAttachmentServiceTest extends TestCase
{
    private W $w;
    private InMemoryNprAttachmentStore $store;
    private S $files;

    protected function setUp(): void
    {
        $this->w = new W();
        $this->store = new InMemoryNprAttachmentStore();
        $this->files = new S($this->w->requests, new InMemoryNprAttachmentRepository($this->w->requests, $this->w->clock), $this->store, new NprAccessPolicy());
    }

    public static function pdf(int $size = 200): string
    {
        $head = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";

        return substr($head . str_repeat('x', max(0, $size - strlen($head))), 0, max($size, strlen($head)));
    }

    private static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }

    private static function jpeg(): string
    {
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private static function ooxml(string $part): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ooxml');
        $zip = new \ZipArchive();
        $zip->open((string) $path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString($part, '<x/>');
        $zip->close();
        $bytes = (string) file_get_contents((string) $path);
        @unlink((string) $path);

        return $bytes;
    }

    private function upload(string $name, string $bytes, string $scope = S::SCOPE_OWN, ?string $id = null, int $user = W::SALES): \TriCab\Core\Npr\Domain\NprAttachment
    {
        $id ??= $this->draftId();

        return $this->files->upload($this->w->user($user), $scope, $id, $name, $bytes, null, null) ?? throw new \LogicException('not found');
    }

    private ?string $draft = null;

    private function draftId(): string
    {
        return $this->draft ??= $this->w->completeDraft()[0];
    }

    private function expectRejected(string $reason, string $name, string $bytes): void
    {
        try {
            $this->upload($name, $bytes);
            self::fail('Expected ' . $reason);
        } catch (NprAttachmentRejected $rejected) {
            self::assertSame($reason, $rejected->reason, $rejected->getMessage());
        }
    }

    public function test_an_upload_is_stored_under_a_generated_name_with_one_activity_row(): void
    {
        $file = $this->upload('Customer ../../drawing.PDF', self::pdf());

        self::assertSame('drawing.PDF', $file->filename, 'the base name only');
        self::assertSame('application/pdf', $file->mimeType);
        self::assertSame('datasheet', $file->documentType);
        self::assertMatchesRegularExpression('#^[0-9a-f-]{36}/[0-9a-f]{24}\.pdf$#', $file->blobKey, 'a generated blob name, never the client\'s');
        self::assertArrayHasKey($file->blobKey, $this->store->objects);
        self::assertSame(self::pdf(), $this->store->get($file->blobKey));
        $log = $this->w->requests->activityLog($this->draftId());
        $added = array_values(array_filter($log, static fn ($a): bool => $a->action === 'attachment_added'));
        self::assertCount(1, $added);
        self::assertSame('requester', $added[0]->actorRole);
        self::assertStringContainsString('drawing.PDF', $added[0]->summary);
        self::assertCount(1, $this->files->forRequest($this->draftId()));
    }

    public function test_every_allowed_type_is_accepted_when_the_content_matches(): void
    {
        $cases = [
            'a.pdf' => [self::pdf(), 'application/pdf'],
            'a.png' => [self::png(), 'image/png'],
            'a.jpg' => [self::jpeg(), 'image/jpeg'],
            'a.JPEG' => [self::jpeg(), 'image/jpeg'],
            'a.csv' => ["code,qty\nA,1\n", 'text/csv'],
            'a.docx' => [self::ooxml('word/document.xml'), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'a.xlsx' => [self::ooxml('xl/workbook.xml'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'a.pptx' => [self::ooxml('ppt/presentation.xml'), 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        ];
        foreach ($cases as $name => [$bytes, $mime]) {
            self::assertSame($mime, $this->upload($name, $bytes)->mimeType, $name);
        }
    }

    public function test_the_extension_and_the_content_must_agree(): void
    {
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'virus.exe', 'MZ' . str_repeat("\0", 100));
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'noextension', self::pdf());
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'page.html', '<html></html>');
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'fake.pdf', self::png());
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'fake.png', self::pdf());
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'fake.jpg', self::png());
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'fake.pdf', '<script>alert(1)</script>');
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'fake.docx', "PK\x03\x04" . str_repeat('x', 40));
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'fake.docx', self::ooxml('xl/workbook.xml'));
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'fake.xlsx', self::ooxml('word/document.xml'));
        $this->expectRejected(NprAttachmentRejected::TYPE_NOT_ALLOWED, 'binary.csv', "a,b\0\1\2\3");
        $this->expectRejected(NprAttachmentRejected::EMPTY, 'empty.pdf', '');
        self::assertSame([], $this->store->objects, 'a refused file never reaches storage');
    }

    public function test_a_file_over_ten_mib_is_too_large(): void
    {
        $this->expectRejected(NprAttachmentRejected::TOO_LARGE, 'big.pdf', self::pdf(10 * 1024 * 1024 + 1));
        self::assertSame('application/pdf', $this->upload('limit.pdf', self::pdf(10 * 1024 * 1024))->mimeType, 'exactly 10 MiB is fine');
    }

    public function test_a_request_holds_at_most_thirty_mib(): void
    {
        $nine = self::pdf(9 * 1024 * 1024);
        $this->upload('1.pdf', $nine);
        $this->upload('2.pdf', $nine);
        $this->upload('3.pdf', $nine);
        try {
            $this->upload('4.pdf', $nine);
            self::fail('36 MiB is over the request limit');
        } catch (NprAttachmentRejected $rejected) {
            self::assertSame(NprAttachmentRejected::REQUEST_TOO_LARGE, $rejected->reason);
            self::assertSame(413, $rejected->status);
        }
        self::assertSame('application/pdf', $this->upload('small.pdf', self::pdf(1024 * 1024))->mimeType, 'a smaller one still fits under 30 MiB');
    }

    public function test_a_request_holds_at_most_twenty_files(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->upload($i . '.pdf', self::pdf());
        }
        $this->expectRejected(NprAttachmentRejected::TOO_MANY, '21.pdf', self::pdf());

        // Removing one makes room: only live files count.
        $first = $this->files->forRequest($this->draftId())[0];
        $this->files->remove($this->w->user(W::SALES), $this->draftId(), $first->publicId);
        self::assertSame('application/pdf', $this->upload('21.pdf', self::pdf())->mimeType);
    }

    public function test_the_document_type_is_checked(): void
    {
        $this->expectException(InvalidNprInput::class);
        $this->files->upload($this->w->user(W::SALES), S::SCOPE_OWN, $this->draftId(), 'a.pdf', self::pdf(), '<b>', null);
    }

    public function test_removal_is_a_soft_delete_with_one_activity_row(): void
    {
        $file = $this->upload('a.pdf', self::pdf());
        $kept = $this->upload('b.pdf', self::pdf());

        $removed = $this->files->remove($this->w->user(W::SALES), $this->draftId(), $file->publicId);

        self::assertSame($file->publicId, $removed?->publicId);
        self::assertSame([$kept->publicId], array_map(static fn ($f): string => $f->publicId, $this->files->forRequest($this->draftId())));
        self::assertNull($this->files->download($this->w->user(W::SALES), S::SCOPE_OWN, $this->draftId(), $file->publicId), 'gone from the download');
        self::assertArrayHasKey($file->blobKey, $this->store->objects, 'the blob is kept');
        $log = array_values(array_filter($this->w->requests->activityLog($this->draftId()), static fn ($a): bool => $a->action === 'attachment_removed'));
        self::assertCount(1, $log);
        self::assertNull($this->files->remove($this->w->user(W::SALES), $this->draftId(), $file->publicId), 'already removed');
    }

    public function test_sales_can_change_files_only_while_the_request_is_editable(): void
    {
        $id = $this->draftId();
        $file = $this->upload('a.pdf', self::pdf());
        $version = $this->w->requests->find($id)->version ?? '';
        $this->w->workflow->submit($this->w->user(W::SALES), $id, $version);

        try {
            $this->files->remove($this->w->user(W::SALES), $id, $file->publicId);
            self::fail('removal after submission');
        } catch (NprRequestNotEditable) {
            self::addToAssertionCount(1);
        }
        $this->expectException(NprRequestNotEditable::class);
        $this->upload('b.pdf', self::pdf());
    }

    public function test_a_returned_request_is_editable_again(): void
    {
        [$id, $version] = $this->w->submitted();
        $this->w->workflow->managerReturn($this->w->user(W::SHANE), $id, $version, 'More');

        self::assertSame('application/pdf', $this->upload('c.pdf', self::pdf(), S::SCOPE_OWN, $id)->mimeType);
    }

    public function test_the_manager_reads_but_never_writes(): void
    {
        [$id, $version] = $this->w->completeDraft();
        $this->upload('a.pdf', self::pdf(), S::SCOPE_OWN, $id);
        $this->w->workflow->submit($this->w->user(W::SALES), $id, $version);

        $list = $this->files->list($this->w->user(W::SHANE), S::SCOPE_TEAM, $id);
        self::assertCount(1, $list ?? []);
        $this->expectException(NprRequestNotEditable::class);
        $this->files->upload($this->w->user(W::SHANE), S::SCOPE_TEAM, $id, 'x.pdf', self::pdf(), null, null);
    }

    public function test_someone_with_no_part_in_the_request_gets_not_found(): void
    {
        $file = $this->upload('a.pdf', self::pdf());
        $id = $this->draftId();

        self::assertNull($this->files->list($this->w->user(W::OTHER_MANAGER), S::SCOPE_OWN, $id));
        self::assertNull($this->files->list($this->w->user(W::SHANE), S::SCOPE_TEAM, $id), 'a draft is not the manager\'s yet');
        self::assertNull($this->files->list($this->w->user(W::TSD), S::SCOPE_TSD, $id), 'not in the TSD queue yet');
        self::assertNull($this->files->download($this->w->user(W::TSD), S::SCOPE_TSD, $id, $file->publicId));
        self::assertNull($this->files->upload($this->w->user(W::OTHER_MANAGER), S::SCOPE_OWN, $id, 'x.pdf', self::pdf(), null, null));
        self::assertNull($this->files->list($this->w->user(W::SALES), S::SCOPE_OWN, '99999999-9999-4999-8999-999999999999'));
    }

    public function test_tsd_reads_and_uploads_once_the_request_is_with_tsd_and_sales_cannot_remove_its_files(): void
    {
        [$id, $version] = $this->w->completeDraft();
        $sales = $this->upload('spec.pdf', self::pdf(), S::SCOPE_OWN, $id);
        $v = W::version($this->w->workflow->submit($this->w->user(W::SALES), $id, $version));
        $this->w->workflow->endorse($this->w->user(W::SHANE), $id, $v, true, null);

        $manager = $this->files->list($this->w->user(W::SHANE), S::SCOPE_TEAM, $id);
        self::assertCount(1, $manager ?? []);
        $tsd = $this->upload('assessment.pdf', self::pdf(), S::SCOPE_TSD, $id, W::TSD);
        self::assertCount(2, $this->files->list($this->w->user(W::TSD), S::SCOPE_TSD, $id) ?? []);
        $download = $this->files->download($this->w->user(W::TSD), S::SCOPE_TSD, $id, $sales->publicId);
        self::assertSame(self::pdf(), $download['bytes'] ?? null);
        $roles = array_map(static fn ($a): string => $a->actorRole, array_filter($this->w->requests->activityLog($id), static fn ($a): bool => $a->action === 'attachment_added'));
        self::assertSame(['requester', 'tsd'], array_values($roles));

        // Sales, once it is returned and editable, still cannot remove TSD's file.
        $v = $this->w->requests->find($id)->version ?? '';
        $this->w->workflow->tsdReturn($this->w->user(W::TSD), $id, $v, 'Need more');
        self::assertNull($this->files->remove($this->w->user(W::SALES), $id, $tsd->publicId));
        self::assertNotNull($this->files->remove($this->w->user(W::SALES), $id, $sales->publicId));
    }

    public function test_tsd_upload_needs_work_permission_and_a_working_status(): void
    {
        [$id, $version] = $this->w->submitted();
        $this->w->workflow->endorse($this->w->user(W::SHANE), $id, $version, true, null);

        // The signatory can read the queue (TSD.READ) but holds no NPR.TSD.WORK.
        self::assertNotNull($this->files->list($this->w->user(W::SIGNATORY), S::SCOPE_TSD, $id));
        $this->expectException(NprRequestNotEditable::class);
        $this->files->upload($this->w->user(W::SIGNATORY), S::SCOPE_TSD, $id, 'x.pdf', self::pdf(), null, null);
    }

    public function test_a_storage_failure_records_nothing(): void
    {
        $failing = new class () implements NprAttachmentStore {
            public function put(string $blobKey, string $contentType, string $bytes): void
            {
                throw new NprAttachmentStorageUnavailable('down');
            }

            public function get(string $blobKey): ?string
            {
                return null;
            }

            public function discard(string $blobKey): void
            {
            }
        };
        $files = new S($this->w->requests, new InMemoryNprAttachmentRepository($this->w->requests, $this->w->clock), $failing, new NprAccessPolicy());
        $id = $this->draftId();

        try {
            $files->upload($this->w->user(W::SALES), S::SCOPE_OWN, $id, 'a.pdf', self::pdf(), null, null);
            self::fail('storage is down');
        } catch (NprAttachmentStorageUnavailable) {
            self::assertSame([], $files->forRequest($id));
        }
    }
}
