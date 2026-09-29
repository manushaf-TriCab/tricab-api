<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Persistence;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Product\Storage\InMemoryProductBlobStore;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqDocumentStore;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqRepository;
use TriCab\Core\Rfq\Application\Service\RfqReaderService;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\ValueObject\ExtractionState;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Shared\Time\SystemClock;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;

/**
 * Plans 2.5, 3.3 and 3.5 end to end on SQL Server: an upload becomes a draft
 * with its documents stored and its reads queued; the worker replaces an
 * untouched draft's rows or proposes them against an edited one; a person
 * accepts or dismisses; failures retry, then fail loudly; and the source's own
 * jobs are never claimed. The parser is the recorded fixture; the blob store
 * is in memory.
 *
 * Needs a local SQL Server ({@see LocalRfqSqlServer}); skips without one.
 */
#[Group('local-sql-server')]
final class RfqReaderJobsTest extends TestCase
{
    private PDO $pdo;
    private AzureSqlRfqDraftStore $store;
    private AzureSqlRfqRepository $repository;
    private FixtureRfqParserClient $parser;
    private RfqReaderService $reader;

    protected function setUp(): void
    {
        $pdo = LocalRfqSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set RFQ_TEST_SQLSERVER and RFQ_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalRfqSqlServer::truncate($pdo);
        InMemoryProductBlobStore::resetSharedForTests();
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $clock = new SystemClock();
        $this->store = new AzureSqlRfqDraftStore($connection, $clock);
        $documents = new AzureSqlRfqDocumentStore($connection, new InMemoryProductBlobStore(), 'rfq-documents', $clock);
        $this->repository = new AzureSqlRfqRepository($connection, $documents, $this->store);
        $this->parser = new FixtureRfqParserClient();
        // The deterministic parse: every line came from schedule.xlsx; notes.csv
        // has no model reader, schedule.xlsx does.
        $this->parser->documentsAnswer = [
            'lines' => array_map(static function (array $line): array {
                $line['source'] = ['file_name' => 'schedule.xlsx', 'sheet' => 'S', 'row_number' => 2];

                return $line;
            }, FixtureRfqParserClient::lines()),
            'coverage' => ['inputRows' => 5, 'keptRows' => 3, 'excludedRows' => 0, 'skippedRows' => 2, 'measured' => true],
            'documents' => [
                ['documentPublicId' => 'upload-1', 'fileName' => 'schedule.xlsx', 'readerRoute' => true, 'lineCount' => 3],
                ['documentPublicId' => 'upload-2', 'fileName' => 'notes.csv', 'readerRoute' => false, 'lineCount' => 0],
            ],
        ];
        $operators = new class () implements RfqOperatorDirectory {
            public function codeFor(string $email): ?string
            {
                return null;
            }
        };
        $this->reader = new RfqReaderService($this->parser, $this->store, $this->store, $documents, $this->repository, $operators, $clock);
    }

    private static function sales(): RfqActor
    {
        return new RfqActor(7, 'user-7', Module::ROLE_PERMISSIONS[Module::ROLE_SALES], 'VIC');
    }

    private function upload(): string
    {
        $uploaded = $this->reader->upload(self::sales(), 'rep@tricab.com', [
            ['fileName' => 'C:\\Users\\rep\\schedule.xlsx', 'contentType' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'bytes' => 'xlsx bytes'],
            ['fileName' => 'notes.csv', 'contentType' => 'text/csv', 'bytes' => "a,b\n"],
        ], CorrelationId::generate());

        return $uploaded->publicId;
    }

    /** @return list<array<string, mixed>> */
    private function query(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** @return array<string, string|null> the job's columns as text */
    private function job(string $draftId): array
    {
        $row = $this->query("SELECT * FROM [rfq].[rfq_reader_jobs] WHERE [draft_id] = '{$draftId}'")[0] ?? [];

        return array_map(static fn (mixed $v): ?string => $v === null ? null : (is_scalar($v) ? (string) $v : null), $row);
    }

    /** @return list<mixed> each line's raw text */
    private function rawTexts(string $draftId): array
    {
        return array_map(static fn (array $r): mixed => $r['raw_text'] ?? null, $this->store->load($draftId)->rows ?? []);
    }

    /** @return array<string, mixed> `email_meta.parse` */
    private function parseMeta(string $draftId): array
    {
        $parse = $this->store->load($draftId)?->emailMeta['parse'] ?? null;
        self::assertIsArray($parse);
        /** @var array<string, mixed> $parse */
        return $parse;
    }

    public function test_an_upload_is_a_draft_with_its_documents_stored_and_its_reads_queued(): void
    {
        $publicId = $this->upload();
        $draftId = (string) StoreProjection::draftId($publicId);

        $draft = $this->store->load($draftId);
        self::assertNotNull($draft);
        self::assertCount(3, $draft->rows);
        self::assertIsString($draft->columns['label']);
        self::assertMatchesRegularExpression('/^schedule\.xlsx \+1 more \x{2013} \d{2} \w{3} \d{4}$/u', $draft->columns['label']);
        self::assertSame('web', $draft->columns['source']);
        self::assertSame(['inputRows' => 5, 'keptRows' => 3, 'excludedRows' => 0, 'skippedRows' => 2, 'measured' => true], $this->parseMeta($draftId)['coverage'] ?? null);

        $documents = $this->repository->findDetail($this->record($publicId), [])->documents;
        self::assertSame(['schedule.xlsx', 'notes.csv'], array_map(static fn ($d): string => $d->fileName, $documents));
        self::assertSame(hash('sha256', 'xlsx bytes'), $documents[0]->sha256);

        self::assertCount(1, $this->query("SELECT [id] FROM [rfq].[rfq_reader_jobs] WHERE [draft_id] = '{$draftId}'"), 'only the file a model reader will read is queued');
        $job = $this->job($draftId);
        self::assertSame(['schedule.xlsx', 'queued', '1'], [$job['file_name'], $job['status'], $job['base_revision']]);
        self::assertStringStartsWith('rfq-document:', (string) $job['spool_path']);
        self::assertTrue($this->record($publicId)->extractionState->is(ExtractionState::READING));
    }

    public function test_a_read_replaces_an_untouched_drafts_rows_for_its_file(): void
    {
        $publicId = $this->upload();
        $draftId = (string) StoreProjection::draftId($publicId);
        $this->parser->readLines = [self::line('4C 16mm2 XLPE/PVC 250m', 'schedule.xlsx'), self::line('2C 2.5mm2 TPS 100m', 'schedule.xlsx')];

        $summary = $this->reader->runWorker();

        self::assertSame(['drafts' => 1, 'jobs' => 1, 'applied' => 1, 'proposed' => 0, 'failed' => 0], array_intersect_key($summary, array_flip(['drafts', 'jobs', 'applied', 'proposed', 'failed'])));
        $draft = $this->store->load($draftId);
        self::assertNotNull($draft);
        self::assertSame(2, $draft->revision);
        self::assertSame(['4C 16mm2 XLPE/PVC 250m', '2C 2.5mm2 TPS 100m'], $this->rawTexts($draftId));
        self::assertSame($draft->rows[0]['raw_text'], $draft->systemRows[0]['raw_text'], 'the re-read is the new system snapshot');
        $parse = $this->parseMeta($draftId);
        self::assertSame(false, is_array($parse['coverage'] ?? null) ? ($parse['coverage']['measured'] ?? null) : null, "a model's rows are unmeasured");
        self::assertSame('gpt-test', $parse['read_by'] ?? null);
        $job = $this->job($draftId);
        self::assertSame(['done', null], [$job['status'], $job['proposal']]);
        self::assertSame('schedule.xlsx', $this->parser->reads[0]->fileName);
        self::assertStringStartsWith('schedule.xlsx +1 more', $this->parser->reads[0]->subject);
        self::assertSame('xlsx bytes', $this->parser->reads[0]->bytes, 'the stored bytes are what is read');
        $events = array_column($this->query("SELECT [event_type] FROM [rfq].[rfq_draft_activity] WHERE [draft_id] = '{$draftId}' ORDER BY [created_at]"), 'event_type');
        self::assertSame(['created', 'reader_rows_replaced'], $events);
        $record = $this->record($publicId);
        self::assertTrue($record->extractionState->is(ExtractionState::PARTIAL));
        self::assertSame([ReaderJobStatus::APPLIED], array_map(static fn ($r): string => $r->status->toString(), $this->repository->findDetail($record, [])->reads));
    }

    public function test_an_edited_draft_gets_a_proposal_which_a_person_accepts(): void
    {
        $publicId = $this->upload();
        $draftId = (string) StoreProjection::draftId($publicId);
        $stored = $this->store->load($draftId);
        self::assertNotNull($stored);
        $this->store->saveContent($draftId, 1, $stored->quoteDetails, $stored->rows, 'in_progress', new DraftActor('rep@tricab.com', 'sales', 'VIC'));
        $this->parser->readLines = [self::line('4C 16mm2 XLPE/PVC 250m', 'schedule.xlsx')];

        self::assertSame(1, $this->reader->runWorker()['proposed']);
        self::assertCount(3, $this->rawTexts($draftId), "the rep's rows are untouched");
        $reads = $this->reader->reads(self::sales(), $publicId);
        self::assertNotNull($reads);
        self::assertSame(ReaderJobStatus::PROPOSED, $reads[0]->status->toString());
        self::assertSame(1, $reads[0]->proposedLineCount);
        self::assertTrue($this->record($publicId)->extractionState->is(ExtractionState::ATTENTION));
        self::assertSame('in_progress', $this->store->load($draftId)?->status);

        $revision = $this->reader->resolve(self::sales(), 'rep@tricab.com', $publicId, $reads[0]->publicId, 'accept', 2, CorrelationId::generate());

        self::assertSame(3, $revision);
        self::assertSame(['4C 16mm2 XLPE/PVC 250m'], $this->rawTexts($draftId));
        $job = $this->job($draftId);
        self::assertSame(['done', null], [$job['status'], $job['proposal']]);
        self::assertStringStartsWith('Proposal accepted by rep@tricab.com', (string) $job['note']);
        self::assertFalse($this->record($publicId)->extractionState->is(ExtractionState::ATTENTION));
    }

    public function test_accepting_against_a_stale_revision_is_refused(): void
    {
        $publicId = $this->upload();
        $draftId = (string) StoreProjection::draftId($publicId);
        $stored = $this->store->load($draftId);
        self::assertNotNull($stored);
        $this->store->saveContent($draftId, 1, $stored->quoteDetails, $stored->rows, 'in_progress', new DraftActor('rep@tricab.com', 'sales', 'VIC'));
        $this->reader->runWorker();
        $readId = $this->reader->reads(self::sales(), $publicId)[0]->publicId ?? '';

        $this->expectException(\TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict::class);
        $this->reader->resolve(self::sales(), 'rep@tricab.com', $publicId, $readId, 'accept', 1, CorrelationId::generate());
    }

    public function test_a_provider_outage_retries_then_fails_loudly(): void
    {
        $publicId = $this->upload();
        $draftId = (string) StoreProjection::draftId($publicId);
        $this->parser->readFailure = RfqParserFailure::of(RfqParserFailure::PROVIDER_UNAVAILABLE, 'the model service is throttling requests');

        self::assertSame(1, $this->reader->runWorker()['retried']);
        self::assertSame(['queued', '1'], [$this->job($draftId)['status'], $this->job($draftId)['attempts']]);
        self::assertSame(1, $this->reader->runWorker()['retried']);
        self::assertSame(1, $this->reader->runWorker()['failed'], 'the third attempt is the last');

        $job = $this->job($draftId);
        self::assertSame('failed', $job['status']);
        self::assertSame('Terra could not read this file: the model service is throttling requests', $job['note']);
        self::assertTrue($this->record($publicId)->extractionState->is(ExtractionState::ATTENTION));
        self::assertCount(3, $this->rawTexts($draftId), 'the deterministic rows stay');

        // Dismissing a failure is how it is acknowledged; there is nothing to accept.
        $readId = $this->reader->reads(self::sales(), $publicId)[0]->publicId ?? '';
        try {
            $this->reader->resolve(self::sales(), 'rep@tricab.com', $publicId, $readId, 'accept', null, CorrelationId::generate());
            self::fail('accepting a failure must be refused');
        } catch (InvalidRfqValue) {
        }
        $this->reader->resolve(self::sales(), 'rep@tricab.com', $publicId, $readId, 'dismiss', null, CorrelationId::generate());
        self::assertSame('dismissed', $this->job($draftId)['status']);
        self::assertFalse($this->record($publicId)->extractionState->is(ExtractionState::ATTENTION));
    }

    public function test_bad_credentials_fail_at_once(): void
    {
        $publicId = $this->upload();
        $this->parser->readFailure = RfqParserFailure::of(RfqParserFailure::PROVIDER_REJECTED, "the model service refused the reader's credentials");
        self::assertSame(1, $this->reader->runWorker()['failed']);
        self::assertSame('failed', $this->job((string) StoreProjection::draftId($publicId))['status']);
    }

    public function test_a_reader_switched_off_stands_the_job_down(): void
    {
        $publicId = $this->upload();
        $this->parser->readFailure = RfqParserFailure::of(RfqParserFailure::UNSUPPORTED_FORMAT, 'No model reader is switched on for .xlsx files.');
        self::assertSame(1, $this->reader->runWorker()['superseded']);
        self::assertSame('superseded', $this->job((string) StoreProjection::draftId($publicId))['status']);
    }

    public function test_the_sources_jobs_are_never_claimed_and_a_claim_is_taken_once(): void
    {
        $publicId = $this->upload();
        $draftId = (string) StoreProjection::draftId($publicId);
        $this->pdo->exec("INSERT INTO [rfq].[rfq_reader_jobs] ([id], [created_at], [draft_id], [file_name], [spool_path], [status])
            VALUES ('" . str_repeat('e', 32) . "', N'2000-01-01T00:00:00+00:00', '{$draftId}', N'old.xls', N'/home/data/rfq-upload-spool/x-old.xls', N'queued')");

        $first = $this->store->claim($draftId);
        self::assertSame(['schedule.xlsx'], array_map(static fn ($j): string => $j->fileName, $first));
        self::assertSame([], $this->store->claim($draftId), 'a second claim gets nothing');
        self::assertNull($this->store->nextDraft(), "the source's queued job is not the platform's");
    }

    public function test_an_abandoned_claim_is_recovered_and_then_given_up(): void
    {
        $publicId = $this->upload();
        $draftId = (string) StoreProjection::draftId($publicId);
        $this->store->claim($draftId);
        $this->pdo->exec("UPDATE [rfq].[rfq_reader_jobs] SET [updated_at] = N'2000-01-01T00:00:00+00:00' WHERE [draft_id] = '{$draftId}'");

        self::assertSame(1, $this->store->requeueStale('2001-01-01T00:00:00+00:00', 3));
        self::assertSame('queued', $this->job($draftId)['status']);

        $this->pdo->exec("UPDATE [rfq].[rfq_reader_jobs] SET [status] = N'running', [attempts] = 3, [updated_at] = N'2000-01-01T00:00:00+00:00' WHERE [draft_id] = '{$draftId}'");
        self::assertSame(1, $this->store->requeueStale('2001-01-01T00:00:00+00:00', 3));
        self::assertSame('failed', $this->job($draftId)['status']);
    }

    public function test_a_document_is_downloaded_only_through_its_rfq(): void
    {
        $publicId = $this->upload();
        $documents = $this->reader->documents(self::sales(), $publicId) ?? [];
        $found = $this->reader->download(self::sales(), $publicId, $documents[0]->publicId);
        self::assertSame('xlsx bytes', $found['bytes'] ?? null);
        self::assertNull($this->reader->download(self::sales(), StoreProjection::publicId(str_repeat('a', 32)), $documents[0]->publicId));
        self::assertNull($this->reader->download(new RfqActor(8, 'user-8', [], null), $publicId, $documents[0]->publicId), 'no read permission, no document');
    }

    /** @return array<string, mixed> */
    private static function line(string $text, string $file): array
    {
        $line = FixtureRfqParserClient::lines()[0];
        $line['raw_text'] = $text;
        $line['source'] = ['input_type' => 'attachment_reader', 'file_name' => $file, 'sheet' => 'S', 'row_number' => 2];

        return $line;
    }

    private function record(string $publicId): \TriCab\Core\Rfq\Application\DTO\RfqRecord
    {
        $record = $this->repository->findRecord($publicId);
        self::assertNotNull($record);

        return $record;
    }
}
