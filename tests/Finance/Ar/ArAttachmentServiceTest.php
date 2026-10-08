<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Ar\InMemoryArAssigneeDirectory;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArKeptAttachment;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Service\ArAttachmentService;
use TriCab\Core\Finance\Ar\Service\ArOptionService;
use TriCab\Core\Finance\Ar\Service\ArRecordGuard;
use TriCab\Core\Finance\Ar\Service\ArRecordService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentRules;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;

/**
 * Files on records (T5.5) on the in-memory twins: kept under app `AR` and record type `email_record`, served only
 * for the record in the URL, removed with a permanent delete, and a failed removal leaving an audit row.
 */
final class ArAttachmentServiceTest extends TestCase
{
    private InMemoryArStore $store;

    private InMemoryFinanceAttachmentStore $files;

    private ArAttachmentService $service;

    private ArActor $actor;

    protected function setUp(): void
    {
        $this->store = new InMemoryArStore();
        $this->files = new InMemoryFinanceAttachmentStore();
        $this->service = new ArAttachmentService(
            $this->store,
            new FinanceAttachmentService($this->files),
            new ArRecordGuard($this->store),
            $this->time(),
        );
        $this->actor = new ArActor(7, 'Rae Reviewer', 'rae@sample.local', []);
    }

    private function time(): ArTime
    {
        return new ArTime(FixedClock::utc('2026-09-22 03:00:00'), 'Australia/Melbourne');
    }

    public function test_a_kept_file_is_stored_under_the_ar_app_and_the_email_record_type(): void
    {
        $this->store->insert(ArFixtures::record('r1'));

        $id = $this->service->keep('r1', 'remittance.pdf', 'application/pdf', '%PDF-1.4 invented');

        $stored = $this->files->forRecord('AR', 'email_record', 'r1');
        self::assertCount(1, $stored);
        self::assertSame('remittance.pdf', $stored[0]->originalName);
        self::assertSame(1, $this->store->attachmentCount('r1'));
        self::assertNotSame('', $id);
        $rows = $this->store->attachmentsOf('r1');
        self::assertSame('pending', $rows[0]['extraction_status']);
        self::assertSame('remittance.pdf', $rows[0]['file_name']);
    }

    public function test_two_files_with_one_name_are_two_files(): void
    {
        $this->store->insert(ArFixtures::record('r1'));

        $first = $this->service->keep('r1', 'scan.pdf', 'application/pdf', 'first');
        $second = $this->service->keep('r1', 'scan.pdf', 'application/pdf', 'second');

        self::assertNotSame($first, $second);
        self::assertSame('first', $this->service->file($this->actor, 'r1', $first)->bytes);
        self::assertSame('second', $this->service->file($this->actor, 'r1', $second)->bytes);
    }

    public function test_the_file_is_served_with_its_type_and_a_safe_name(): void
    {
        $this->store->insert(ArFixtures::record('r1'));
        $id = $this->service->keep('r1', '..\\..\\pay slip.pdf', 'application/pdf', 'bytes');

        $file = $this->service->file($this->actor, 'r1', $id);

        self::assertSame('application/pdf', $file->contentType);
        self::assertSame('pay slip.pdf', $file->downloadName);
    }

    public function test_another_records_attachment_is_not_found(): void
    {
        $this->store->insert(ArFixtures::record('r1'));
        $this->store->insert(ArFixtures::record('r2'));
        $id = $this->service->keep('r1', 'a.pdf', 'application/pdf', 'bytes');

        $this->expectException(ArNotFound::class);
        $this->expectExceptionMessage('Attachment not found');
        $this->service->file($this->actor, 'r2', $id);
    }

    public function test_an_unknown_attachment_is_not_found(): void
    {
        $this->store->insert(ArFixtures::record('r1'));

        $this->expectException(ArNotFound::class);
        $this->service->file($this->actor, 'r1', 'nope');
    }

    public function test_a_binned_record_still_serves_its_files(): void
    {
        $this->store->insert(ArFixtures::record('r1', deletedAt: ArFixtures::utc('2026-09-20 00:00:00')));
        $id = $this->service->keep('r1', 'a.pdf', 'application/pdf', 'bytes');

        self::assertSame('bytes', $this->service->file($this->actor, 'r1', $id)->bytes);
    }

    public function test_a_row_whose_file_is_gone_says_the_file_is_missing(): void
    {
        $this->store->insert(ArFixtures::record('r1'));
        $id = $this->service->keep('r1', 'a.pdf', 'application/pdf', 'bytes');
        $this->files->deleteForRecord('AR', 'email_record', 'r1');

        $this->expectException(ArNotFound::class);
        $this->expectExceptionMessage('Attachment file is missing');
        $this->service->file($this->actor, 'r1', $id);
    }

    public function test_keeping_for_an_unknown_record_is_refused_and_stores_nothing(): void
    {
        try {
            $this->service->keep('ghost', 'a.pdf', 'application/pdf', 'bytes');
            self::fail('Expected the keep to be refused.');
        } catch (ArNotFound) {
            self::assertSame([], $this->files->forRecord('AR', 'email_record', 'ghost'));
        }
    }

    public function test_an_empty_or_oversize_file_is_refused(): void
    {
        $this->store->insert(ArFixtures::record('r1'));

        try {
            $this->service->keep('r1', 'a.pdf', 'application/pdf', '');
            self::fail('Expected an empty file to be refused.');
        } catch (ArInvalid) {
            self::assertSame(0, $this->store->attachmentCount('r1'));
        }
        $this->expectException(ArInvalid::class);
        $this->service->keep('r1', 'big.pdf', 'application/pdf', str_repeat('x', FinanceAttachmentRules::MAX_BYTES + 1));
    }

    public function test_a_failed_row_write_takes_the_file_off_again(): void
    {
        $store = new class () extends InMemoryArStore {
            public function addAttachment(ArKeptAttachment $attachment): void
            {
                throw new RuntimeException('database down');
            }
        };
        $store->insert(ArFixtures::record('r1'));
        $service = new ArAttachmentService($store, new FinanceAttachmentService($this->files), new ArRecordGuard($store), $this->time());

        try {
            $service->keep('r1', 'a.pdf', 'application/pdf', 'bytes');
            self::fail('Expected the failure to come through.');
        } catch (RuntimeException $e) {
            self::assertSame('database down', $e->getMessage());
            self::assertSame([], $this->files->forRecord('AR', 'email_record', 'r1'));
        }
    }

    public function test_a_permanent_delete_removes_the_files_and_the_rows(): void
    {
        $this->store->insert(ArFixtures::record('r1', deletedAt: ArFixtures::utc('2026-09-20 00:00:00')));
        $this->service->keep('r1', 'a.pdf', 'application/pdf', 'bytes');
        $records = $this->recordService($this->files, new InMemoryFinanceAuditLog());

        $answer = $records->delete($this->actor, 'r1', true);

        self::assertSame(['in_bin' => false, 'deleted' => true], $answer);
        self::assertSame([], $this->files->forRecord('AR', 'email_record', 'r1'));
        self::assertSame(0, $this->store->attachmentCount('r1'));
    }

    public function test_a_failed_file_delete_after_the_rows_are_gone_leaves_an_audit_row_and_says_so(): void
    {
        $this->store->insert(ArFixtures::record('r1', deletedAt: ArFixtures::utc('2026-09-20 00:00:00')));
        $this->service->keep('r1', 'a.pdf', 'application/pdf', 'bytes');
        $audit = new InMemoryFinanceAuditLog();
        $records = $this->recordService(new ThrowingAttachmentStore($this->files), $audit);

        $answer = $records->delete($this->actor, 'r1', true);

        self::assertSame(['in_bin' => false, 'deleted' => true, 'files_orphaned' => true], $answer);
        self::assertNull($this->store->find('r1'), 'the record is gone');
        self::assertCount(1, $audit->events);
        self::assertSame('ar.record.files_orphaned', $audit->events[0]->action);
        self::assertSame('r1', $audit->events[0]->entityId);
        self::assertSame('AR', $audit->events[0]->application);
        self::assertSame(7, $audit->events[0]->actorUserId);
        self::assertStringContainsString('RuntimeException', json_encode($audit->events[0]->details, JSON_THROW_ON_ERROR));
    }

    private function recordService(FinanceAttachmentStore $files, InMemoryFinanceAuditLog $audit): ArRecordService
    {
        $time = $this->time();

        return new ArRecordService($this->store, new ArOptionService($this->store, $time), new InMemoryArAssigneeDirectory(), $time, $files, $audit);
    }
}
