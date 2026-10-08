<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Ar\InMemoryArScanStore;
use TriCab\Api\Finance\Ar\InMemoryArStore;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Shared\Mailbox\InMemoryMailboxReader;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Ar\Domain\ArActor;
use TriCab\Core\Finance\Ar\Domain\ArAlreadyScanned;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArForbidden;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;
use TriCab\Core\Finance\Ar\Domain\ArNotFound;
use TriCab\Core\Finance\Ar\Domain\ArRecord;
use TriCab\Core\Finance\Ar\Domain\ArRecordQuery;
use TriCab\Core\Finance\Ar\Domain\ArScanRun;
use TriCab\Core\Finance\Ar\Domain\ArUpstream;
use TriCab\Core\Finance\Ar\Service\ArAiService;
use TriCab\Core\Finance\Ar\Service\ArAttachmentService;
use TriCab\Core\Finance\Ar\Service\ArMailboxScanJob;
use TriCab\Core\Finance\Ar\Service\ArOptionService;
use TriCab\Core\Finance\Ar\Service\ArRecordGuard;
use TriCab\Core\Finance\Ar\Service\ArScanService;
use TriCab\Core\Finance\Ar\Service\ArTime;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReadService;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;

/**
 * Tri-AR's scan (T5.7) on the in-memory twins: ingest and its enrichment and attachment failures, the Inbox-only
 * listing, dedupe and tombstones, the watermark rule (an unbroken run only; a period scan never moves it), the
 * 31-day slices and the cap bisection, the operator's three modes, the claim, and the daily job. Nothing reaches
 * Graph or a model, and every address is invented.
 */
final class ArScanServiceTest extends TestCase
{
    private const MAILBOX = 'ar@example.test';
    private const NOW = '2026-10-08 00:00:00';

    private FixedClock $clock;

    private InMemoryArStore $store;

    private InMemoryArScanStore $scans;

    private InMemoryMailboxReader $mailbox;

    private FlakyMailboxReader $reader;

    private ScriptedArAiClient $client;

    private InMemoryFinanceAttachmentStore $files;

    private ArActor $actor;

    /** @var list<string> */
    private array $aiLog = [];

    private int $ids = 0;

    private string $errorLogBefore = '';

    private string $errorLogFile = '';

    protected function setUp(): void
    {
        $this->clock = FixedClock::utc(self::NOW);
        $this->store = new InMemoryArStore();
        $this->scans = new InMemoryArScanStore();
        $this->mailbox = new InMemoryMailboxReader();
        $this->client = new ScriptedArAiClient();
        $this->files = new InMemoryFinanceAttachmentStore();
        $this->actor = new ArActor(7, 'Olive Operator', 'olive@sample.local', []);
        $this->reader = $this->readerFor($this->mailbox);
        // The scan writes a line to the error log when a record cannot be stored; keep it out of the test output.
        $this->errorLogBefore = (string) ini_get('error_log');
        $this->errorLogFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ar-scan-test-' . bin2hex(random_bytes(4)) . '.log';
        ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLogBefore);
        if (is_file($this->errorLogFile)) {
            unlink($this->errorLogFile);
        }
    }

    // ---- builders -----------------------------------------------------------------------

    private function readerFor(InMemoryMailboxReader $mailbox, bool $granted = true): FlakyMailboxReader
    {
        $registry = new InMemoryFinanceMailboxRegistry([
            new FinanceMailbox('0000000000000000000000000000000b', self::MAILBOX, 'Accounts Receivable', 'AR', true, ['AR' => ['read' => $granted, 'send' => false]]),
        ]);

        return new FlakyMailboxReader(new FinanceMailboxReadService($registry, $mailbox, new InMemoryFinanceAccessScopeReader(), new InMemoryFinanceAuditLog(), $this->clock));
    }

    private function time(): ArTime
    {
        return new ArTime($this->clock, 'Australia/Melbourne');
    }

    private function service(int $listCap = 500, ?InMemoryArStore $store = null): ArScanService
    {
        $time = $this->time();
        $records = $store ?? $this->store;
        $ai = new ArAiService($this->client, new ArOptionService($records, $time), function (string $line): void {
            $this->aiLog[] = $line;
        });
        $attachments = new ArAttachmentService($records, new FinanceAttachmentService($this->files), new ArRecordGuard($records), $time);

        return new ArScanService($records, $this->scans, $this->reader, $ai, $attachments, $time, self::MAILBOX, $listCap, fn (): string => sprintf('%032x', ++$this->ids));
    }

    private static function utc(string $when): DateTimeImmutable
    {
        return new DateTimeImmutable($when, new DateTimeZone('UTC'));
    }

    /**
     * @param list<MailboxAttachment> $attachments
     */
    private function add(string $id, string $received, string $subject = 'Please release the order', string $body = '<p>Hello</p>', array $attachments = [], string $folder = 'inbox'): DateTimeImmutable
    {
        $at = self::utc($received);
        $message = new MailboxMessage(
            new MailboxMessageSummary($id, $subject, 'pat@customer.example.test', 'Pat Payer', $at, $attachments !== [], 'preview'),
            MailboxMessage::BODY_HTML,
            $body,
        );
        $this->mailbox->add(self::MAILBOX, $message, $attachments, $folder);

        return $at;
    }

    private static function file(string $id, string $name, string $content = 'bytes', ?int $size = null, bool $inline = false): MailboxAttachment
    {
        return new MailboxAttachment(new MailboxAttachmentSummary($id, $name, 'application/octet-stream', $size ?? strlen($content), $inline), $content);
    }

    /** The one stored record for a Graph message id, with its body. */
    private function recordFor(string $messageId, ?InMemoryArStore $store = null): ArRecord
    {
        $store ??= $this->store;
        $query = new ArRecordQuery(false, null, null, null, null, null, null, null, null, false, 'email_date', false, 1000, 0, '2026-10-08');
        [$rows] = $store->page($query);
        foreach ($rows as $row) {
            if ($row->sourceMessageId === $messageId) {
                return $store->find($row->id) ?? self::fail('The record vanished.');
            }
        }
        self::fail('No record for ' . $messageId);
    }

    private function runOf(string $id): ArScanRun
    {
        return $this->scans->find($id) ?? self::fail('No run ' . $id);
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);
        $typed = [];
        foreach ($value as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }

    private function recordCount(): int
    {
        $query = new ArRecordQuery(false, null, null, null, null, null, null, null, null, false, 'email_date', false, 1000, 0, '2026-10-08');

        return $this->store->page($query)[1];
    }

    /** A completed run, as an earlier scan would have left it. */
    private function seedWatermark(string $when): void
    {
        $at = self::utc($when);
        $run = new ArScanRun('seed' . $this->ids++, $at, null, self::MAILBOX, ArScanRun::RUNNING, null, 0, 0, 0, 0, '', null, null, null, 'Scheduled scan', 0, 0, $at->modify('+30 minutes'));
        $this->scans->begin($run, $at);
        $this->scans->finish($run->id, ArScanRun::COMPLETED, $at, $at, $run);
    }

    // ---- ingest -------------------------------------------------------------------------

    public function test_a_message_becomes_a_record_with_the_source_fields_and_the_run_is_recorded(): void
    {
        $at = $this->add('imm-1', '2026-10-07 02:00:00', 'Release order 5', '<p>Hi <b>Sue</b>, please release order 5.</p><style>p{color:red}</style>');

        $answer = $this->service()->scanFromWatermark(null);

        self::assertSame([1, 1, 0, 0], [$answer['total_fetched'], $answer['new_records'], $answer['skipped_records'], $answer['failed_enrichments']]);
        $record = $this->recordFor('imm-1');
        self::assertSame('release_customer_orders', $record->followUpCategory);
        self::assertSame('email', $record->proposedResponseType);
        self::assertSame('in_progress', $record->followUpStatus);
        self::assertSame('new', $record->status);
        self::assertSame(0.91, $record->classificationConfidence);
        self::assertSame('Acme Marine Pty Ltd', $record->customerName);
        self::assertSame('A short summary.', $record->aiSummary);
        self::assertSame('Thanks, done.', $record->proposedResponse);
        self::assertSame('Release order 5', $record->subject);
        self::assertSame('pat@customer.example.test', $record->senderEmail);
        self::assertSame('Hi Sue, please release order 5.', $record->body, 'HTML is stripped to text');
        self::assertSame(self::MAILBOX, $record->mailbox);
        self::assertSame($at->format('c'), $record->emailDate->format('c'));
        self::assertNull($record->dueDate);
        self::assertNull($record->assignedUserId);

        $run = $this->runOf(self::str(self::arr($answer['run'])['id']));
        self::assertSame(ArScanRun::COMPLETED, $run->status);
        self::assertSame('Scheduled scan', $run->requestedBy);
        self::assertSame($at->format('c'), $run->watermark?->format('c'));
        self::assertNull($run->claimExpiresAt, 'the claim is released');
        self::assertSame(1, $this->client->count('ar_classify'));
        self::assertSame(1, $this->client->count('ar_prose'));
        self::assertSame(0, $this->client->count('ar_remittance'));
        self::assertSame('Hi Sue, please release order 5.', $this->client->calls[0]['data']['body'], 'the model reads text, not markup');
    }

    public function test_only_the_inbox_is_listed_and_every_listing_says_so(): void
    {
        $this->add('inbox-1', '2026-10-07 01:00:00');
        $this->add('sent-1', '2026-10-07 02:00:00', folder: 'sentitems');
        $this->add('junk-1', '2026-10-07 03:00:00', folder: 'junkemail');

        $answer = $this->service()->scanFromWatermark(null);

        self::assertSame(1, $answer['new_records']);
        self::assertSame(1, $this->recordCount());
        self::assertNotSame([], $this->reader->listings);
        foreach ($this->reader->listings as $listing) {
            self::assertSame('inbox', $listing['folder']);
        }
    }

    public function test_a_known_message_is_skipped_whatever_its_case_and_a_repeat_changes_nothing(): void
    {
        $this->add('imm-new', '2026-10-07 01:00:00');
        $this->add('IMM-Known', '2026-10-07 02:00:00');
        $this->store->insert(ArFixtures::record('existing', sourceMessageId: 'imm-known', mailbox: self::MAILBOX));

        $first = $this->service()->scanFromWatermark(null);
        $second = $this->service()->scanFromWatermark(null);

        self::assertSame([1, 1], [$first['new_records'], $first['skipped_records']]);
        self::assertSame([0, 1], [$second['new_records'], $second['skipped_records']], 'a second pass starts at the watermark (inclusive), sees only known mail and stores nothing');
        self::assertSame(2, $this->recordCount());
        self::assertSame(1, $this->client->count('ar_classify'), 'a known message is never sent to a model');
    }

    public function test_a_message_deleted_for_good_does_not_come_back(): void
    {
        $this->add('imm-gone', '2026-10-07 01:00:00');
        $this->store->insert(ArFixtures::record('old', sourceMessageId: 'imm-gone', mailbox: self::MAILBOX));
        $this->store->bin('old', 7, 'olive@sample.local', self::utc(self::NOW));
        $this->store->purge('old', self::utc(self::NOW));

        $answer = $this->service()->scanFromWatermark(null);

        self::assertSame([0, 1], [$answer['new_records'], $answer['skipped_records']]);
        self::assertSame(0, $this->recordCount());
    }

    public function test_a_failed_classification_leaves_the_record_for_review_with_the_fallbacks(): void
    {
        $this->add('imm-1', '2026-10-07 01:00:00');
        $this->client->answers['ar_classify'] = FinanceAiFailure::of(FinanceAiFailure::PROVIDER_UNAVAILABLE, 'The model provider is down.', 'corr-0123456789abcdef');

        $answer = $this->service()->scanFromWatermark(null);

        self::assertSame([1, 1], [$answer['new_records'], $answer['failed_enrichments']]);
        $record = $this->recordFor('imm-1');
        self::assertSame(['other', 'email', 'needs_review'], [$record->followUpCategory, $record->proposedResponseType, $record->followUpStatus]);
        self::assertNull($record->classificationConfidence);
        self::assertSame('A short summary.', $record->aiSummary, 'the prose call still ran');
        self::assertCount(1, $this->aiLog, 'the failure is logged by the AI service');
        self::assertStringNotContainsString('Please release', $this->aiLog[0]);
        self::assertStringContainsString('Jev error', (string) $this->scans->recent(1)[0]->errors);
    }

    public function test_a_failed_summary_forces_review_and_both_failing_uses_the_senders_name(): void
    {
        $this->add('imm-1', '2026-10-07 01:00:00');
        $this->client->answers['ar_prose'] = FinanceAiFailure::of(FinanceAiFailure::TIMEOUT, 'The AI service took too long.', 'corr-0123456789abcdef');

        $this->service()->scanFromWatermark(null);
        $record = $this->recordFor('imm-1');
        self::assertSame('needs_review', $record->followUpStatus, 'the classifier said in_progress, the missing summary overrides it');
        self::assertSame('release_customer_orders', $record->followUpCategory);
        self::assertSame('Pat Payer', $record->customerName, 'no summary, so the customer is the sender');

        $this->add('imm-2', '2026-10-07 02:00:00');
        $this->client->answers['ar_classify'] = FinanceAiFailure::of(FinanceAiFailure::TIMEOUT, 'The AI service took too long.', 'corr-0123456789abcdef');
        $this->service()->scanFromWatermark(null);
        $both = $this->recordFor('imm-2');
        self::assertSame(['other', 'email', 'needs_review', 'Pat Payer', ''], [$both->followUpCategory, $both->proposedResponseType, $both->followUpStatus, $both->customerName, $both->aiSummary]);
    }

    public function test_the_body_is_cut_to_twenty_thousand_characters_and_a_missing_subject_is_named(): void
    {
        $this->add('imm-1', '2026-10-07 01:00:00', '', str_repeat('x', 25000));

        $this->service()->scanFromWatermark(null);

        $record = $this->recordFor('imm-1');
        self::assertSame(20000, mb_strlen((string) $record->body));
        self::assertSame('(no subject)', $record->subject);
    }

    // ---- attachments --------------------------------------------------------------------

    public function test_supported_attachments_are_kept_and_remittance_advice_is_read(): void
    {
        $this->add('imm-1', '2026-10-07 01:00:00', 'Payment advice', '<p>Paid</p>', [
            self::file('a1', 'advice.pdf', '%PDF-1.4 invented', 40),
            self::file('a2', 'logo.png', 'tiny', 1000),
            self::file('a3', 'scan.PNG', 'png-bytes', 30000),
            self::file('a4', 'notes.docx', 'doc'),
            self::file('a5', 'banner.pdf', 'inline', 5000, inline: true),
            self::file('a6', 'cheque.tif', 'tif-bytes', 90000),
        ]);
        $this->client->answers['ar_classify'] = [
            'follow_up_category' => 'remittance_advice', 'proposed_response_type' => 'email', 'needs_review' => false,
            'classification_confidence' => 0.95, 'follow_up_status' => 'in_progress',
        ];

        $answer = $this->service()->scanFromWatermark(null);

        self::assertSame([3, 3, 0], [$answer['attachments_kept'], $answer['attachments_extracted'], $answer['attachment_failures']]);
        $record = $this->recordFor('imm-1');
        $rows = $this->store->attachmentsOf($record->id);
        self::assertSame(['advice.pdf', 'scan.PNG', 'cheque.tif'], array_column($rows, 'file_name'), 'a small image, a Word file and an inline file are not kept');
        foreach ($rows as $row) {
            self::assertSame('extracted', $row['extraction_status']);
            self::assertTrue($row['reconciles']);
            self::assertSame('AUD', $row['currency']);
            self::assertCount(2, FixturesView::list($row['line_items']));
        }
        self::assertCount(3, $this->files->forRecord('AR', 'email_record', $record->id));
        self::assertSame(3, $this->client->count('ar_remittance'));
        $last = end($this->client->calls);
        self::assertIsArray($last);
        $file = self::arr($last['data']['file'] ?? null);
        self::assertSame(base64_encode('tif-bytes'), $file['data']);
        self::assertSame('image/tiff', $file['content_type'], 'the type is decided from the extension, never from the sender');
    }

    public function test_other_mail_keeps_its_attachments_without_reading_them(): void
    {
        $this->add('imm-1', '2026-10-07 01:00:00', 'Order', '<p>Hi</p>', [self::file('a1', 'order.pdf', '%PDF invented')]);

        $answer = $this->service()->scanFromWatermark(null);

        self::assertSame([1, 0], [$answer['attachments_kept'], $answer['attachments_extracted']]);
        self::assertSame('skipped', $this->store->attachmentsOf($this->recordFor('imm-1')->id)[0]['extraction_status']);
        self::assertSame(0, $this->client->count('ar_remittance'));
    }

    public function test_a_failed_extraction_is_recorded_on_the_file_and_never_fails_the_scan(): void
    {
        $this->add('imm-1', '2026-10-07 01:00:00', 'Advice', '<p>Paid</p>', [self::file('a1', 'advice.pdf', '%PDF')]);
        $this->client->answers['ar_classify'] = [
            'follow_up_category' => 'remittance_advice', 'proposed_response_type' => 'email', 'needs_review' => false,
            'classification_confidence' => 0.95, 'follow_up_status' => 'in_progress',
        ];
        $this->client->answers['ar_remittance'] = FinanceAiFailure::of(FinanceAiFailure::BAD_MODEL_ANSWER, 'The model answered in a form this app cannot use.', 'corr-0123456789abcdef');

        $answer = $this->service()->scanFromWatermark(null);

        self::assertSame([1, 1, 0, 1], [$answer['new_records'], $answer['attachments_kept'], $answer['attachments_extracted'], $answer['attachment_failures']]);
        $row = $this->store->attachmentsOf($this->recordFor('imm-1')->id)[0];
        self::assertSame('failed', $row['extraction_status']);
        self::assertStringContainsString('model answered', self::str($row['extraction_error']));
        $run = $this->scans->recent(1)[0];
        self::assertSame(ArScanRun::COMPLETED, $run->status);
        self::assertNotNull($run->watermark, 'a file that could not be read does not block the watermark');
    }

    public function test_an_attachment_that_cannot_be_read_or_listed_is_counted_and_the_record_stays(): void
    {
        $this->add('imm-1', '2026-10-07 01:00:00', 'A', '<p>x</p>', [self::file('a1', 'one.pdf', '%PDF'), self::file('a2', 'two.pdf', '%PDF')]);
        $this->add('imm-2', '2026-10-07 02:00:00', 'B', '<p>y</p>', [self::file('b1', 'three.pdf', '%PDF')]);
        $this->reader->failAttachments = ['a1'];

        $answer = $this->service()->scanFromWatermark(null);
        self::assertSame([2, 2, 1], [$answer['new_records'], $answer['attachments_kept'], $answer['attachment_failures']]);

        $this->add('imm-3', '2026-10-07 03:00:00', 'C', '<p>z</p>', [self::file('c1', 'four.pdf', '%PDF')]);
        $this->reader->failAttachmentListing = true;
        $third = $this->service()->scanFromWatermark(null);
        self::assertSame([1, 0, 1], [$third['new_records'], $third['attachments_kept'], $third['attachment_failures']]);
        self::assertSame(0, $this->store->attachmentCount($this->recordFor('imm-3')->id));
    }

    // ---- the watermark ------------------------------------------------------------------

    public function test_the_watermark_moves_to_the_newest_message_of_an_unbroken_run(): void
    {
        $this->add('imm-1', '2026-10-06 01:00:00');
        $this->add('imm-2', '2026-10-06 05:00:00');
        $newest = $this->add('imm-3', '2026-10-07 03:00:00');

        $this->service()->scanFromWatermark(null);

        self::assertSame($newest->format('c'), $this->scans->latestWatermark(self::MAILBOX)?->format('c'));
    }

    public function test_a_message_that_cannot_be_read_blocks_the_watermark_for_the_rest_of_the_run(): void
    {
        $first = $this->add('imm-1', '2026-10-06 01:00:00');
        $this->add('imm-2', '2026-10-06 05:00:00');
        $this->add('imm-3', '2026-10-07 03:00:00');
        $this->reader->failMessages = ['imm-2'];

        $answer = $this->service()->scanFromWatermark(null);

        self::assertSame(2, $answer['new_records'], 'the messages after the broken one are still stored');
        self::assertSame($first->format('c'), $this->scans->latestWatermark(self::MAILBOX)?->format('c'), 'but the watermark stops before the one that failed');

        $this->reader->failMessages = [];
        $healed = $this->service()->scanFromWatermark(null);
        self::assertSame([1, 2], [$healed['new_records'], $healed['skipped_records']], 'the next run reads from the watermark (inclusive), finds the missed one and skips what is stored');
        self::assertSame(3, $this->recordCount());
        self::assertSame('2026-10-07T03:00:00+00:00', $this->scans->latestWatermark(self::MAILBOX)?->format('c'));
    }

    public function test_a_record_that_cannot_be_stored_blocks_the_watermark_and_the_log_names_no_content(): void
    {
        $first = $this->add('imm-1', '2026-10-06 01:00:00', 'Fine');
        $this->add('imm-2', '2026-10-06 05:00:00', 'Confidential subject');
        $this->add('imm-3', '2026-10-07 03:00:00', 'Also fine');
        $store = new class() extends InMemoryArStore {
            public function insert(ArRecord $record): void
            {
                if ($record->subject === 'Confidential subject') {
                    throw new RuntimeException('disk full while writing Confidential subject');
                }
                parent::insert($record);
            }
        };

        $answer = $this->service(500, $store)->scanFromWatermark(null);

        self::assertSame(2, $answer['new_records']);
        self::assertSame($first->format('c'), $this->scans->latestWatermark(self::MAILBOX)?->format('c'));
        $log = (string) file_get_contents($this->errorLogFile);
        self::assertStringContainsString('could not be stored', $log);
        self::assertStringNotContainsString('Confidential', $log);
    }

    public function test_a_period_scan_never_moves_the_watermark(): void
    {
        $this->seedWatermark('2026-10-01 00:00:00');
        $this->add('imm-1', '2026-10-05 01:00:00');
        $service = $this->service();

        $list = $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']);
        $service->handle($this->actor, ['mode' => 'process', 'messageId' => 'imm-1', 'scanRunId' => $list['scanRunId']]);
        $done = $service->handle($this->actor, ['mode' => 'complete', 'scanRunId' => $list['scanRunId']]);

        self::assertSame(ArScanRun::COMPLETED, self::arr($done['run'])['status']);
        self::assertNull(self::arr($done['run'])['watermark']);
        self::assertSame('2026-10-01T00:00:00+00:00', $this->scans->latestWatermark(self::MAILBOX)?->format('c'));
        // Even a client that sends a watermark cannot move it.
        $stopped = $service->handle($this->actor, ['mode' => 'complete', 'scanRunId' => $list['scanRunId'], 'watermark' => '2026-10-07T00:00:00Z']);
        self::assertNull(self::arr($stopped['run'])['watermark']);
        self::assertSame('2026-10-01T00:00:00+00:00', $this->scans->latestWatermark(self::MAILBOX)?->format('c'));
    }

    public function test_the_first_run_with_no_watermark_reads_the_last_seven_days_only(): void
    {
        $this->add('old', '2026-09-30 01:00:00');
        $this->add('recent', '2026-10-03 01:00:00');

        $answer = $this->service()->scanFromWatermark(null);

        self::assertSame(1, $answer['total_fetched']);
        $this->recordFor('recent');
        self::assertSame(1, $this->recordCount());
    }

    public function test_a_long_gap_is_read_in_slices_of_at_most_31_days(): void
    {
        $this->seedWatermark('2026-06-20 00:00:00');
        foreach (['2026-06-21 01:00:00', '2026-07-12 01:00:00', '2026-08-02 01:00:00', '2026-08-30 01:00:00', '2026-09-25 01:00:00', '2026-10-07 01:00:00'] as $n => $when) {
            $this->add('imm-' . $n, $when);
        }

        $answer = $this->service()->scanFromWatermark(null);

        self::assertSame(6, $answer['new_records'], 'nothing in the gap is skipped');
        self::assertGreaterThanOrEqual(4, count($this->reader->listings));
        foreach ($this->reader->listings as $listing) {
            $days = (strtotime($listing['to']) - strtotime($listing['from'])) / 86400;
            self::assertLessThanOrEqual(31, $days);
        }
    }

    public function test_a_slice_that_fills_the_listing_cap_is_split_until_it_fits(): void
    {
        for ($n = 0; $n < 8; ++$n) {
            $this->add('imm-' . $n, sprintf('2026-10-06 10:%02d:00', $n * 7));
        }

        $answer = $this->service(3)->scanFromWatermark(null);

        self::assertSame(8, $answer['total_fetched'], 'the oldest mail is not dropped behind the newest');
        self::assertSame(8, $answer['new_records']);
        self::assertGreaterThan(1, count($this->reader->listings));
    }

    // ---- the three modes ----------------------------------------------------------------

    public function test_list_returns_the_inbox_messages_of_the_melbourne_days_and_opens_a_run(): void
    {
        // Melbourne is UTC+11 on these days: 5 Oct 00:00 is 4 Oct 13:00Z and the end of the 6th is 6 Oct 13:00Z.
        $this->add('before', '2026-10-04 12:00:00');
        $this->add('first', '2026-10-04 13:30:00', 'First');
        $this->add('last', '2026-10-06 12:30:00', 'Last');
        $this->add('after', '2026-10-06 13:30:00');
        $this->add('sent', '2026-10-05 01:00:00', folder: 'sentitems');

        $answer = $this->service()->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-06']);

        self::assertFalse($answer['truncated']);
        $messages = ArFixtures::rows($answer['messages']);
        self::assertSame(['last', 'first'], array_column($messages, 'id'), 'newest first, as the reader returns them');
        self::assertSame(['id', 'subject', 'from', 'receivedDateTime', 'hasAttachments'], array_keys($messages[0]));
        self::assertSame('2026-10-06T12:30:00Z', $messages[0]['receivedDateTime']);
        $run = $this->runOf(self::str($answer['scanRunId']));
        self::assertSame([ArScanRun::RUNNING, '2026-10-05', '2026-10-06', 2, 'Olive Operator'], [$run->status, $run->fromDate, $run->toDate, $run->totalMessages, $run->requestedBy]);
        self::assertSame(0, $this->recordCount(), 'listing stores nothing');
    }

    public function test_process_ingests_one_message_and_counts_it_on_the_run_and_a_repeat_is_a_skip(): void
    {
        $this->add('imm-1', '2026-10-05 01:00:00');
        $service = $this->service();
        $list = $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']);

        $first = $service->handle($this->actor, ['mode' => 'process', 'messageId' => 'imm-1', 'scanRunId' => $list['scanRunId']]);
        $again = $service->handle($this->actor, ['mode' => 'process', 'messageId' => 'imm-1', 'scanRunId' => $list['scanRunId']]);

        self::assertSame([1, 0, 0, 'release_customer_orders', '2026-10-05T01:00:00Z'], [$first['newRecords'], $first['skipped'], $first['errors'], $first['category'], $first['receivedAt']]);
        self::assertSame([0, 1], [$again['newRecords'], $again['skipped']]);
        $run = $this->runOf(self::str($list['scanRunId']));
        self::assertSame([2, 1, 1], [$run->processedMessages, $run->newRecords, $run->skippedRecords]);
        self::assertSame(1, $this->recordCount());
    }

    public function test_process_without_a_run_still_ingests_and_a_missing_message_id_is_refused(): void
    {
        $this->add('imm-1', '2026-10-05 01:00:00');
        $service = $this->service();

        self::assertSame(1, $service->handle($this->actor, ['mode' => 'process', 'messageId' => 'imm-1'])['newRecords']);
        $this->expectException(ArInvalid::class);
        $this->expectExceptionMessage('messageId is required');
        $service->handle($this->actor, ['mode' => 'process']);
    }

    public function test_a_message_graph_cannot_give_is_a_typed_502_and_counts_on_the_run(): void
    {
        $this->add('imm-1', '2026-10-05 01:00:00');
        $this->reader->failMessages = ['imm-1'];
        $service = $this->service();
        $list = $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']);

        try {
            $service->handle($this->actor, ['mode' => 'process', 'messageId' => 'imm-1', 'scanRunId' => $list['scanRunId']]);
            self::fail('Expected a 502.');
        } catch (ArUpstream $upstream) {
            self::assertSame([502, 'FINANCE_AR_UPSTREAM_FAILED', ['reason' => 'mailbox']], [$upstream->httpStatus(), $upstream->errorCode(), $upstream->extra]);
            self::assertStringStartsWith('Graph fetch failed: ', $upstream->getMessage());
        }
        $run = $this->runOf(self::str($list['scanRunId']));
        self::assertSame([1, 1], [$run->processedMessages, $run->failedEnrichments]);
        self::assertSame(0, $this->recordCount());
    }

    public function test_complete_ends_the_run_as_completed_or_stopped_and_an_unknown_run_is_a_404(): void
    {
        $service = $this->service();
        $a = $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-01', 'toDate' => '2026-10-01']);
        self::assertSame(ArScanRun::COMPLETED, self::arr($service->handle($this->actor, ['mode' => 'complete', 'scanRunId' => $a['scanRunId']])['run'])['status']);
        $b = $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-02', 'toDate' => '2026-10-02']);
        $stopped = $service->handle($this->actor, ['mode' => 'complete', 'scanRunId' => $b['scanRunId'], 'stopped' => true]);
        self::assertSame(ArScanRun::STOPPED, self::arr($stopped['run'])['status']);
        self::assertNotNull(self::arr($stopped['run'])['finished_at']);

        try {
            $service->handle($this->actor, ['mode' => 'complete', 'scanRunId' => 'nope']);
            self::fail('Expected a 404.');
        } catch (ArNotFound $notFound) {
            self::assertSame('Scan run not found', $notFound->getMessage());
        }
    }

    public function test_a_period_already_scanned_is_a_409_with_the_earlier_runs_until_it_is_confirmed(): void
    {
        $service = $this->service();
        $first = $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-01', 'toDate' => '2026-10-03']);
        $service->handle($this->actor, ['mode' => 'complete', 'scanRunId' => $first['scanRunId']]);

        try {
            $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-03', 'toDate' => '2026-10-05']);
            self::fail('Expected a 409.');
        } catch (ArAlreadyScanned $already) {
            self::assertSame([409, 'FINANCE_AR_ALREADY_SCANNED'], [$already->httpStatus(), $already->errorCode()]);
            $prior = $already->extra['priorRuns'];
            self::assertIsArray($prior);
            self::assertCount(1, $prior);
            self::assertIsArray($prior[0]);
            self::assertSame([$first['scanRunId'], '2026-10-01', '2026-10-03', 'Olive Operator', 'completed'], [$prior[0]['id'], $prior[0]['fromDate'], $prior[0]['toDate'], $prior[0]['scannedBy'], $prior[0]['status']]);
        }
        // A period that does not overlap is fine, and so is a confirmed one.
        self::assertArrayHasKey('scanRunId', $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-04', 'toDate' => '2026-10-04']));
        $service->handle($this->actor, ['mode' => 'complete', 'scanRunId' => $this->scans->recent(1)[0]->id]);
        self::assertArrayHasKey('scanRunId', $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-01', 'toDate' => '2026-10-03', 'confirmRescan' => true]));
    }

    public function test_bad_input_is_refused_in_the_sources_words(): void
    {
        $service = $this->service();
        $cases = [
            [['mode' => 'list'], 'fromDate and toDate are required'],
            [['mode' => 'list', 'fromDate' => '2026-10-05'], 'fromDate and toDate are required'],
            [['mode' => 'list', 'fromDate' => '2026-10-06', 'toDate' => '2026-10-05'], 'From date cannot be after to date'],
            [['mode' => 'list', 'fromDate' => '05/10/2026', 'toDate' => '2026-10-05'], 'Dates must use YYYY-MM-DD'],
            [['mode' => 'sideways'], 'Unknown mode: sideways'],
        ];
        foreach ($cases as [$body, $message]) {
            try {
                $service->handle($this->actor, $body);
                self::fail('Expected a 422 for ' . $message);
            } catch (ArInvalid $invalid) {
                self::assertSame($message, $invalid->getMessage());
            }
        }
        self::assertSame([], $this->scans->recent(10), 'a refused request opens no run');
    }

    public function test_no_mode_is_the_scan_from_the_watermark(): void
    {
        $this->add('imm-1', '2026-10-07 01:00:00');

        $answer = $this->service()->handle($this->actor, []);

        self::assertSame(1, $answer['new_records']);
        self::assertSame('Olive Operator', $this->scans->recent(1)[0]->requestedBy);
        self::assertSame(7, $this->scans->recent(1)[0]->requestedByUserId);
    }

    // ---- the claim ----------------------------------------------------------------------

    public function test_a_scan_is_refused_while_another_holds_the_mailbox_and_a_dead_one_is_failed_when_its_claim_expires(): void
    {
        $service = $this->service();
        $held = $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']);
        $listings = count($this->reader->listings);

        try {
            $service->scanFromWatermark(null);
            self::fail('Expected a conflict.');
        } catch (ArConflict $busy) {
            self::assertSame('A scan of this mailbox is already running', $busy->getMessage());
        }
        self::assertSame($listings, count($this->reader->listings), 'a refused scan reads nothing');

        $this->clock->advance('+31 minutes');
        $service->scanFromWatermark(null);
        $old = $this->runOf(self::str($held['scanRunId']));
        self::assertSame(ArScanRun::FAILED, $old->status);
        self::assertSame('The scan stopped without finishing', $old->errors);
    }

    public function test_each_message_renews_the_claim(): void
    {
        $this->add('imm-1', '2026-10-05 01:00:00');
        $this->add('imm-2', '2026-10-05 02:00:00');
        $service = $this->service();
        $list = $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']);

        $this->clock->advance('+20 minutes');
        $service->handle($this->actor, ['mode' => 'process', 'messageId' => 'imm-1', 'scanRunId' => $list['scanRunId']]);
        $this->clock->advance('+20 minutes');

        $this->expectException(ArConflict::class);
        $service->scanFromWatermark(null);
    }

    public function test_a_finished_run_releases_the_mailbox(): void
    {
        $service = $this->service();
        $service->scanFromWatermark(null);
        $service->scanFromWatermark(null);

        self::assertCount(2, $this->scans->recent(10));
    }

    // ---- refusals -----------------------------------------------------------------------

    public function test_without_a_read_grant_the_scan_is_a_403_and_the_run_is_failed(): void
    {
        $this->reader = $this->readerFor($this->mailbox, granted: false);

        try {
            $this->service()->scanFromWatermark(null);
            self::fail('Expected a 403.');
        } catch (ArForbidden) {
            $run = $this->scans->recent(1)[0];
            self::assertSame(ArScanRun::FAILED, $run->status);
            self::assertNull($run->claimExpiresAt);
        }

        // The failed run released the mailbox: with the grant in place the next scan starts.
        $this->reader = $this->readerFor($this->mailbox);
        self::assertSame(0, $this->service()->scanFromWatermark(null)['total_fetched']);
    }

    public function test_graph_being_down_is_a_typed_502_the_run_is_failed_and_the_watermark_is_kept(): void
    {
        $this->seedWatermark('2026-10-01 00:00:00');
        $this->reader->failListing = true;

        try {
            $this->service()->scanFromWatermark(null);
            self::fail('Expected a 502.');
        } catch (ArUpstream $upstream) {
            self::assertSame('mailbox', $upstream->extra['reason']);
        }

        self::assertSame(ArScanRun::FAILED, $this->scans->recent(1)[0]->status);
        self::assertSame('2026-10-01T00:00:00+00:00', $this->scans->latestWatermark(self::MAILBOX)?->format('c'));
    }

    // ---- run history --------------------------------------------------------------------

    public function test_runs_are_listed_newest_first_with_the_sources_keys_and_a_bounded_limit(): void
    {
        $service = $this->service();
        for ($n = 0; $n < 3; ++$n) {
            $service->scanFromWatermark(null);
            $this->clock->advance('+1 hour');
        }

        $all = ArFixtures::rows($service->runs(null)['runs']);
        self::assertCount(3, $all);
        self::assertSame(
            ['id', 'started_at', 'finished_at', 'mailbox', 'status', 'watermark', 'total_fetched', 'new_records', 'skipped_records', 'failed_enrichments', 'errors', 'from_date', 'to_date', 'requested_by', 'total_messages', 'processed_messages'],
            array_keys($all[0]),
        );
        self::assertGreaterThan($all[1]['started_at'], $all[0]['started_at']);
        self::assertCount(1, ArFixtures::rows($service->runs(0)['runs']), 'a limit below 1 is 1');
        self::assertCount(2, ArFixtures::rows($service->runs('2')['runs']));
        self::assertCount(3, ArFixtures::rows($service->runs('nonsense')['runs']), 'a non-number is the default');
    }

    // ---- the job ------------------------------------------------------------------------

    public function test_the_job_is_the_daily_08_00_scan_with_no_person_and_reports_counts_only(): void
    {
        $this->add('imm-1', '2026-10-07 01:00:00', 'Secret subject');
        $job = new ArMailboxScanJob($this->service());

        self::assertSame('ar.mailbox-scan', $job->name());
        self::assertEquals(FinanceJobSchedule::dailyAt('08:00'), $job->schedule());
        $facts = $job->run(self::utc(self::NOW));

        self::assertSame(1, $facts['new']);
        self::assertSame(1, $facts['fetched']);
        self::assertStringNotContainsString('Secret', json_encode($facts, JSON_THROW_ON_ERROR));
        self::assertSame('Scheduled scan', $this->scans->recent(1)[0]->requestedBy);
        self::assertNull($this->scans->recent(1)[0]->requestedByUserId);
        self::assertSame(0, $job->run(self::utc(self::NOW))['new'], 'a repeat in the same slot adds nothing');
    }

    public function test_the_job_skips_a_pass_while_an_operators_scan_holds_the_mailbox(): void
    {
        $service = $this->service();
        $service->handle($this->actor, ['mode' => 'list', 'fromDate' => '2026-10-05', 'toDate' => '2026-10-05']);

        self::assertSame(['skipped' => 'another scan is running'], (new ArMailboxScanJob($service))->run(self::utc(self::NOW)));
    }
}
