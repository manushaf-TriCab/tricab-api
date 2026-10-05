<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq;

use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqRepository;
use TriCab\Api\Tests\Rfq\Persistence\LocalRfqSqlServer;
use TriCab\Core\Rfq\Application\DTO\SyncOutcome;
use TriCab\Core\Rfq\Application\Service\ReadRfqQueueService;
use TriCab\Core\Rfq\Application\Service\RfqAliflexService;
use TriCab\Core\Rfq\Application\Service\RfqReviewService;
use TriCab\Core\Rfq\Application\Service\RfqSyncService;
use TriCab\Core\Rfq\Contract\RfqOperatorDirectory;
use TriCab\Core\Rfq\Domain\Exception\RfqAliflexNotEligible;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncBlocked;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncNeedsReconciliation;
use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Module;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\PythonJson;
use TriCab\Core\Rfq\Storage\StoreProjection;
use TriCab\Core\Rfq\Sync\GatewayHeaderResult;
use TriCab\Core\Rfq\Sync\GatewayLineResult;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingCustomerDirectory;
use TriCab\Core\Tests\Rfq\Fixtures\RecordingQuoteGateway;

/**
 * The sync ledger on SQL Server (parity plan E7.3): the intent is on disk before
 * 4js is contacted, the lock holds, an uncertain attempt holds the RFQ, and a
 * crashed attempt is closed with its reason. The 4js side is a recording fake;
 * nothing here can reach a real endpoint.
 *
 * Needs a local SQL Server ({@see LocalRfqSqlServer}); skips without one.
 */
#[Group('local-sql-server')]
final class RfqSyncLedgerTest extends TestCase
{
    private PDO $pdo;
    private FixtureRfqParserClient $parser;
    private RecordingQuoteGateway $gateway;
    private AzureSqlRfqDraftStore $store;
    private ReadRfqQueueService $queue;
    private RfqSyncService $sync;
    private RfqAliflexService $aliflex;
    private FixedClock $clock;
    private int $repId;
    private string $draftId;
    private string $publicId;

    protected function setUp(): void
    {
        $pdo = LocalRfqSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set RFQ_TEST_SQLSERVER and RFQ_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalRfqSqlServer::truncate($pdo);
        $this->repId = $this->user('rep@tricab.com');

        $this->clock = FixedClock::utc('2026-10-02T02:00:00');
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $repository = new AzureSqlRfqRepository($connection);
        $this->store = new AzureSqlRfqDraftStore($connection, $this->clock);
        $this->parser = new FixtureRfqParserClient();
        $this->gateway = new RecordingQuoteGateway();
        $operators = new class () implements RfqOperatorDirectory {
            public function codeFor(string $email): string
            {
                return 'REPCODE';
            }
        };
        $this->sync = new RfqSyncService($this->parser, $repository, $this->store, $this->store, $this->gateway, $operators, null, $this->clock, true);
        $this->queue = new ReadRfqQueueService($repository);
        $review = new RfqReviewService($this->parser, $this->store, $repository, $operators, new RecordingCustomerDirectory(), $this->clock, null);
        $this->aliflex = new RfqAliflexService($this->parser, $repository, $this->store, $review);
        $this->parser->costs = ['XL-PAXN/4C16OR' => 5.0, 'KL-PAXN/4C16OR' => 6.0];
        $this->makeDraft();
    }

    private function makeDraft(string $assignee = 'someone.else@tricab.com'): void
    {
        $row = static fn (string $uid, string $code, float $sell): array => [
            'line_uid' => $uid,
            'raw_text' => '3C 2.5 ' . $code,
            'source' => ['original_text' => '3C 2.5', 'input_type' => 'pasted_text'],
            'final_product_code' => $code,
            'selected_product_code' => $code,
            'length' => ['number_of_lengths' => 2, 'length_each' => 25, 'total_length' => 50],
            'status' => 'matched',
            'final_status' => 'matched',
            'unit_price' => 4.0,
            'final_unit_price' => $sell,
            'margin_percent' => 50,
            'line_note' => 'Site A',
            'pricing' => ['status' => 'available', 'unit_price' => 4.0],
        ];
        $this->draftId = $this->store->create(new NewDraft(
            source: 'web',
            label: 'Sync test',
            quoteDetails: ['customer_id' => '123', 'contact' => 'Buyer', 'author' => 'OLDREP', 'salesoffice' => 'PER', 'payterms' => 'EOM30', 'region' => 'australia', 'currency' => 'AUD'],
            rows: [$row('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1', 'TPS3C25', 8.0), $row('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2', 'XL-PAXN/4C16OR', 12.5)],
            emailMeta: ['parse' => ['coverage' => ['inputRows' => 2, 'keptRows' => 2, 'excludedRows' => 0, 'skippedRows' => 0, 'measured' => true]]],
            creatorEmail: 'rep@tricab.com',
            branch: 'VIC',
            assigneeEmail: $assignee,
        ));
        $this->publicId = StoreProjection::publicId($this->draftId);
    }

    private function rep(): RfqActor
    {
        return new RfqActor($this->repId, 'user-' . $this->repId, Module::ROLE_PERMISSIONS[Module::ROLE_SALES], 'VIC');
    }

    private function press(int $revision = 1): SyncOutcome
    {
        $outcome = $this->sync->sync($this->rep(), 'rep@tricab.com', $this->publicId, $revision, CorrelationId::fromHeaderOrGenerate('corr-ledger-0001'));
        self::assertNotNull($outcome);

        return $outcome;
    }

    /** @return array<string, mixed> */
    private function attemptRow(?string $id = null): array
    {
        $sql = 'SELECT * FROM [rfq].[rfq_sync_attempts] WHERE [draft_id] = ?' . ($id === null ? ' ORDER BY [created_at] DESC' : ' AND [id] = ?');
        $statement = $this->pdo->prepare($sql);
        $statement->execute($id === null ? [$this->draftId] : [$this->draftId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        /** @var array<string, mixed> $row */
        return $row;
    }

    private function attemptCount(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM [rfq].[rfq_sync_attempts] WHERE [draft_id] = ?');
        $statement->execute([$this->draftId]);

        return self::int($statement->fetchColumn());
    }

    /** @return array<string, mixed> */
    private function draftRow(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM [rfq].[rfq_draft_quotes] WHERE [id] = ?');
        $statement->execute([$this->draftId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        /** @var array<string, mixed> $row */
        return $row;
    }

    /** @return list<string> */
    private function activityTypes(): array
    {
        $statement = $this->pdo->prepare('SELECT [event_type] FROM [rfq].[rfq_draft_activity] WHERE [draft_id] = ? ORDER BY [created_at], [id]');
        $statement->execute([$this->draftId]);

        return array_values(array_map(self::text(...), $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function test_a_successful_sync_is_on_disk_before_4js_hears_of_it_and_settles_the_draft_as_completed(): void
    {
        $atHeader = null;
        $atFirstLine = null;
        $this->gateway->onCall = function (string $type) use (&$atHeader, &$atFirstLine): void {
            if ($type === 'header') {
                $atHeader = [$this->attemptRow(), $this->draftRow()];
            }
            if ($type === 'line' && $atFirstLine === null) {
                $atFirstLine = [$this->attemptRow(), $this->draftRow()];
            }
        };

        $outcome = $this->press();

        // The moment the header request was made, the intent was already committed.
        self::assertNotNull($atHeader);
        self::assertSame('running', $atHeader[0]['state']);
        self::assertSame('sent', $atHeader[0]['header_state'], 'sent is written before the request leaves');
        self::assertNotNull($atHeader[0]['header_sent_at']);
        self::assertNull($atHeader[0]['refno']);
        self::assertNotNull($atHeader[1]['sync_started_at'], 'the sync lock was already held');
        // And the quote number was durable before the first line went.
        self::assertNotNull($atFirstLine);
        self::assertSame('confirmed', $atFirstLine[0]['header_state']);
        self::assertSame('721999', $atFirstLine[0]['refno']);
        self::assertSame('721999', $atFirstLine[1]['refno'], 'the draft carries the quote number as soon as 4js allocates it');

        self::assertSame(SyncOutcome::SUCCEEDED, $outcome->outcome);
        $attempt = $this->attemptRow();
        self::assertSame('succeeded', $attempt['state']);
        self::assertSame('confirmed', $attempt['header_state']);
        self::assertSame(2, self::int($attempt['lines_total']));
        self::assertSame(2, self::int($attempt['lines_sent']));
        self::assertSame(0, self::int($attempt['lines_failed']));
        self::assertSame(2, self::int($attempt['next_line']));
        self::assertSame(1, self::int($attempt['revision']));
        self::assertSame('rep@tricab.com', $attempt['actor_email']);
        self::assertSame('portal', $attempt['origin']);
        self::assertSame(hash('sha256', self::text($attempt['payload'])), $attempt['payload_sha256'], 'the stored hash is of the stored text');
        self::assertStringNotContainsString(', ', self::text($attempt['payload']), 'compact, as the source writes it');
        $progress = PythonJson::decodeObject(self::text($attempt['progress']));
        self::assertSame('corr-ledger-0001', $progress['correlation_id']);
        self::assertSame($this->draftId . ':1:' . $attempt['payload_sha256'], $progress['idempotency_key']);
        $result = PythonJson::decodeObject(self::text($attempt['result']));
        self::assertTrue($result['ok']);
        self::assertSame(2, $result['pushed']);

        $draft = $this->draftRow();
        self::assertSame('synced', $draft['status']);
        self::assertSame('721999', $draft['refno']);
        self::assertSame('1', $draft['refver']);
        self::assertNotNull($draft['synced_at']);
        self::assertNull($draft['sync_started_at']);
        self::assertNull($draft['sync_revision']);
        self::assertNull($draft['push_error']);
        self::assertSame(2, self::int($draft['revision']), 'the draft changed, so its revision advances');
        self::assertSame(1, PythonJson::decodeObject(self::text($draft['sync_payload']))['synced_revision']);
        // The worker became the sales rep and the author when the attempt was created.
        self::assertSame('rep@tricab.com', $draft['assignee_email']);
        self::assertSame('REPCODE', PythonJson::decodeObject(self::text($draft['quote_details']))['author']);

        // (One clock tick, so the order within it is the database's; the ledger's order is proved by the in-memory run.)
        self::assertEqualsCanonicalizing(['created', 'sync_queued', 'sync_finished'], $this->activityTypes());
        $detail = $this->queue->detail($this->rep(), $this->publicId);
        self::assertNotNull($detail);
        self::assertSame('completed', $detail->summary->record->workflowStatus->toString());
        self::assertSame('721999', $detail->summary->record->quoteNumber);
        self::assertCount(1, $detail->syncAttempts);
        self::assertSame('succeeded', $detail->syncAttempts[0]->state->toString());
        self::assertSame('1', $detail->syncAttempts[0]->quoteVersion);
    }

    public function test_an_edit_or_an_archive_while_a_sync_runs_is_refused(): void
    {
        $refused = [];
        $this->gateway->onCall = function (string $type) use (&$refused): void {
            if ($type !== 'header') {
                return;
            }
            $worker = new DraftActor('rep@tricab.com', 'sales', 'VIC');
            try {
                $this->store->saveContent($this->draftId, 1, ['customer_id' => '1'], [], 'in_progress', $worker);
            } catch (RfqDraftNotEditable $e) {
                $refused[] = $e->reason;
            }
            try {
                $this->store->archive($this->draftId, 1, 'no', $worker);
            } catch (RfqDraftNotEditable $e) {
                $refused[] = $e->reason;
            }
            try {
                $this->press();
            } catch (RfqDraftNotEditable $e) {
                $refused[] = 'second sync: ' . $e->reason;
            }
        };

        $this->press();

        self::assertSame([RfqDraftNotEditable::SYNC_RUNNING, RfqDraftNotEditable::SYNC_RUNNING, 'second sync: ' . RfqDraftNotEditable::SYNC_BLOCKED], $refused);
        self::assertSame(1, $this->attemptCount(), 'the duplicate click made no second attempt');
        self::assertSame(['header', 'line', 'line', 'pdf'], $this->gateway->sequence(), 'and sent nothing twice');
    }

    public function test_a_header_that_did_not_complete_holds_the_rfq_until_a_person_resolves_it(): void
    {
        $this->gateway->header = GatewayHeaderResult::unknown('timed out');

        $outcome = $this->press();

        self::assertSame(SyncOutcome::UNCERTAIN, $outcome->outcome);
        $attempt = $this->attemptRow();
        self::assertSame('uncertain', $attempt['state']);
        self::assertSame('unknown', $attempt['header_state']);
        self::assertNull($attempt['refno']);
        self::assertStringContainsString('may or may not hold a quote', self::text($attempt['reconcile_note']));
        $draft = $this->draftRow();
        self::assertSame('failed', $draft['status']);
        self::assertNull($draft['sync_started_at'], 'the lock is released; the uncertain attempt is the hold');
        self::assertSame(2, self::int($draft['revision']));

        $calls = count($this->gateway->calls);
        try {
            $this->press(2);
            self::fail('a second attempt must wait for 4js to be checked');
        } catch (RfqSyncNeedsReconciliation $held) {
            self::assertTrue($held->attempt->toArray()['needsReconciliation']);
        }
        self::assertCount($calls, $this->gateway->calls, 'nothing was sent');
        self::assertSame(1, $this->attemptCount());
    }

    public function test_a_refused_header_leaves_a_failed_attempt_and_a_retry_is_allowed(): void
    {
        $this->gateway->header = GatewayHeaderResult::rejected('unknown customer');

        $outcome = $this->press();

        self::assertSame(SyncOutcome::FAILED, $outcome->outcome);
        $attempt = $this->attemptRow();
        self::assertSame('failed', $attempt['state']);
        self::assertSame('rejected', $attempt['header_state']);
        self::assertSame('failed', $this->draftRow()['status']);

        // Certain, so a corrected retry is safe: a new attempt against a fresh quote.
        $this->gateway->header = GatewayHeaderResult::accepted('721888', '1');
        $again = $this->press(2);
        self::assertSame(SyncOutcome::SUCCEEDED, $again->outcome);
        self::assertSame(2, $this->attemptCount());
        self::assertSame('0', self::at($this->gateway->calls, 1, 'args', 'header', 'ref'), 'a fresh quote, not a version of one that was never made');
    }

    public function test_a_partial_keeps_the_quote_number_and_the_retry_raises_a_new_version_of_that_quote(): void
    {
        $this->gateway->lineAnswers[1] = GatewayLineResult::rejected('Unknown product');

        $outcome = $this->press();

        self::assertSame(SyncOutcome::PARTIAL, $outcome->outcome);
        $attempt = $this->attemptRow();
        self::assertSame('failed', $attempt['state']);
        self::assertSame(1, self::int($attempt['lines_sent']));
        self::assertSame(1, self::int($attempt['lines_failed']));
        $progress = PythonJson::decodeObject(self::text($attempt['progress']));
        self::assertSame('XL-PAXN/4C16OR', self::at($progress, 'errors', 0, 'code'));
        self::assertSame(2, self::at($progress, 'errors', 0, 'row_number'));
        $draft = $this->draftRow();
        self::assertSame('failed', $draft['status']);
        self::assertSame('721999', $draft['refno']);
        self::assertStringContainsString('1 of 2 line(s) were rejected by 4js', self::text($draft['push_error']));
        $stored = PythonJson::decodeObject(self::text($draft['sync_payload']));
        self::assertSame(1, $stored['pushed']);
        self::assertSame(1, $stored['failed']);

        // Corrected, it goes again as a new version of the same quote, never a second quote.
        $this->gateway->lineAnswers = [];
        $this->gateway->header = GatewayHeaderResult::accepted('721999', '2');
        $retry = $this->press(2);
        self::assertSame(SyncOutcome::SUCCEEDED, $retry->outcome);
        self::assertTrue($retry->isUpdate);
        $last = $this->parser->syncPlanCalls[1];
        self::assertSame('721999', $last['existingRefno']);
        self::assertSame(2, $last['version']);
        self::assertSame('2', $this->draftRow()['refver']);
    }

    public function test_an_attempt_a_dead_worker_left_running_is_closed_by_the_next_sync_with_its_reason(): void
    {
        // A process killed after the header request left and before an answer was recorded.
        $worker = new DraftActor('rep@tricab.com', 'sales', 'VIC', 'REPCODE');
        $rep = ['quoteDetails' => ['customer_id' => '123', 'author' => 'REPCODE'], 'assignee' => 'rep@tricab.com', 'authorSource' => 'operator', 'assigneeChange' => null];
        $id = $this->store->begin($this->draftId, 1, $worker, $rep, ['line_items' => [[], []]], '{"line_items":[[],[]]}', hash('sha256', '{"line_items":[[],[]]}'), 'corr-crash-0001', 'portal');
        self::assertNotNull($id);
        $this->store->noteHeaderIntent($id);
        self::assertSame('running', $this->attemptRow($id)['state']);

        // Within the stale window it is still somebody's, and nothing may start.
        try {
            $this->press();
            self::fail('a live sync holds the RFQ');
        } catch (RfqDraftNotEditable $e) {
            self::assertSame(RfqDraftNotEditable::SYNC_BLOCKED, $e->reason);
        }

        // Past it, the attempt is closed: the header was in flight with no number, so uncertain, never retried.
        $this->clock->advance('+16 minutes');
        try {
            $this->press(1);
            self::fail('the revision moved when the stale attempt was closed');
        } catch (RfqRevisionConflict $conflict) {
            self::assertSame(2, $conflict->currentRevision);
        }
        $closed = $this->attemptRow($id);
        self::assertSame('uncertain', $closed['state']);
        self::assertStringContainsString('interrupted while the quote header was being sent', self::text($closed['error']));
        self::assertNull($this->draftRow()['sync_started_at']);
        try {
            $this->press(2);
            self::fail('an uncertain attempt holds the RFQ');
        } catch (RfqSyncNeedsReconciliation) {
        }
        self::assertSame([], $this->gateway->calls);
    }

    public function test_a_crash_after_the_quote_exists_is_closed_as_failed_and_the_retry_versions_that_quote(): void
    {
        $worker = new DraftActor('rep@tricab.com', 'sales', 'VIC', 'REPCODE');
        $rep = ['quoteDetails' => ['customer_id' => '123', 'author' => 'REPCODE'], 'assignee' => 'rep@tricab.com', 'authorSource' => 'operator', 'assigneeChange' => null];
        $id = (string) $this->store->begin($this->draftId, 1, $worker, $rep, ['line_items' => [[], []]], '{"line_items":[[],[]]}', hash('sha256', '{"line_items":[[],[]]}'), 'corr-crash-0002', 'portal');
        $this->store->noteHeaderIntent($id);
        $this->store->noteHeaderOk($id, '721777', '1');
        $this->store->noteLine($id, 0, true, ['code' => 'TPS3C25'], 1);

        $this->clock->advance('+16 minutes');
        self::assertSame(1, $this->store->sweepStale($this->draftId, '2026-10-02T02:10:00+00:00'));

        $closed = $this->attemptRow($id);
        self::assertSame('failed', $closed['state']);
        self::assertStringContainsString('Syncing again raises a new version of that quote', self::text($closed['error']));
        $draft = $this->draftRow();
        self::assertSame('failed', $draft['status']);
        self::assertSame('721777', $draft['refno'], 'the number 4js allocated is not lost');
        self::assertNull($draft['sync_started_at']);

        $this->gateway->header = GatewayHeaderResult::accepted('721777', '2');
        $outcome = $this->press(2);
        self::assertSame(SyncOutcome::SUCCEEDED, $outcome->outcome);
        self::assertSame('721777', $this->parser->syncPlanCalls[0]['existingRefno']);
    }

    public function test_a_second_sweep_changes_nothing_and_a_fresh_attempt_is_not_swept(): void
    {
        $worker = new DraftActor('rep@tricab.com', 'sales', 'VIC');
        $rep = ['quoteDetails' => ['customer_id' => '123'], 'assignee' => 'rep@tricab.com', 'authorSource' => null, 'assigneeChange' => null];
        $id = (string) $this->store->begin($this->draftId, 1, $worker, $rep, ['line_items' => []], '{}', hash('sha256', '{}'), 'corr-sweep-0001', '');

        self::assertSame(0, $this->store->sweepStale($this->draftId, '2026-10-02T01:00:00+00:00'), 'touched since the cut-off: still live');
        self::assertSame('running', $this->attemptRow($id)['state']);
        self::assertSame(1, $this->store->sweepStale($this->draftId, '2026-10-02T03:00:00+00:00'));
        self::assertSame(0, $this->store->sweepStale($this->draftId, '2026-10-02T03:00:00+00:00'));
        self::assertSame('failed', $this->attemptRow($id)['state'], 'nothing was sent: closed as failed, a retry starts a new attempt');
    }

    public function test_the_intent_checks_run_under_the_lock_and_leave_nothing_behind_when_they_refuse(): void
    {
        // A stale revision.
        try {
            $this->press(7);
            self::fail('expected a conflict');
        } catch (RfqRevisionConflict $conflict) {
            self::assertSame(1, $conflict->currentRevision);
        }

        // A document still being read.
        $this->readerJob('queued', null);
        try {
            $this->press();
            self::fail('a read in flight must hold the sync');
        } catch (RfqDraftNotEditable | RfqSyncBlocked $held) {
            self::assertStringContainsString('read', strtolower($held->getMessage()));
        }

        self::assertSame(0, $this->attemptCount());
        self::assertNull($this->draftRow()['sync_started_at']);
        self::assertSame([], $this->gateway->calls);
    }

    public function test_the_store_refuses_a_draft_with_a_failed_read_or_an_open_proposal_with_the_sources_words(): void
    {
        $worker = new DraftActor('rep@tricab.com', 'sales', 'VIC');
        $rep = ['quoteDetails' => ['customer_id' => '123'], 'assignee' => 'rep@tricab.com', 'authorSource' => null, 'assigneeChange' => null];
        $begin = fn () => $this->store->begin($this->draftId, 1, $worker, $rep, ['line_items' => []], '{}', hash('sha256', '{}'), 'corr-reads-0001', '');

        $this->readerJob('queued', null);
        self::assertSame('A document is still being read. Wait for the resulting lines before syncing.', $this->refusal($begin));
        $this->pdo->exec('DELETE FROM [rfq].[rfq_reader_jobs]');

        $this->readerJob('failed', null, 'schedule.xlsx');
        self::assertSame('schedule.xlsx could not be read, so its lines are not on this quote. Add them by hand or dismiss the failed read before syncing.', $this->refusal($begin));
        $this->pdo->exec('DELETE FROM [rfq].[rfq_reader_jobs]');

        $this->readerJob('done', '{"rows": [1]}', 'schedule.xlsx');
        self::assertSame('A re-read of schedule.xlsx is offering replacement lines. Accept or dismiss it before syncing.', $this->refusal($begin));
        $this->pdo->exec('DELETE FROM [rfq].[rfq_reader_jobs]');

        $this->readerJob('dismissed', '{"rows": [1]}', 'schedule.xlsx');
        self::assertNotNull($begin(), 'a dismissed proposal is settled');
    }

    public function test_an_archived_draft_and_one_already_in_4js_are_refused(): void
    {
        $this->store->archive($this->draftId, 1, 'duplicate', new DraftActor('rep@tricab.com', 'sales', 'VIC'));
        try {
            $this->press();
            self::fail('an archived RFQ must not sync');
        } catch (RfqDraftNotEditable $e) {
            self::assertSame(RfqDraftNotEditable::ARCHIVED, $e->reason);
        }
        $this->store->restore($this->draftId, 1, new DraftActor('rep@tricab.com', 'sales', 'VIC'));

        $this->press();
        try {
            $this->press(2);
            self::fail('a quote already in 4js must not be sent again');
        } catch (RfqDraftNotEditable $e) {
            self::assertSame(RfqDraftNotEditable::ALREADY_SYNCED, $e->reason);
            self::assertStringStartsWith('Quote 721999 (v1) is in 4js.', $e->getMessage());
        }
        self::assertSame(1, $this->attemptCount());
    }

    public function test_the_ledger_will_not_close_an_attempt_twice_or_succeed_without_a_confirmed_header(): void
    {
        $this->press();
        $id = self::text($this->attemptRow()['id']);
        $worker = new DraftActor('rep@tricab.com', 'sales', 'VIC');

        $this->expectException(RfqTransitionNotPermitted::class);
        $this->store->finishAttempt($id, SyncAttemptState::fromString(SyncAttemptState::FAILED), [], 'again', '', $worker);
    }

    public function test_a_quote_that_cannot_be_planned_leaves_no_attempt_and_no_lock(): void
    {
        $this->parser->syncProblem = ['status' => 400, 'payload' => ['ok' => false, 'error' => 'Fix the listed issues before syncing. No quote was sent to 4js.', 'blocking_issues' => []]];

        try {
            $this->press();
            self::fail('expected the quote to be refused');
        } catch (RfqSyncBlocked) {
        }

        self::assertSame(0, $this->attemptCount());
        self::assertNull($this->draftRow()['sync_started_at']);
        self::assertSame(['created'], $this->activityTypes());
    }

    public function test_a_synced_quote_becomes_an_aliflex_alternative_draft_and_the_alternative_cannot_be_converted_again(): void
    {
        $before = $this->queue->detail($this->rep(), $this->publicId);
        self::assertNotNull($before);
        $unfinished = $before->toArray();
        self::assertFalse(self::at($unfinished, 'aliflex', 'eligible'));
        self::assertSame('Sync this quote to 4js first. An ALI-FLEX alternative is made from a finished quote: one synced to 4js, or an imported TriCab quote.', self::at($unfinished, 'aliflex', 'reason'));
        try {
            $this->aliflex->create($this->rep(), 'rep@tricab.com', $this->publicId, 1, CorrelationId::generate());
            self::fail('an unfinished quote must not convert');
        } catch (RfqAliflexNotEligible) {
        }

        $this->press();
        $source = $this->queue->detail($this->rep(), $this->publicId);
        self::assertNotNull($source);
        self::assertTrue(self::at($source->toArray(), 'aliflex', 'eligible'), 'a quote synced to 4js with no change since is finished');
        $sourceRevision = $source->summary->record->revision;
        $sourceRows = $this->column($this->draftId);

        $result = $this->aliflex->create($this->rep(), 'rep@tricab.com', $this->publicId, $sourceRevision, CorrelationId::fromHeaderOrGenerate('corr-aliflex-sql1'));

        self::assertNotNull($result);
        self::assertNotNull($result->publicId);
        self::assertSame(['changed' => 1, 'unchanged' => 1, 'review' => 0], $result->counts);
        $newId = (string) StoreProjection::draftId((string) $result->publicId);
        $statement = $this->pdo->prepare('SELECT * FROM [rfq].[rfq_draft_quotes] WHERE [id] = ?');
        $statement->execute([$newId]);
        $new = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($new);
        self::assertSame('alternative', $new['source']);
        self::assertSame('ALI-FLEX alternative · Sync test', $new['label']);
        self::assertSame('new', $new['status']);
        self::assertSame(1, self::int($new['revision']));
        self::assertNull($new['refno'], 'a new draft has no 4js quote');
        self::assertSame('rep@tricab.com', $new['assignee_email']);
        $meta = PythonJson::decodeObject(self::text($new['email_meta']));
        self::assertSame($this->draftId, self::at($meta, 'aliflex', 'source_draft_id'));
        self::assertSame('synced', self::at($meta, 'aliflex', 'source_status'));
        self::assertSame('721999', self::at($meta, 'aliflex', 'source_refno'));
        self::assertSame('REPCODE', PythonJson::decodeObject(self::text($new['quote_details']))['author']);
        $rowsStatement = $this->pdo->prepare('SELECT [row_data], [line_uid] FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = ? ORDER BY [row_order]');
        $rowsStatement->execute([$newId]);
        $rows = $rowsStatement->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        $unchanged = PythonJson::decodeObject(self::text(self::at($rows, 0, 'row_data')));
        $changed = PythonJson::decodeObject(self::text(self::at($rows, 1, 'row_data')));
        self::assertSame('TPS3C25', $unchanged['final_product_code']);
        self::assertSame(4.0, $unchanged['unit_price'], 'an unchanged line keeps the price it had');
        self::assertSame('KL-PAXN/4C16OR', $changed['final_product_code']);
        self::assertSame('changed', self::at($changed, 'aliflex', 'status'));
        self::assertSame(6.0, $changed['unit_price'], 'priced afresh for the new product');
        self::assertNotSame(self::at($sourceRows, 1, 'line_uid'), self::at($rows, 1, 'line_uid'), 'the new draft has its own line identity');
        $activity = $this->pdo->prepare('SELECT [event_type] FROM [rfq].[rfq_draft_activity] WHERE [draft_id] = ?');
        $activity->execute([$newId]);
        self::assertSame(['created'], array_map(self::text(...), $activity->fetchAll(PDO::FETCH_COLUMN)));

        // The source quote is exactly as it was: same revision, same rows.
        $after = $this->queue->detail($this->rep(), $this->publicId);
        self::assertNotNull($after);
        self::assertSame($sourceRevision, $after->summary->record->revision);
        self::assertSame($sourceRows, $this->column($this->draftId));
        self::assertSame('completed', $after->summary->record->workflowStatus->toString());

        // The new draft is an ordinary draft the queue can open; it is not eligible again, nor synced.
        $opened = $this->queue->detail($this->rep(), (string) $result->publicId);
        self::assertNotNull($opened);
        self::assertSame('new', $opened->summary->record->workflowStatus->toString());
        self::assertFalse(self::at($opened->toArray(), 'aliflex', 'eligible'));
        self::assertSame('This quote is already an ALI-FLEX alternative.', self::at($opened->toArray(), 'aliflex', 'reason'));
        self::assertSame(['header', 'line', 'line', 'pdf'], $this->gateway->sequence(), 'ALIFLEX sent nothing to 4js');
        try {
            $this->aliflex->create($this->rep(), 'rep@tricab.com', (string) $result->publicId, 1, CorrelationId::generate());
            self::fail('an alternative must not be converted again');
        } catch (RfqAliflexNotEligible $refused) {
            self::assertSame('This quote is already an ALI-FLEX alternative.', $refused->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    private function column(string $draftId): array
    {
        $statement = $this->pdo->prepare('SELECT [line_uid], [row_data] FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = ? ORDER BY [row_order]');
        $statement->execute([$draftId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function refusal(callable $begin): string
    {
        try {
            $begin();
        } catch (RfqDraftNotEditable $e) {
            return $e->getMessage();
        }
        self::fail('expected a refusal');
    }

    private function readerJob(string $status, ?string $proposal, string $file = 'schedule.xlsx'): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [rfq].[rfq_reader_jobs] ([id], [created_at], [updated_at], [draft_id], [file_name], [spool_path], [status], [attempts], [proposal])
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)',
        );
        $statement->execute([bin2hex(random_bytes(16)), '2026-10-02T01:00:00+00:00', '2026-10-02T01:00:00+00:00', $this->draftId, $file, 'rfq-document:' . bin2hex(random_bytes(16)), $status, $proposal]);
    }

    private static function int(mixed $value): int
    {
        self::assertTrue(is_int($value) || (is_string($value) && is_numeric($value)), 'an integer column');

        return (int) $value;
    }

    private static function text(mixed $value): string
    {
        self::assertTrue(is_string($value) || is_int($value), 'a text column');

        return (string) $value;
    }

    /** Walks decoded JSON: `at($progress, 'errors', 0, 'code')`. */
    private static function at(mixed $value, string|int ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    private function user(string $email): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [identity].[Users] ([EmailAddress], [NormalizedEmailAddress], [DisplayName], [UserStatusId])
             OUTPUT INSERTED.[UserId] VALUES (?, ?, ?, 1)',
        );
        $statement->execute([$email, $email, ucfirst(explode('@', $email)[0])]);

        return self::int($statement->fetchColumn());
    }
}
