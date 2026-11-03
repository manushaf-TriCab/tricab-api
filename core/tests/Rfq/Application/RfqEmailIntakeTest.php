<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Application;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Core\Rfq\Application\DTO\SourceDocument;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;
use TriCab\Core\Rfq\Contract\RfqDocumentStore;
use TriCab\Core\Rfq\Contract\RfqDraftStore;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Contract\RfqReaderJobStore;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Infrastructure\InMemoryRfqRepository;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\StoredDraft;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;

/**
 * RFQ mail intake M6.1 at the service: an email becomes a draft through the upload's intake, as source `email`,
 * owned by the mailbox user, with the message's ids kept, and one message can never become two drafts.
 */
final class RfqEmailIntakeTest extends TestCase
{
    private const DRAFT_ID = 'abcdef0123456789abcdef0123456789';

    private FixtureRfqParserClient $parser;

    /** @var array<string, NewDraft> */
    private array $drafts = [];

    /** @var list<string> */
    private array $stored = [];

    private RfqReaderService $service;

    private RfqDraftStore $store;

    /** When set, `load` reports no draft until `create` has been called this many times (a racing run's insert landing in between). */
    private bool $raceOnCreate = false;

    protected function setUp(): void
    {
        $this->parser = new FixtureRfqParserClient();
        $self = $this;
        $this->store = new class ($self) implements RfqDraftStore {
            public function __construct(private readonly RfqEmailIntakeTest $test)
            {
            }

            public function create(NewDraft $draft): string
            {
                return $this->test->insert($draft);
            }

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, DraftActor $actor, string $viewMode = ''): ?int
            {
                throw new RuntimeException('not used');
            }

            public function load(string $draftId): ?StoredDraft
            {
                return $this->test->find($draftId);
            }
        };
        $documents = new class ($this) implements RfqDocumentStore {
            public function __construct(private readonly RfqEmailIntakeTest $test)
            {
            }

            public function store(string $draftId, string $fileName, string $contentType, string $bytes, string $uploaderEmail): SourceDocument
            {
                $this->test->keep($draftId . ':' . $fileName);

                return new SourceDocument('00000000-0000-4000-8000-000000000001', SourceDocument::KIND_FILE, $fileName, $contentType, strlen($bytes), hash('sha256', $bytes), new DateTimeImmutable('2026-11-03 00:00:00 UTC'));
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
                return $this->test->documentsOf($draftId);
            }
        };
        $jobs = $this->createStub(RfqReaderJobStore::class);
        $operators = new class () implements RfqOperatorDirectory {
            public function codeFor(string $email): ?string
            {
                return null;
            }
        };
        $this->service = new RfqReaderService($this->parser, $this->store, $jobs, $documents, new InMemoryRfqRepository(), $operators, new FixedClock(new DateTimeImmutable('2026-11-03 00:00:00 UTC')));
    }

    /** @internal for the doubles above */
    public function insert(NewDraft $draft): string
    {
        $id = $draft->id ?? 'ffffffffffffffffffffffffffffffff';
        if (isset($this->drafts[$id])) {
            throw new RuntimeException('Violation of PRIMARY KEY constraint');
        }
        $this->drafts[$id] = $draft;

        return $id;
    }

    /** @internal */
    public function find(string $id): ?StoredDraft
    {
        $draft = $this->drafts[$id] ?? null;
        if ($draft === null || $this->raceOnCreate) {
            return null;
        }

        return new StoredDraft($id, 1, 'new', [], $draft->quoteDetails, $draft->emailMeta ?? [], $draft->rows, []);
    }

    /** @internal */
    public function keep(string $key): void
    {
        $this->stored[] = $key;
    }

    /**
     * @internal
     *
     * @return list<SourceDocument>
     */
    public function documentsOf(string $draftId): array
    {
        $out = [];
        foreach ($this->stored as $key) {
            if (str_starts_with($key, $draftId . ':')) {
                $out[] = new SourceDocument('00000000-0000-4000-8000-000000000001', SourceDocument::KIND_FILE, substr($key, 33), null, 1, '', new DateTimeImmutable());
            }
        }

        return $out;
    }

    private static function mailbox(): RfqActor
    {
        return new RfqActor(11, 'user-11', Module::ROLE_PERMISSIONS[Module::ROLE_SALES], 'VIC');
    }

    /** @return list<array{fileName: string, contentType: string, bytes: string}> */
    private static function files(): array
    {
        return [
            ['fileName' => 'email-body.txt', 'contentType' => 'text/plain', 'bytes' => "Please quote 100m 3C 2.5mm\n"],
            ['fileName' => 'schedule.xlsx', 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'bytes' => 'xlsx bytes'],
        ];
    }

    /** @return array<string, mixed> */
    private static function meta(): array
    {
        return ['internet_message_id' => '<a@customer.example>', 'original_sender' => ['name' => 'Jo', 'address' => 'jo@customer.example'], 'mail_thread_id' => 'thread-1'];
    }

    public function test_an_email_becomes_an_email_source_draft_owned_by_the_mailbox_user(): void
    {
        $made = $this->service->createFromEmail(self::mailbox(), 'melbourne@tricab.com', self::DRAFT_ID, 'Email - Quote', self::files(), self::meta(), CorrelationId::generate());

        self::assertTrue($made['created']);
        self::assertSame('abcdef01-2345-6789-abcd-ef0123456789', $made['publicId']);
        $draft = $this->drafts[self::DRAFT_ID];
        self::assertSame('email', $draft->source);
        self::assertSame('Email - Quote', $draft->label);
        self::assertSame('melbourne@tricab.com', $draft->owner());
        self::assertSame('melbourne@tricab.com', $draft->assignee());
        self::assertSame('VIC', $draft->ownerBranch());
        self::assertSame('<a@customer.example>', $draft->emailMeta['internet_message_id'] ?? null);
        self::assertSame(['name' => 'Jo', 'address' => 'jo@customer.example'], $draft->emailMeta['original_sender'] ?? null);
        self::assertSame('melbourne@tricab.com', $draft->emailMeta['owner_email'] ?? null, 'the intake still stamps the owner');
        self::assertArrayHasKey('parse', $draft->emailMeta ?? []);
        self::assertNotEmpty($draft->rows);
        self::assertSame([self::DRAFT_ID . ':email-body.txt', self::DRAFT_ID . ':schedule.xlsx'], $this->stored, 'the body and the attachment are kept');
        self::assertCount(1, $this->parser->parses);
        self::assertSame(['email-body.txt', 'schedule.xlsx'], array_column($this->parser->parses[0]->documents, 'fileName'));
    }

    public function test_the_same_message_again_returns_the_same_draft_and_parses_nothing(): void
    {
        $this->service->createFromEmail(self::mailbox(), 'melbourne@tricab.com', self::DRAFT_ID, 'Email - Quote', self::files(), self::meta(), CorrelationId::generate());
        $again = $this->service->createFromEmail(self::mailbox(), 'melbourne@tricab.com', self::DRAFT_ID, 'Email - Quote', self::files(), self::meta(), CorrelationId::generate());

        self::assertFalse($again['created']);
        self::assertSame('abcdef01-2345-6789-abcd-ef0123456789', $again['publicId']);
        self::assertCount(1, $this->drafts);
        self::assertCount(1, $this->parser->parses, 'the second call did not even parse');
        self::assertCount(2, $this->stored, 'nothing stored twice');
    }

    public function test_a_run_that_loses_the_race_on_the_primary_key_adopts_the_winners_draft(): void
    {
        // The other run's insert lands between this run's check and its own insert.
        $this->drafts[self::DRAFT_ID] = new NewDraft('email', 'Email - Quote', [], [['raw_text' => 'x']], self::meta(), 'melbourne@tricab.com', id: self::DRAFT_ID);
        $this->raceOnCreate = true;
        $first = true;
        $store = new class ($this, $first) implements RfqDraftStore {
            public function __construct(private readonly RfqEmailIntakeTest $test, private bool $firstLoad)
            {
            }

            public function create(NewDraft $draft): string
            {
                return $this->test->insert($draft);
            }

            public function saveContent(string $draftId, int $expectedRevision, array $quoteDetails, array $rows, string $nextStatus, DraftActor $actor, string $viewMode = ''): ?int
            {
                throw new RuntimeException('not used');
            }

            public function load(string $draftId): ?StoredDraft
            {
                // Absent the first time (the check), present afterwards (the adoption).
                if ($this->firstLoad) {
                    $this->firstLoad = false;

                    return null;
                }
                $this->test->releaseRace();

                return $this->test->find($draftId);
            }
        };
        $service = new RfqReaderService($this->parser, $store, $this->createStub(RfqReaderJobStore::class), $this->documentStore(), new InMemoryRfqRepository(), $this->operators(), new FixedClock(new DateTimeImmutable('2026-11-03 00:00:00 UTC')));

        $made = $service->createFromEmail(self::mailbox(), 'melbourne@tricab.com', self::DRAFT_ID, 'Email - Quote', self::files(), self::meta(), CorrelationId::generate());

        self::assertFalse($made['created'], 'this run lost; the winner owns the draft');
        self::assertSame('abcdef01-2345-6789-abcd-ef0123456789', $made['publicId']);
        self::assertCount(1, $this->drafts);
    }

    /** @internal */
    public function releaseRace(): void
    {
        $this->raceOnCreate = false;
    }

    private function documentStore(): RfqDocumentStore
    {
        $test = $this;

        return new class ($test) implements RfqDocumentStore {
            public function __construct(private readonly RfqEmailIntakeTest $test)
            {
            }

            public function store(string $draftId, string $fileName, string $contentType, string $bytes, string $uploaderEmail): SourceDocument
            {
                $this->test->keep($draftId . ':' . $fileName);

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
                return $this->test->documentsOf($draftId);
            }
        };
    }

    private function operators(): RfqOperatorDirectory
    {
        return new class () implements RfqOperatorDirectory {
            public function codeFor(string $email): ?string
            {
                return null;
            }
        };
    }

    public function test_an_adopted_draft_whose_first_run_died_before_storing_files_gets_them(): void
    {
        $this->drafts[self::DRAFT_ID] = new NewDraft('email', 'Email - Quote', [], [], self::meta(), 'melbourne@tricab.com', id: self::DRAFT_ID);

        $made = $this->service->createFromEmail(self::mailbox(), 'melbourne@tricab.com', self::DRAFT_ID, 'Email - Quote', self::files(), self::meta(), CorrelationId::generate());

        self::assertFalse($made['created']);
        self::assertSame([self::DRAFT_ID . ':email-body.txt', self::DRAFT_ID . ':schedule.xlsx'], $this->stored);
        self::assertSame([], $this->parser->parses);
    }

    public function test_an_email_the_parser_reads_nothing_from_is_still_a_draft_with_no_lines(): void
    {
        $this->parser->documentsAnswer = ['lines' => [], 'coverage' => ['inputRows' => 0, 'keptRows' => 0, 'excludedRows' => 0, 'skippedRows' => 0, 'measured' => true], 'documents' => []];

        $made = $this->service->createFromEmail(self::mailbox(), 'melbourne@tricab.com', self::DRAFT_ID, 'Email - Hello', [self::files()[0]], self::meta(), CorrelationId::generate());

        self::assertTrue($made['created']);
        self::assertSame(0, $made['lineCount']);
        self::assertSame([], $this->drafts[self::DRAFT_ID]->rows);
    }

    public function test_a_parser_failure_creates_nothing_so_a_retry_starts_clean(): void
    {
        $this->parser->parseFailure = RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'down', CorrelationId::generate());

        try {
            $this->service->createFromEmail(self::mailbox(), 'melbourne@tricab.com', self::DRAFT_ID, 'Email - Quote', self::files(), self::meta(), CorrelationId::generate());
            self::fail('expected the parser failure');
        } catch (RfqParserFailure) {
        }

        self::assertSame([], $this->drafts);
        self::assertSame([], $this->stored);
    }

    public function test_the_mailbox_user_needs_the_create_permission(): void
    {
        $this->expectException(RfqActionForbidden::class);

        $this->service->createFromEmail(new RfqActor(1, 'u', [], null), 'melbourne@tricab.com', self::DRAFT_ID, 'Email - Quote', self::files(), self::meta(), CorrelationId::generate());
    }

    public function test_the_upload_path_is_unchanged_and_still_makes_a_web_draft_with_a_generated_id(): void
    {
        $uploaded = $this->service->upload(self::mailbox(), 'rep@tricab.com', [['fileName' => 'schedule.xlsx', 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'bytes' => 'x']], CorrelationId::generate());

        self::assertNotSame('', $uploaded->publicId);
        $draft = array_values($this->drafts)[0];
        self::assertSame('web', $draft->source);
        self::assertNull($draft->id, 'an upload lets the store generate the id');
        self::assertArrayNotHasKey('internet_message_id', $draft->emailMeta ?? []);
    }
}
