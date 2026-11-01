<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Attachments;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachment;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentInvalid;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentNotFound;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentRules;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;
use TriCab\Core\Finance\Shared\Attachments\FinanceRecordGuard;
use TriCab\Core\Finance\Shared\Attachments\FinanceUnattachedAttachmentsJob;

/**
 * What every finance file store does, written once and run against each adapter:
 * the in-memory twin, and the SQL-and-blob store over a real local SQL Server.
 * No test touches Azure or the network; the blob store is the platform's in-memory one.
 */
abstract class FinanceAttachmentStoreBehaviour extends TestCase
{
    protected FinanceAttachmentStore $store;

    protected FinanceAttachmentService $service;

    protected FixedClock $clock;

    private int $ids = 0;

    abstract protected function newStore(): FinanceAttachmentStore;

    /** How many byte blobs the adapter holds right now. */
    abstract protected function blobCount(): int;

    protected function setUp(): void
    {
        $this->ids = 0;
        $this->clock = FixedClock::utc('2026-10-07 01:00:00');
        $this->store = $this->newStore();
        $this->service = new FinanceAttachmentService(
            $this->store,
            fn (): DateTimeImmutable => $this->clock->now(),
            function (): string {
                return str_pad(dechex(++$this->ids), 32, '0', STR_PAD_LEFT);
            },
        );
    }

    private static function allow(): FinanceRecordGuard
    {
        return new class implements FinanceRecordGuard {
            public function maySee(string $application, string $recordType, string $recordId): bool
            {
                return true;
            }
        };
    }

    /** @param list<string> $ids record ids this guard lets through */
    private static function only(array $ids): FinanceRecordGuard
    {
        return new class ($ids) implements FinanceRecordGuard {
            /** @param list<string> $ids */
            public function __construct(private readonly array $ids)
            {
            }

            public function maySee(string $application, string $recordType, string $recordId): bool
            {
                return in_array($recordId, $this->ids, true);
            }
        };
    }

    public function test_an_upload_is_unattached_and_its_bytes_and_fingerprint_are_kept(): void
    {
        $file = $this->service->upload('AP2', 'Invoice 7.pdf', 'application/pdf', 'PDFBYTES', 42);

        self::assertFalse($file->isAttached());
        self::assertSame('AP2', $file->application);
        self::assertSame(8, $file->byteSize);
        self::assertSame(hash('sha256', 'PDFBYTES'), $file->sha256);
        self::assertSame(42, $file->uploadedByUserId);
        self::assertSame('Invoice 7.pdf', $file->originalName);
        self::assertSame('application/pdf', $file->contentType);
        self::assertSame('ap2/2026/10/' . $file->id, $file->blobPath);

        $found = $this->store->find('AP2', $file->id);
        self::assertNotNull($found);
        self::assertSame('2026-10-07 01:00:00.000000', $found->createdAt->format('Y-m-d H:i:s.u'));
        self::assertNull($found->attachedAt);
        self::assertSame('PDFBYTES', $this->store->bytes($found));
    }

    public function test_two_files_with_the_same_name_are_two_files_each_with_its_own_bytes(): void
    {
        // Tri-AR overwrites a file that has the same name as an earlier one.
        $first = $this->service->upload('AR', 'remittance.pdf', 'application/pdf', 'FIRST', 1);
        $second = $this->service->upload('AR', 'remittance.pdf', 'application/pdf', 'SECOND', 1);

        self::assertNotSame($first->id, $second->id);
        self::assertNotSame($first->blobPath, $second->blobPath);
        self::assertSame(2, $this->blobCount());
        self::assertSame('FIRST', $this->store->bytes($first));
        self::assertSame('SECOND', $this->store->bytes($second));
    }

    public function test_the_senders_name_is_data_and_never_part_of_a_path(): void
    {
        $hostile = $this->service->upload('TASK', '../../etc/passwd', 'text/plain', 'x', 1);
        $windows = $this->service->upload('TASK', '..\\..\\boot.ini', 'text/plain', 'x', 1);

        self::assertSame('../../etc/passwd', $hostile->originalName);
        self::assertSame('passwd', $hostile->downloadName());
        self::assertSame('boot.ini', $windows->downloadName());
        foreach ([$hostile, $windows] as $file) {
            self::assertMatchesRegularExpression('~^task/2026/10/[0-9a-f]{32}$~', $file->blobPath);
            self::assertNotNull($this->store->find('TASK', $file->id));
        }
        self::assertSame('file', (new FinanceAttachment('i', 'TASK', null, null, 'p', '..', 't', 1, 's', null, $this->clock->now(), null))->downloadName());
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function badUploads(): iterable
    {
        yield 'empty file' => ['AP2', 'a.pdf', 'application/pdf', ''];
        yield 'too big' => ['AP2', 'a.pdf', 'application/pdf', str_repeat('x', FinanceAttachmentRules::MAX_BYTES + 1)];
        yield 'no name' => ['AP2', '   ', 'application/pdf', 'x'];
        yield 'control character in the name' => ['AP2', "a\0.pdf", 'application/pdf', 'x'];
        yield 'name too long' => ['AP2', str_repeat('n', 256), 'application/pdf', 'x'];
        yield 'not an app' => ['WIDGET', 'a.pdf', 'application/pdf', 'x'];
        yield 'bad content type' => ['AP2', 'a.pdf', 'not a type', 'x'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badUploads')]
    public function test_a_bad_upload_is_refused_and_leaves_nothing(string $app, string $name, string $type, string $bytes): void
    {
        try {
            $this->service->upload($app, $name, $type, $bytes, 1);
            self::fail('Expected a refusal.');
        } catch (FinanceAttachmentInvalid) {
            self::assertSame(0, $this->blobCount());
        }
    }

    public function test_the_largest_allowed_file_and_an_empty_content_type_are_accepted(): void
    {
        $file = $this->service->upload('CREDIT', str_repeat('n', 255), '', str_repeat('x', 1024), null);

        self::assertSame(FinanceAttachmentRules::DEFAULT_CONTENT_TYPE, $file->contentType);
        self::assertNull($file->uploadedByUserId);
        self::assertSame(255, mb_strlen($file->originalName));
    }

    public function test_only_the_uploader_attaches_a_file_and_only_once(): void
    {
        $file = $this->service->upload('CREDIT', 'quote.pdf', 'application/pdf', 'Q', 7);

        foreach ([8, null] as $stranger) {
            try {
                $this->service->attach('CREDIT', $file->id, 'credit_note', 'CN-1', $stranger);
                self::fail('Expected not found.');
            } catch (FinanceAttachmentNotFound) {
                self::assertFalse($this->store->find('CREDIT', $file->id)?->isAttached());
            }
        }
        try {
            $this->service->attach('AP2', $file->id, 'invoice', '9', 7);
            self::fail('Another app cannot attach it.');
        } catch (FinanceAttachmentNotFound) {
        }

        $attached = $this->service->attach('CREDIT', $file->id, 'credit_note', 'CN-1', 7);
        self::assertTrue($attached->isAttached());
        self::assertSame('credit_note', $attached->recordType);
        self::assertSame('CN-1', $attached->recordId);

        $again = $this->service->forRecord('CREDIT', 'credit_note', 'CN-1');
        self::assertSame([$file->id], array_map(static fn (FinanceAttachment $a): string => $a->id, $again));
        self::assertNotNull($again[0]->attachedAt);

        $this->expectException(FinanceAttachmentNotFound::class);
        $this->service->attach('CREDIT', $file->id, 'credit_note', 'CN-2', 7);
    }

    public function test_a_record_type_and_id_must_look_like_one(): void
    {
        $file = $this->service->upload('AP2', 'a.pdf', 'application/pdf', 'x', 1);

        foreach ([['Invoice', '1'], ['invoice', ''], ['invoice', '../1'], ['i', '1'], ['invoice', str_repeat('1', 101)]] as [$type, $id]) {
            try {
                $this->service->attach('AP2', $file->id, $type, $id, 1);
                self::fail('Expected a refusal for ' . $type . ' ' . $id);
            } catch (FinanceAttachmentInvalid) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_a_file_on_a_record_is_served_only_where_the_apps_guard_lets_the_caller_see_the_record(): void
    {
        $file = $this->service->upload('AP2', 'inv.pdf', 'application/pdf', 'INV', 5);
        $this->service->attach('AP2', $file->id, 'invoice', '100', 5);

        $served = $this->service->download('AP2', $file->id, 99, self::only(['100']));
        self::assertSame('INV', $served->bytes);
        self::assertSame($file->id, $served->attachment->id);

        // Credit Notes serves any file to any signed-in user today; here the guard decides.
        $this->expectException(FinanceAttachmentNotFound::class);
        $this->service->download('AP2', $file->id, 99, self::only(['200']));
    }

    public function test_a_file_is_not_served_to_another_app_or_by_a_guess(): void
    {
        $file = $this->service->upload('AP2', 'inv.pdf', 'application/pdf', 'INV', 5);
        $this->service->attach('AP2', $file->id, 'invoice', '100', 5);

        foreach (['AR', 'TASK', 'WIDGET'] as $other) {
            try {
                $this->service->download($other, $file->id, 5, self::allow());
                self::fail('Another app must not reach it.');
            } catch (FinanceAttachmentNotFound) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(FinanceAttachmentNotFound::class);
        $this->service->download('AP2', str_repeat('f', 32), 5, self::allow());
    }

    public function test_an_upload_not_yet_on_a_record_is_served_only_to_the_person_who_uploaded_it(): void
    {
        $file = $this->service->upload('AP2', 'inv.pdf', 'application/pdf', 'INV', 5);

        self::assertSame('INV', $this->service->download('AP2', $file->id, 5, self::only([]))->bytes);
        foreach ([6, null] as $viewer) {
            try {
                $this->service->download('AP2', $file->id, $viewer, self::allow());
                self::fail('Expected not found.');
            } catch (FinanceAttachmentNotFound) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_deleting_a_record_removes_its_files_and_their_bytes_and_nothing_else(): void
    {
        $a = $this->service->upload('AR', 'a.pdf', 'application/pdf', 'A', 1);
        $b = $this->service->upload('AR', 'b.pdf', 'application/pdf', 'B', 1);
        $other = $this->service->upload('AR', 'c.pdf', 'application/pdf', 'C', 1);
        $otherApp = $this->service->upload('AP2', 'd.pdf', 'application/pdf', 'D', 1);
        $this->service->attach('AR', $a->id, 'email_record', '12', 1);
        $this->service->attach('AR', $b->id, 'email_record', '12', 1);
        $this->service->attach('AR', $other->id, 'email_record', '13', 1);
        $this->service->attach('AP2', $otherApp->id, 'email_record', '12', 1);
        self::assertSame(4, $this->blobCount());

        self::assertSame(2, $this->service->removeRecordFiles('AR', 'email_record', '12'));

        self::assertSame([], $this->service->forRecord('AR', 'email_record', '12'));
        self::assertNull($this->store->find('AR', $a->id));
        self::assertNull($this->store->bytes($a));
        self::assertSame(2, $this->blobCount());
        self::assertNotNull($this->store->find('AR', $other->id));
        self::assertNotNull($this->store->find('AP2', $otherApp->id));
        self::assertSame(0, $this->service->removeRecordFiles('AR', 'email_record', '12'));
    }

    public function test_counts_by_record_type_are_one_read_of_each_records_files_for_one_app(): void
    {
        $files = [
            ['TASK', 'task', 't1'], ['TASK', 'task', 't1'], ['TASK', 'task', 't2'],
            ['TASK', 'other_kind', 't1'], ['AR', 'task', 't1'],
        ];
        foreach ($files as [$app, $type, $record]) {
            $file = $this->service->upload($app, 'f.pdf', 'application/pdf', 'x', 1);
            $this->service->attach($app, $file->id, $type, $record, 1);
        }
        $this->service->upload('TASK', 'loose.pdf', 'application/pdf', 'x', 1);

        self::assertSame(['t1' => 2, 't2' => 1], $this->store->countsByRecordType('TASK', 'task'));
        self::assertSame([], $this->store->countsByRecordType('AP2', 'task'));
        $this->service->removeRecordFiles('TASK', 'task', 't1');
        self::assertSame(['t2' => 1], $this->store->countsByRecordType('TASK', 'task'));
    }

    public function test_one_file_can_be_removed_and_a_missing_one_says_so(): void
    {
        $file = $this->service->upload('TASK', 'a.pdf', 'application/pdf', 'A', 1);

        self::assertFalse($this->service->remove('AP2', $file->id));
        self::assertTrue($this->service->remove('TASK', $file->id));
        self::assertFalse($this->service->remove('TASK', $file->id));
        self::assertSame(0, $this->blobCount());
    }

    public function test_an_upload_never_put_on_a_record_is_swept_after_a_day_and_nothing_else_is(): void
    {
        $abandoned = $this->service->upload('AP2', 'old.pdf', 'application/pdf', 'OLD', 1);
        $kept = $this->service->upload('AP2', 'kept.pdf', 'application/pdf', 'KEPT', 1);
        $this->service->attach('AP2', $kept->id, 'invoice', '1', 1);
        $this->clock->advance('+23 hours');
        $recent = $this->service->upload('AP2', 'recent.pdf', 'application/pdf', 'NEW', 1);
        $this->clock->advance('+2 hours');
        $job = new FinanceUnattachedAttachmentsJob($this->store);

        self::assertSame(FinanceUnattachedAttachmentsJob::NAME, $job->name());
        self::assertSame(['deleted' => 1], $job->run($this->clock->now()));

        self::assertNull($this->store->find('AP2', $abandoned->id));
        self::assertNull($this->store->bytes($abandoned));
        self::assertNotNull($this->store->find('AP2', $kept->id), 'an attached file, however old, stays');
        self::assertNotNull($this->store->find('AP2', $recent->id), 'a recent upload stays');
        self::assertSame(2, $this->blobCount());
        self::assertSame(['deleted' => 0], $job->run($this->clock->now()));
    }

    public function test_the_sweep_stops_at_its_limit_and_finishes_on_the_next_run(): void
    {
        foreach (range(1, 5) as $n) {
            $this->service->upload('AP2', "u{$n}.pdf", 'application/pdf', 'x', 1);
        }
        $this->clock->advance('+3 days');
        $job = new FinanceUnattachedAttachmentsJob($this->store, 24, 3);

        self::assertSame(['deleted' => 3], $job->run($this->clock->now()));
        self::assertSame(['deleted' => 2], $job->run($this->clock->now()));
        self::assertSame(0, $this->blobCount());
    }

    public function test_the_job_runs_daily_at_a_quiet_hour_under_a_valid_name(): void
    {
        $job = new FinanceUnattachedAttachmentsJob($this->store);

        self::assertMatchesRegularExpression('/^[a-z0-9._-]+$/', $job->name());
        $melbourne = new DateTimeImmutable('2026-10-07 03:30:00', new DateTimeZone('Australia/Melbourne'));
        self::assertNotNull($job->schedule()->dueSlot($melbourne));
        self::assertNull($job->schedule()->dueSlot($melbourne->modify('-1 minute')));
    }

    public function test_every_app_key_can_hold_files(): void
    {
        foreach (array_keys(Module::APPS) as $key) {
            $file = $this->service->upload($key, 'a.pdf', 'application/pdf', 'x', 1);
            self::assertSame($key, $this->store->find($key, $file->id)?->application);
        }
    }
}
