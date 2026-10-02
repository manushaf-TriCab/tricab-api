<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqCorrectionMemory;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqRepository;
use TriCab\Api\Tests\Rfq\Persistence\LocalRfqSqlServer;
use TriCab\Core\Rfq\Application\DTO\CreatedRfq;
use TriCab\Core\Rfq\Application\DTO\ParsedLine;
use TriCab\Core\Rfq\Application\DTO\RfqDetail;
use TriCab\Core\Rfq\Application\Service\ReadRfqQueueService;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Domain\Exception\RfqActionForbidden;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParseSeal;
use TriCab\Core\Rfq\Parser\RfqParserFailure;
use TriCab\Core\Rfq\Storage\PythonJson;
use TriCab\Core\Rfq\Storage\QuoteHeaderEdit;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingCustomerDirectory;

/**
 * Plan 3.7, the first vertical slice, through the real services and the real
 * store on SQL Server: paste → parse → draft → review → save → reopen. The
 * parser is the recorded answer of the real one (`FixtureRfqParserClient`);
 * nothing on this path writes outside the store.
 *
 * Needs a local SQL Server ({@see LocalRfqSqlServer}); skips without one.
 */
#[Group('local-sql-server')]
final class RfqFirstSliceTest extends TestCase
{
    private PDO $pdo;

    private FixtureRfqParserClient $parser;

    private RfqReviewService $review;

    private ReadRfqQueueService $queue;

    private int $repId;

    private int $colleagueId;

    protected function setUp(): void
    {
        $pdo = LocalRfqSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set RFQ_TEST_SQLSERVER and RFQ_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalRfqSqlServer::truncate($pdo);
        $this->repId = $this->user('rep@tricab.com');
        $this->colleagueId = $this->user('colleague@tricab.com');

        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $repository = new AzureSqlRfqRepository($connection);
        $operators = new class implements RfqOperatorDirectory {
            public function codeFor(string $email): ?string
            {
                return ['rep@tricab.com' => 'REPCODE', 'colleague@tricab.com' => 'COLLEAGUE'][$email] ?? null;
            }
        };
        $this->parser = new FixtureRfqParserClient();
        $this->review = new RfqReviewService(
            $this->parser,
            new AzureSqlRfqDraftStore($connection, FixedClock::utc('2026-09-28T02:00:00')),
            $repository,
            $operators,
            new RecordingCustomerDirectory(),
            FixedClock::utc('2026-09-28T02:00:00'),
            new ParseSeal('test-parser-token'),
            false,
            null,
            new AzureSqlRfqCorrectionMemory($connection, FixedClock::utc('2026-09-28T02:00:00')),
        );
        $this->queue = new ReadRfqQueueService($repository);
    }

    public function test_paste_parse_review_save_reopen(): void
    {
        $rep = $this->sales($this->repId);

        // Match Text → Save as Draft: a draft that is the parser's answer.
        $created = $this->paste($rep, 'rep@tricab.com', "DB-1\n4C 16mm2 XLPE/PVC 120m\n3C 2.5mm TPS 4 x 55m per cut\nsupply and install lighting");
        $opened = $this->open($rep, $created->publicId);
        self::assertSame(1, $opened->summary->record->revision);
        self::assertSame('new', $opened->summary->record->workflowStatus->toString());
        self::assertSame('Rep', $opened->summary->record->assigneeDisplayName);
        self::assertContains('edit', $opened->summary->allowedActions);
        $lines = $this->lines($opened);
        self::assertCount(3, $lines);
        self::assertSame('REPCODE', $opened->revision?->header->authorCode);
        self::assertSame('XL-PAXN/4C16OR', $lines[0]->productCode);

        // The system snapshot is the parser's rows, byte for byte, and valid.
        $draftId = (string) StoreProjection::draftId($created->publicId);
        $parsed = FixtureRfqParserClient::lines();
        foreach ($this->column($draftId, 'rfq_draft_rows', '[system_row_data], [system_snapshot_valid]', '[row_order]') as $i => $snapshot) {
            self::assertSame('1', self::str($snapshot['system_snapshot_valid']));
            $withUid = PythonJson::decodeObject(self::str($snapshot['system_row_data']));
            unset($withUid['line_uid']);
            self::assertSame(PythonJson::encode($parsed[$i]), PythonJson::encode($withUid));
        }

        // Review: approve the first, comment the second, delete the third. A colleague saves.
        $colleague = $this->sales($this->colleagueId);
        $revision = $this->review->save($colleague, 'colleague@tricab.com', $created->publicId, 1, [
            ['lineUid' => $lines[0]->lineUid, 'approved' => true, 'comment' => (string) $lines[0]->comment],
            ['lineUid' => $lines[1]->lineUid, 'approved' => false, 'comment' => 'Deliver to site B'],
        ], CorrelationId::generate());
        self::assertSame(2, $revision);

        // Readiness was asked of the rows that were saved and the header the store writes.
        $asked = $this->parser->readinessCalls[0];
        self::assertCount(2, $asked['rows']);
        self::assertSame('COLLEAGUE', $asked['quoteDetails']['author'] ?? null);
        self::assertArrayNotHasKey('_draft_row_id', $asked['rows'][0]);

        // Reopen: what was saved is what comes back.
        $reopened = $this->open($rep, $created->publicId);
        self::assertSame(2, $reopened->summary->record->revision);
        self::assertSame('in_progress', $reopened->summary->record->workflowStatus->toString());
        self::assertSame('Colleague', $reopened->summary->record->assigneeDisplayName, 'the last worker is the sales rep');
        self::assertSame('COLLEAGUE', $reopened->revision?->header->authorCode);
        $saved = $this->lines($reopened);
        self::assertSame([$lines[0]->lineUid, $lines[1]->lineUid], array_map(static fn (ParsedLine $l): string => $l->lineUid, $saved));
        self::assertTrue($saved[0]->approval->isApproved());
        self::assertSame('Deliver to site B', $saved[1]->comment);

        // The change log saw it as the source's would.
        $edits = $this->column($draftId, 'rfq_draft_row_edits', '[field], [new_value], [actor_email]', '[row_order], [field]');
        $fields = array_map(static fn (array $e): string => self::str($e['field']) . ':' . self::str($e['new_value']), $edits);
        self::assertContains('status:approved', $fields);
        self::assertContains('comment:Deliver to site B', $fields);
        self::assertContains('line:', $fields, 'the deleted line');
        self::assertSame(['colleague@tricab.com'], array_values(array_unique(array_map(static fn (array $e): string => self::str($e['actor_email']), $edits))));
    }

    public function test_a_save_on_an_old_revision_writes_nothing(): void
    {
        $rep = $this->sales($this->repId);
        $created = $this->paste($rep, 'rep@tricab.com', '4C 16mm2 XLPE/PVC 120m');
        $keep = self::keepAll($this->lines($this->open($rep, $created->publicId)));

        self::assertSame(2, $this->review->save($rep, 'rep@tricab.com', $created->publicId, 1, $keep, CorrelationId::generate()));
        try {
            $this->review->save($rep, 'rep@tricab.com', $created->publicId, 1, array_slice($keep, 0, 1), CorrelationId::generate());
            self::fail('expected a conflict');
        } catch (RfqRevisionConflict $conflict) {
            self::assertSame(2, $conflict->currentRevision);
        }
        self::assertCount(3, $this->lines($this->open($rep, $created->publicId)), 'the stale save deleted nothing');
    }

    public function test_readiness_unavailable_saves_in_progress_and_ready_saves_ready(): void
    {
        $rep = $this->sales($this->repId);
        $created = $this->paste($rep, 'rep@tricab.com', '4C 16mm2 XLPE/PVC 120m');
        $keep = self::keepAll($this->lines($this->open($rep, $created->publicId)));

        $this->parser->readinessFailure = RfqParserFailure::of(RfqParserFailure::UNAVAILABLE, 'down');
        self::assertSame(2, $this->review->save($rep, 'rep@tricab.com', $created->publicId, 1, $keep, CorrelationId::generate()));
        self::assertSame('in_progress', $this->draftStatus($created->publicId));

        $this->parser->readinessFailure = null;
        $this->parser->ready = true;
        $this->review->save($rep, 'rep@tricab.com', $created->publicId, 2, $keep, CorrelationId::generate());
        self::assertSame('ready_to_sync', $this->draftStatus($created->publicId));
    }

    public function test_a_reader_cannot_save(): void
    {
        $rep = $this->sales($this->repId);
        $created = $this->paste($rep, 'rep@tricab.com', '4C 16mm2 XLPE/PVC 120m');
        $reader = new RfqActor($this->colleagueId, 'u', [Module::PERMISSION_ACCESS, Module::PERMISSION_DRAFT_READ], null);

        $this->expectException(RfqActionForbidden::class);
        $this->review->save($reader, 'colleague@tricab.com', $created->publicId, 1, [], CorrelationId::generate());
    }

    public function test_a_save_writes_the_header_and_reopens_with_it(): void
    {
        $rep = $this->sales($this->repId);
        $created = $this->paste($rep, 'rep@tricab.com', '4C 16mm2 XLPE/PVC 120m');
        $keep = self::keepAll($this->lines($this->open($rep, $created->publicId)));

        $this->review->save($rep, 'rep@tricab.com', $created->publicId, 1, $keep, CorrelationId::generate(), QuoteHeaderEdit::fromInput([
            'customerId' => 37193, 'contact' => 'Jane Buyer', 'salesOffice' => 'MEL', 'payTerms' => 'EOM030ND', 'validTill' => '2026-10-31',
        ]));
        $header = $this->open($rep, $created->publicId)->revision?->header;

        self::assertNotNull($header);
        self::assertSame('37193', $header->customerId);
        self::assertSame('A&HCON', $header->customerCode);
        self::assertSame('A & H Controls Pte Ltd', $header->customerName);
        self::assertSame('Jane Buyer', $header->contactCode);
        self::assertSame('2026-10-31', $header->validTill);
        self::assertSame('REPCODE', $header->authorCode);
        self::assertSame('australia', $header->regionCode);
        // Readiness judged the header as saved.
        $asked = end($this->parser->readinessCalls);
        self::assertIsArray($asked);
        self::assertSame('37193', $asked['quoteDetails']['customer_id'] ?? null);
    }

    public function test_grid_edits_save_in_order_and_reopen(): void
    {
        $rep = $this->sales($this->repId);
        $created = $this->paste($rep, 'rep@tricab.com', "DB-1\n4C 16mm2 XLPE/PVC 120m\n3C 2.5mm TPS 4 x 55m per cut");
        $lines = $this->lines($this->open($rep, $created->publicId));
        $added = str_repeat('c', 32);

        self::assertSame(2, $this->review->save($rep, 'rep@tricab.com', $created->publicId, 1, [
            ['lineUid' => $lines[0]->lineUid, 'approved' => null, 'comment' => 'DB-1', 'productCode' => 'xl-paxn/4c25or'],
            ['lineUid' => $added, 'approved' => null, 'comment' => 'Glands', 'productCode' => 'CG20', 'cuts' => '4', 'lengthEach' => '', 'isNew' => true],
            ['lineUid' => $lines[1]->lineUid, 'approved' => null, 'comment' => (string) $lines[1]->comment, 'cuts' => '3', 'lengthEach' => '60'],
        ], CorrelationId::generate()));

        $saved = $this->lines($this->open($rep, $created->publicId));
        self::assertSame([$lines[0]->lineUid, $added, $lines[1]->lineUid], array_map(static fn (ParsedLine $l): string => $l->lineUid, $saved));
        self::assertSame('XL-PAXN/4C25OR', $saved[0]->productCode);
        self::assertSame('CG20', $saved[1]->productCode);
        self::assertSame('180', $saved[2]->quantity);
        $entry = $saved[2]->entry;
        self::assertNotNull($entry);
        self::assertSame('3', $entry->cuts);
        self::assertSame('60', $entry->lengthEach);
        self::assertSame('(3 x 60)', $saved[2]->comment);
    }

    public function test_save_review_logs_the_saved_revision_and_the_next_parse_is_sent_what_it_learned(): void
    {
        $rep = $this->sales($this->repId);
        $created = $this->paste($rep, 'rep@tricab.com', "4C 16mm2 XLPE/PVC 120m\n3C 2.5mm TPS 4 x 55m per cut");
        $lines = $this->lines($this->open($rep, $created->publicId));
        $this->review->save($rep, 'rep@tricab.com', $created->publicId, 1, [
            ['lineUid' => $lines[0]->lineUid, 'approved' => true, 'comment' => '', 'reviewerNote' => 'checked', 'correctionReason' => 'wrong_series'],
            ['lineUid' => $lines[1]->lineUid, 'approved' => null, 'comment' => '', 'status' => 'flagged'],
        ], CorrelationId::generate());

        try {
            $this->review->saveReview($rep, 'rep@tricab.com', $created->publicId, 1, CorrelationId::generate());
            self::fail('expected a conflict: only the saved revision is logged');
        } catch (RfqRevisionConflict $conflict) {
            self::assertSame(2, $conflict->currentRevision);
        }
        $saved = $this->review->saveReview($rep, 'rep@tricab.com', $created->publicId, 2, CorrelationId::generate());
        self::assertNotNull($saved);
        self::assertSame([2, 1], [$saved->savedEntries, $saved->learnedCorrections]);

        $statement = $this->pdo->prepare('SELECT [final_status], [reviewer_note], [correction_reason] FROM [rfq].[rfq_entries] WHERE [session_id] = ? ORDER BY [final_status]');
        $statement->execute([$saved->sessionId]);
        /** @var list<array<string, mixed>> $entries */
        $entries = $statement->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['approved', 'flagged'], array_map(static fn (array $e): string => self::str($e['final_status']), $entries));
        self::assertSame(['checked', 'wrong_series'], [self::str($entries[0]['reviewer_note']), self::str($entries[0]['correction_reason'])]);

        $this->review->parse($rep, 'rep@tricab.com', '4C 16mm2', CorrelationId::generate());
        $sent = end($this->parser->parses);
        self::assertNotFalse($sent);
        $corrections = (array) ($sent->toPayload()['corrections'] ?? []);
        self::assertSame(['product_code' => 'XL-PAXN/4C16OR', 'confirmations' => 1], array_values($corrections)[0] ?? null);
    }

    public function test_an_unknown_rfq_is_not_found(): void
    {
        self::assertNull($this->review->save($this->sales($this->repId), 'rep@tricab.com', '01234567-89ab-4def-8123-456789abcdef', 1, [], CorrelationId::generate()));
    }

    /** Match Text, then Save as Draft with every line kept and a blank header. */
    private function paste(RfqActor $actor, string $email, string $text): CreatedRfq
    {
        $parsed = $this->review->parse($actor, $email, $text, CorrelationId::generate());
        $keep = array_map(static fn (ParsedLine $l): array => ['lineUid' => $l->lineUid, 'approved' => null, 'comment' => (string) $l->comment], $parsed->lines);

        return $this->review->create($actor, $email, $parsed->parseToken, QuoteHeaderEdit::fromInput([]), $keep, CorrelationId::generate());
    }

    private function open(RfqActor $actor, string $publicId): RfqDetail
    {
        $detail = $this->queue->detail($actor, $publicId);
        self::assertNotNull($detail);

        return $detail;
    }

    /** @return list<ParsedLine> */
    private function lines(RfqDetail $detail): array
    {
        self::assertNotNull($detail->revision);

        return $detail->revision->lines;
    }

    /**
     * @param list<ParsedLine> $lines
     *
     * @return list<array{lineUid: string, approved: bool, comment: string}>
     */
    private static function keepAll(array $lines): array
    {
        return array_map(static fn (ParsedLine $l): array => ['lineUid' => $l->lineUid, 'approved' => false, 'comment' => (string) $l->comment], $lines);
    }

    /** @return list<array<string, mixed>> */
    private function column(string $draftId, string $table, string $columns, string $order): array
    {
        $statement = $this->pdo->prepare("SELECT {$columns} FROM [rfq].[{$table}] WHERE [draft_id] = ? ORDER BY {$order}");
        $statement->execute([$draftId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private static function str(mixed $value): string
    {
        self::assertTrue(is_string($value) || is_int($value), 'a text or integer column');

        return (string) $value;
    }

    private function draftStatus(string $publicId): string
    {
        $statement = $this->pdo->prepare('SELECT [status] FROM [rfq].[rfq_draft_quotes] WHERE [id] = ?');
        $statement->execute([StoreProjection::draftId($publicId)]);

        return self::str($statement->fetchColumn());
    }

    private function sales(int $userId): RfqActor
    {
        return new RfqActor($userId, 'user-' . $userId, Module::ROLE_PERMISSIONS[Module::ROLE_SALES], 'VIC');
    }

    private function user(string $email): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId])
             OUTPUT INSERTED.[UserId] VALUES (?, ?, ?, 1)',
        );
        $statement->execute([$email, $email, ucfirst(explode('@', $email)[0])]);

        return (int) $statement->fetchColumn();
    }
}
