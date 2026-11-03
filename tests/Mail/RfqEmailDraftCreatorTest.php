<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Mail;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Identity\Persistence\InMemoryApiUserRepository;
use TriCab\Api\Mail\Drafts\RfqEmailDraftCreator;
use TriCab\Api\Mail\Persistence\InMemoryMailStore;
use TriCab\Core\Identity\Domain\Service\EmailOtpExternalIdentityFactory;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserStatus;
use TriCab\Core\Mail\Application\DTO\AttachmentDownload;
use TriCab\Core\Mail\Application\DTO\NewAttachment;
use TriCab\Core\Mail\Application\Service\MailIntakeRecorder;
use TriCab\Core\Mail\Contract\MailBodyFetcher;
use TriCab\Core\Mail\Domain\Exception\MailNotFound;
use TriCab\Core\Mail\Domain\Exception\MailUnavailable;
use TriCab\Core\Mail\Domain\MailActor;
use TriCab\Core\Rfq\Application\DTO\SourceDocument;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;
use TriCab\Core\Rfq\Contract\RfqDocumentStore;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Contract\RfqReaderJobStore;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxRecipient;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;

/**
 * The creator both the reader and "Create draft" use, over the real in-memory mail store and RFQ intake with
 * in-memory RFQ stores: what documents it gathers from Exchange, who owns the draft, what the draft records of the
 * email, and that one message is one draft.
 */
final class RfqEmailDraftCreatorTest extends TestCase
{
    private InMemoryMailStore $store;

    private FixtureRfqParserClient $parser;

    /** @var array<string, NewDraft> */
    private array $drafts = [];

    /** @var list<string> */
    private array $documentNames = [];

    private RfqEmailDraftCreator $creator;

    private RfqReaderService $rfqs;

    /** @var array<string, AttachmentDownload|MailNotFound> keyed by graph attachment id */
    private array $files = [];

    private string $html = '<p>Please quote 100m of 3C 2.5mm</p>';

    /** @var list<MailboxAttachmentSummary> what Graph lists on the message */
    private array $listed = [];

    private ?MailUnavailable $bodyFailure = null;

    /** @var list<string> */
    private array $fetched = [];

    protected function setUp(): void
    {
        $this->store = new InMemoryMailStore();
        $this->parser = new FixtureRfqParserClient();
        $tests = $this;
        $drafts = new class ($tests) implements RfqDraftStore {
            public function __construct(private readonly RfqEmailDraftCreatorTest $test)
            {
            }

            public function create(NewDraft $draft): string
            {
                return $this->test->insert($draft);
            }

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, \TriCab\Core\Rfq\Storage\DraftActor $actor, string $viewMode = ''): ?int
            {
                return null;
            }

            public function load(string $draftId): ?StoredDraft
            {
                $draft = $this->test->draft($draftId);

                return $draft === null ? null : new StoredDraft($draftId, 1, 'new', [], $draft->quoteDetails, $draft->emailMeta ?? [], $draft->rows, []);
            }
        };
        $documents = new class ($tests) implements RfqDocumentStore {
            public function __construct(private readonly RfqEmailDraftCreatorTest $test)
            {
            }

            public function store(string $draftId, string $fileName, string $contentType, string $bytes, string $uploaderEmail): SourceDocument
            {
                $this->test->keepDocument($fileName);

                return new SourceDocument('00000000-0000-4000-8000-000000000001', SourceDocument::KIND_FILE, $fileName, $contentType, strlen($bytes), '', new DateTimeImmutable());
            }

            public function bytes(string $draftId, string $documentId): ?string
            {
                return null;
            }

            public function find(string $draftId, string $documentId): ?SourceDocument
            {
                return null;
            }

            public function forDraft(string $draftId): array
            {
                return [];
            }
        };
        $operators = new class () implements RfqOperatorDirectory {
            public function codeFor(string $email): ?string
            {
                return null;
            }
        };
        $this->rfqs = $rfqs = new RfqReaderService($this->parser, $drafts, $this->createStub(RfqReaderJobStore::class), $documents, new InMemoryRfqRepository(), $operators, new FixedClock(new DateTimeImmutable('2026-11-03 00:00:00 UTC')));

        $users = new InMemoryApiUserRepository();
        $users->register(
            EmailAddress::fromString(MailFixtures::MAILBOX),
            'TriCab Melbourne',
            UserStatus::active(),
            [RoleCode::fromString('RFQ_SALES')],
            array_map(static fn (string $c): PermissionCode => PermissionCode::fromString($c), Module::ROLE_PERMISSIONS[Module::ROLE_SALES]),
            EmailOtpExternalIdentityFactory::forEmail(EmailAddress::fromString('melbourne-seed@tricab.com')),
        );

        $this->creator = new RfqEmailDraftCreator($this->store, $this->fetcher(), $rfqs, $users, MailFixtures::MAILBOX, 'VIC');
    }

    /** @internal */
    public function insert(NewDraft $draft): string
    {
        $id = $draft->id ?? 'ffffffffffffffffffffffffffffffff';
        if (isset($this->drafts[$id])) {
            throw new \RuntimeException('Violation of PRIMARY KEY constraint');
        }
        $this->drafts[$id] = $draft;

        return $id;
    }

    /** @internal */
    public function draft(string $id): ?NewDraft
    {
        return $this->drafts[$id] ?? null;
    }

    /** @internal */
    public function keepDocument(string $name): void
    {
        $this->documentNames[] = $name;
    }

    private function fetcher(): MailBodyFetcher
    {
        $test = $this;

        return new class ($test) implements MailBodyFetcher {
            public function __construct(private readonly RfqEmailDraftCreatorTest $test)
            {
            }

            public function body(string $mailboxAddress, string $graphMessageId): array
            {
                return $this->test->body();
            }

            public function envelopeSource(string $mailboxAddress, string $graphMessageId): array
            {
                return $this->test->source();
            }

            public function attachment(string $mailboxAddress, string $graphMessageId, string $graphAttachmentId): AttachmentDownload
            {
                return $this->test->attachment($graphAttachmentId);
            }
        };
    }

    /**
     * @internal
     *
     * @return array{type: string, content: string}
     */
    public function body(): array
    {
        if ($this->bodyFailure !== null) {
            throw $this->bodyFailure;
        }

        return ['type' => 'html', 'content' => $this->html];
    }

    /**
     * @internal
     *
     * @return array{type: string, content: string, to: list<MailboxRecipient>, cc: list<MailboxRecipient>, attachments: list<MailboxAttachmentSummary>}
     */
    public function source(): array
    {
        return $this->body() + ['to' => [new MailboxRecipient('melbourne@tricab.com', 'TriCab Melbourne')], 'cc' => [], 'attachments' => $this->listed];
    }

    /** @internal */
    public function attachment(string $graphAttachmentId): AttachmentDownload
    {
        $this->fetched[] = $graphAttachmentId;
        $file = $this->files[$graphAttachmentId] ?? new MailNotFound('gone');
        if ($file instanceof MailNotFound) {
            throw $file;
        }

        return $file;
    }

    /**
     * @param list<NewAttachment> $attachments
     *
     * @return array{threadId: string, messageId: string}
     */
    private function receive(string $key, array $attachments = [], string $label = 'rfq'): array
    {
        $recorded = MailFixtures::classified(new MailIntakeRecorder($this->store), $key, $label, attachments: $attachments);
        $this->listed = array_map(static fn (NewAttachment $a): MailboxAttachmentSummary => new MailboxAttachmentSummary($a->graphAttachmentId, $a->fileName, $a->mimeType, $a->sizeBytes, $a->isInline), $attachments);

        return ['threadId' => $recorded->threadId, 'messageId' => $recorded->messageId];
    }

    /**
     * The attachments of the nth parse's envelope.
     *
     * @return list<array<mixed, mixed>>
     */
    private function sentDocuments(int $parse): array
    {
        $documents = $this->parser->parses[$parse]->email['documents'] ?? [];
        self::assertIsArray($documents);

        return array_values(array_filter($documents, 'is_array'));
    }

    private function actor(): MailActor
    {
        return new MailActor(5, 'Sam Reviewer', 'sam@tricab.com', ['MAIL.ACCESS', 'MAIL.REVIEW']);
    }

    public function test_the_draft_is_the_email_body_and_its_readable_attachments_owned_by_the_mailbox_user(): void
    {
        $m = $this->receive('a', [
            new NewAttachment('g-xlsx', 'Schedule.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 100, false),
            new NewAttachment('g-sig', 'logo.gif', 'image/gif', 50, false),
            new NewAttachment('g-zip', 'plans.zip', 'application/zip', 50, false),
        ]);
        $this->files['g-xlsx'] = new AttachmentDownload('Schedule.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx bytes');

        $made = $this->creator->createFromThread($this->actor(), $m['threadId'], 'ignored subject', $m['messageId']);

        self::assertCount(1, $this->drafts);
        $id = RfqEmailDraftCreator::draftIdFor(MailFixtures::MAILBOX, '<a@customer.example>');
        $draft = $this->drafts[$id] ?? null;
        self::assertNotNull($draft);
        self::assertSame('email', $draft->source);
        self::assertSame('melbourne@tricab.com', $draft->owner());
        self::assertSame('melbourne@tricab.com', $draft->assignee());
        self::assertSame('VIC', $draft->ownerBranch());
        self::assertSame('<a@customer.example>', $draft->emailMeta['internet_message_id'] ?? null);
        self::assertSame(['name' => 'Jo Customer', 'address' => 'jo@customer.example'], $draft->emailMeta['original_sender'] ?? null);
        self::assertSame('sam@tricab.com', $draft->emailMeta['draft_requested_by'] ?? null);
        self::assertSame($m['threadId'], $draft->emailMeta['mail_thread_id'] ?? null);
        self::assertSame(['logo.gif: not a format the parser reads', 'plans.zip: not a format the parser reads'], $draft->emailMeta['attachments_not_read'] ?? null);
        $envelope = $this->parser->parses[0]->email;
        self::assertIsArray($envelope);
        self::assertSame([], $this->parser->parses[0]->documents, 'the email is parsed as an envelope, not as loose files');
        self::assertSame('<a@customer.example>', $envelope['internetMessageId'] ?? null);
        self::assertSame(['contentType' => 'html', 'content' => '<p>Please quote 100m of 3C 2.5mm</p>'], $envelope['body'] ?? null, 'the body goes untouched; the parser flattens it');
        self::assertSame('melbourne@tricab.com', $envelope['mailbox'] ?? null);
        self::assertSame([['name' => 'TriCab Melbourne', 'address' => 'melbourne@tricab.com']], $envelope['toRecipients'] ?? null);
        self::assertSame(['Schedule.xlsx'], array_column($this->sentDocuments(0), 'fileName'));
        self::assertSame(['email-2'], array_column($this->sentDocuments(0), 'documentPublicId'));
        self::assertSame(['email-body.txt', 'Schedule.xlsx'], $this->documentNames);
        self::assertSame(\TriCab\Core\Rfq\Storage\StoreProjection::publicId($id), $made->publicId);
        self::assertSame('RFQ-' . strtoupper(substr($id, 0, 8)), $made->reference);
        self::assertSame(['g-xlsx'], $this->fetched, 'only the file the parser reads was downloaded');
    }

    public function test_the_draft_id_is_a_function_of_the_message_alone(): void
    {
        $a = RfqEmailDraftCreator::draftIdFor('Melbourne@tricab.com', ' <x@y> ');

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);
        self::assertSame($a, RfqEmailDraftCreator::draftIdFor('melbourne@tricab.com', '<x@y>'));
        self::assertNotSame($a, RfqEmailDraftCreator::draftIdFor('melbourne@tricab.com', '<z@y>'));
    }

    public function test_asking_twice_makes_one_draft_and_a_thread_with_a_draft_returns_it(): void
    {
        $m = $this->receive('a');

        $first = $this->creator->createFromThread($this->actor(), $m['threadId'], 's', $m['messageId']);
        $second = $this->creator->createFromThread($this->actor(), $m['threadId'], 's', $m['messageId']);

        self::assertSame($first->publicId, $second->publicId);
        self::assertCount(1, $this->drafts);
        self::assertCount(1, $this->parser->parses);

        // Linked, as the reader and the review service do after it: now the thread answers from its link.
        $this->store->recordDraft($m['threadId'], new \TriCab\Core\Mail\Application\DTO\NewLink($first->publicId, $first->reference, $first->title, null, new DateTimeImmutable('2026-11-03 00:00:00 UTC'), $m['messageId']), new \TriCab\Core\Mail\Application\DTO\MailParty('x', 'x@x'));
        $third = $this->creator->createFromThread($this->actor(), $m['threadId'], 's');
        self::assertSame($first->publicId, $third->publicId);
        self::assertCount(1, $this->parser->parses, 'no Graph and no parse for a thread that has its draft');
    }

    public function test_the_thread_assessment_message_is_used_when_none_is_named(): void
    {
        $m = $this->receive('a');

        $this->creator->createFromThread($this->actor(), $m['threadId'], 's');

        self::assertSame(RfqEmailDraftCreator::draftIdFor(MailFixtures::MAILBOX, '<a@customer.example>'), array_key_first($this->drafts));
    }

    public function test_inline_pictures_are_sent_in_the_envelope_and_decoration_is_not_downloaded(): void
    {
        $m = $this->receive('a', [
            new NewAttachment('g-logo', 'logo.png', 'image/png', 4_000, true),
            new NewAttachment('g-paste', 'image001.png', 'image/png', 90_000, true),
            new NewAttachment('g-gif', 'sig.gif', 'image/gif', 90_000, true),
            new NewAttachment('g-xlsx', 'Schedule.xlsx', 'application/octet-stream', 100, false),
        ]);
        $this->files['g-paste'] = new AttachmentDownload('image001.png', 'image/png', 'png bytes');
        $this->files['g-xlsx'] = new AttachmentDownload('Schedule.xlsx', 'application/octet-stream', 'xlsx bytes');

        $this->creator->createFromThread($this->actor(), $m['threadId'], 's', $m['messageId']);

        $documents = $this->sentDocuments(0);
        self::assertSame(['Schedule.xlsx', 'image001.png'], array_column($documents, 'fileName'), 'files first, then inline pictures');
        self::assertSame([false, true], array_column($documents, 'isInline'));
        self::assertSame(['email-2', 'email-3'], array_column($documents, 'documentPublicId'));
        self::assertSame(['g-xlsx', 'g-paste'], $this->fetched);
        self::assertSame(['email-body.txt', 'Schedule.xlsx', 'image001.png'], $this->documentNames);
    }

    public function test_only_ten_attachments_go_to_one_parse_and_the_rest_are_noted(): void
    {
        $attachments = [];
        for ($i = 1; $i <= 11; $i++) {
            $attachments[] = new NewAttachment('g-' . $i, 'part' . $i . '.csv', 'text/csv', 10, false);
            $this->files['g-' . $i] = new AttachmentDownload('part' . $i . '.csv', 'text/csv', 'a,b');
        }
        $m = $this->receive('a', $attachments);

        $this->creator->createFromThread($this->actor(), $m['threadId'], 's', $m['messageId']);

        self::assertCount(10, $this->sentDocuments(0));
        self::assertSame(['part11.csv: too many files on one email'], array_values($this->drafts)[0]->emailMeta['attachments_not_read'] ?? null);
    }

    public function test_an_attachment_that_left_the_message_is_noted_not_fatal(): void
    {
        $m = $this->receive('a', [new NewAttachment('g-pdf', 'Quote request.pdf', 'application/pdf', 100, false)]);

        $this->creator->createFromThread($this->actor(), $m['threadId'], 's', $m['messageId']);

        $draft = array_values($this->drafts)[0];
        self::assertSame(['Quote request.pdf: no longer on the message'], $draft->emailMeta['attachments_not_read'] ?? null);
    }

    public function test_an_oversized_attachment_is_not_downloaded(): void
    {
        $m = $this->receive('a', [new NewAttachment('g-big', 'huge.pdf', 'application/pdf', 30 * 1024 * 1024, false)]);

        $this->creator->createFromThread($this->actor(), $m['threadId'], 's', $m['messageId']);

        self::assertSame([], $this->fetched);
        self::assertSame(['huge.pdf: larger than the parser accepts'], array_values($this->drafts)[0]->emailMeta['attachments_not_read'] ?? null);
    }

    public function test_a_file_the_parser_refuses_does_not_cost_the_email_its_draft(): void
    {
        $m = $this->receive('a', [new NewAttachment('g-pdf', 'Scan.pdf', 'application/pdf', 100, false)]);
        $this->files['g-pdf'] = new AttachmentDownload('Scan.pdf', 'application/pdf', 'not really a pdf');
        $this->parser->firstParseFailure = RfqParserFailure::of(RfqParserFailure::UNSUPPORTED_FORMAT, 'Scan.pdf does not contain what its .pdf name says.', \TriCab\Core\Rfq\Parser\CorrelationId::generate());

        $this->creator->createFromThread($this->actor(), $m['threadId'], 's', $m['messageId']);

        self::assertCount(2, $this->parser->parses);
        self::assertSame(['Scan.pdf'], array_column($this->sentDocuments(0), 'fileName'));
        self::assertSame([], $this->sentDocuments(1), 'the second try is the body alone');
        $draft = array_values($this->drafts)[0];
        $notRead = $draft->emailMeta['attachments_not_read'] ?? null;
        self::assertIsArray($notRead);
        self::assertStringContainsString('the parser refused the attachments', (string) json_encode($notRead));
    }

    public function test_a_retryable_parser_outage_is_a_refusal_with_nothing_created(): void
    {
        $m = $this->receive('a');
        $this->parser->parseFailure = RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'down', \TriCab\Core\Rfq\Parser\CorrelationId::generate());

        try {
            $this->creator->createFromThread($this->actor(), $m['threadId'], 's', $m['messageId']);
            self::fail('expected MailUnavailable');
        } catch (MailUnavailable $failure) {
            self::assertSame(MailUnavailable::DRAFTS, $failure->what);
        }
        self::assertSame([], $this->drafts);
        self::assertNull($this->store->thread($m['threadId'])?->links[0] ?? null);
    }

    public function test_a_message_that_left_the_mailbox_is_unavailable_not_a_crash(): void
    {
        $m = $this->receive('a');
        $this->bodyFailure = new MailUnavailable('boom', MailUnavailable::BODY);

        $this->expectException(MailUnavailable::class);
        $this->creator->createFromThread($this->actor(), $m['threadId'], 's', $m['messageId']);
    }

    public function test_a_mailbox_that_is_not_a_platform_user_cannot_own_a_draft(): void
    {
        $m = $this->receive('a');
        $creator = new RfqEmailDraftCreator($this->store, $this->fetcher(), $this->rfqs, new InMemoryApiUserRepository(), MailFixtures::MAILBOX, 'VIC');

        try {
            $creator->createFromThread($this->actor(), $m['threadId'], 's', $m['messageId']);
            self::fail('expected MailUnavailable');
        } catch (MailUnavailable $failure) {
            self::assertStringContainsString('not a platform user', $failure->getMessage());
        }
    }
}
