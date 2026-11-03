<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Mail\Drafts\EmailEnvelopeGatherer;
use TriCab\Api\Mail\Drafts\RfqEmailFollowupProposer;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Core\Mail\Application\DTO\AttachmentDownload;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\MailBodyFetcher;
use TriCab\Core\Mail\Contract\RfqFollowupProposer;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Rfq\Application\DTO\ReaderResult;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;
use TriCab\Core\Rfq\Contract\RfqDocumentStore;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;

/**
 * RFQ mail M6.2 over the in-memory stores: a follow-up's rows become a proposal on the draft that is open, once per
 * message, and never touch the draft.
 */
final class RfqEmailFollowupProposerTest extends TestCase
{
    private const DRAFT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private InMemoryMailStore $store;

    private FixtureRfqParserClient $parser;

    private FollowupReaderJobStore $jobs;

    private RfqEmailFollowupProposer $proposer;

    /** @var array<string, StoredDraft> */
    private array $drafts = [];

    private int $envelopeFetches = 0;

    protected function setUp(): void
    {
        $this->store = new InMemoryMailStore();
        $this->parser = new FixtureRfqParserClient();
        $this->jobs = new FollowupReaderJobStore();
        $this->drafts[self::DRAFT_ID] = new StoredDraft(self::DRAFT_ID, 4, 'in_progress', [], [], [], [], []);
        $test = $this;
        $drafts = new class ($test) implements RfqDraftStore {
            public function __construct(private readonly RfqEmailFollowupProposerTest $test)
            {
            }

            public function create(NewDraft $draft): string
            {
                throw new \LogicException('a follow-up must never make a draft');
            }

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, \TriCab\Core\Rfq\Storage\DraftActor $actor, string $viewMode = ''): ?int
            {
                throw new \LogicException('a follow-up must never save the draft');
            }

            public function load(string $draftId): ?StoredDraft
            {
                return $this->test->draftFor($draftId);
            }
        };
        $documents = $this->createStub(RfqDocumentStore::class);
        $operators = new class () implements RfqOperatorDirectory {
            public function codeFor(string $email): ?string
            {
                return null;
            }
        };
        $rfqs = new RfqReaderService($this->parser, $drafts, $this->jobs, $documents, new InMemoryRfqRepository(), $operators, new FixedClock(new DateTimeImmutable('2026-11-03 00:00:00 UTC')));
        $fetcher = new class ($test) implements MailBodyFetcher {
            public function __construct(private readonly RfqEmailFollowupProposerTest $test)
            {
            }

            public function body(string $mailboxAddress, string $graphMessageId): array
            {
                return ['type' => 'html', 'content' => '<p>Also 50m of 4C 1.5mm</p>'];
            }

            public function envelopeSource(string $mailboxAddress, string $graphMessageId): array
            {
                $this->test->fetched();

                return $this->body($mailboxAddress, $graphMessageId) + ['to' => [], 'cc' => [], 'attachments' => []];
            }

            public function attachment(string $mailboxAddress, string $graphMessageId, string $graphAttachmentId): AttachmentDownload
            {
                throw new \LogicException('no attachments');
            }
        };
        $this->proposer = new RfqEmailFollowupProposer($this->store, new EmailEnvelopeGatherer($fetcher), $rfqs, MailFixtures::MAILBOX);
    }

    /** @internal */
    public function draftFor(string $id): ?StoredDraft
    {
        return $this->drafts[$id] ?? null;
    }

    /** @internal */
    public function fetched(): void
    {
        ++$this->envelopeFetches;
    }

    /** @return array{threadId: string, messageId: string} */
    private function followup(string $key = 'b'): array
    {
        $recorded = MailFixtures::classified(new MailIntakeRecorder($this->store), $key, 'rfq_followup', conversation: 'conv-1', at: '2026-11-02 09:00:00', fromName: 'Jo Customer');

        return ['threadId' => $recorded->threadId, 'messageId' => $recorded->messageId];
    }

    public function test_the_followups_rows_become_a_proposal_that_is_never_applied(): void
    {
        $m = $this->followup();

        $result = $this->proposer->propose($m['threadId'], $m['messageId'], StoreProjection::publicId(self::DRAFT_ID));

        self::assertSame(RfqFollowupProposer::PROPOSED, $result);
        self::assertCount(1, $this->jobs->jobs);
        $job = array_values($this->jobs->jobs)[0];
        self::assertSame(self::DRAFT_ID, $job->draftId);
        self::assertSame(4, $job->baseRevision, 'against the revision the draft is at');
        self::assertIsArray($job->proposal);
        /** @var array<string, mixed> $proposal */
        $proposal = $job->proposal;
        $rows = $proposal['rows'];
        $info = $proposal['email'];
        self::assertIsArray($rows);
        self::assertIsArray($info);
        self::assertSame('proposed', $job->readerStatus()->toString());
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsArray($row['source']);
            self::assertSame($job->fileName, $row['source']['file_name'], 'its own file name, so accepting appends and never splices over another file');
            self::assertNotSame('', $row['line_uid']);
        }
        self::assertSame('jo@customer.example', $info['sender_address']);
        self::assertSame($m['threadId'], $info['mail_thread_id']);
        self::assertSame($m['messageId'], $job->emailMessageId);
        self::assertStringContainsString('Jo Customer', $job->fileName);
        self::assertSame([false], $this->jobs->refreshes, 'a waiting proposal stops the draft saying ready');
        self::assertSame(['email_followup_proposal'], array_column($this->jobs->events, 'type'));
    }

    public function test_the_editor_gets_the_sender_and_message_on_the_read(): void
    {
        $m = $this->followup();
        $this->proposer->propose($m['threadId'], $m['messageId'], StoreProjection::publicId(self::DRAFT_ID));

        $read = ReaderResult::fromStored(array_values($this->jobs->jobs)[0])->toArray();
        $email = $read['email'];
        self::assertIsArray($email);

        self::assertSame('proposed', $read['status']);
        self::assertSame('Jo Customer', $email['senderName']);
        self::assertSame($m['threadId'], $email['threadId']);
        self::assertSame($m['messageId'], $email['messageId']);
        self::assertGreaterThan(0, $read['proposedLineCount']);
    }

    public function test_a_redelivery_makes_no_second_proposal_and_asks_exchange_for_nothing(): void
    {
        $m = $this->followup();
        $draft = StoreProjection::publicId(self::DRAFT_ID);

        $this->proposer->propose($m['threadId'], $m['messageId'], $draft);
        $parses = count($this->parser->parses);
        $again = $this->proposer->propose($m['threadId'], $m['messageId'], $draft);

        self::assertSame(RfqFollowupProposer::EXISTS, $again);
        self::assertCount(1, $this->jobs->jobs);
        self::assertSame(1, $this->envelopeFetches);
        self::assertCount($parses, $this->parser->parses);
    }

    public function test_two_followups_are_two_proposals(): void
    {
        $a = $this->followup('b');
        $b = $this->followup('c');
        $draft = StoreProjection::publicId(self::DRAFT_ID);

        $this->proposer->propose($a['threadId'], $a['messageId'], $draft);
        $this->proposer->propose($b['threadId'], $b['messageId'], $draft);

        self::assertCount(2, $this->jobs->jobs);
    }

    public function test_a_draft_that_is_gone_or_archived_gets_nothing(): void
    {
        $m = $this->followup();
        $this->drafts[self::DRAFT_ID] = new StoredDraft(self::DRAFT_ID, 4, 'in_progress', ['archived_at' => '2026-11-01T00:00:00'], [], [], [], []);

        self::assertSame(RfqFollowupProposer::NO_DRAFT, $this->proposer->propose($m['threadId'], $m['messageId'], StoreProjection::publicId(self::DRAFT_ID)));
        self::assertSame(RfqFollowupProposer::NO_DRAFT, $this->proposer->propose($m['threadId'], $m['messageId'], StoreProjection::publicId('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')));
        self::assertSame([], $this->jobs->jobs);
    }

    public function test_an_email_with_no_lines_is_recorded_once_as_nothing_to_resolve(): void
    {
        $m = $this->followup();
        $this->parser->answerOverride = ['lines' => []];

        $result = $this->proposer->propose($m['threadId'], $m['messageId'], StoreProjection::publicId(self::DRAFT_ID));

        self::assertSame(RfqFollowupProposer::EMPTY, $result);
        $job = array_values($this->jobs->jobs)[0];
        self::assertNull($job->proposal);
        self::assertFalse($job->readerStatus()->needsAttention());
        self::assertSame([], $this->jobs->refreshes);
        self::assertSame(RfqFollowupProposer::EXISTS, $this->proposer->propose($m['threadId'], $m['messageId'], StoreProjection::publicId(self::DRAFT_ID)));
    }

    public function test_a_parser_outage_is_unavailable_and_records_nothing(): void
    {
        $m = $this->followup();
        $this->parser->parseFailure = RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'down', CorrelationId::generate());

        $this->expectException(MailUnavailable::class);
        try {
            $this->proposer->propose($m['threadId'], $m['messageId'], StoreProjection::publicId(self::DRAFT_ID));
        } finally {
            self::assertSame([], $this->jobs->jobs);
        }
    }
}
