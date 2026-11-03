<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq\Persistence;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Rfq\Persistence\AzureSqlRfqDraftStore;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Storage\DraftActor;
use TriCab\Core\Rfq\Storage\NewDraft;
use TriCab\Core\Rfq\Storage\PythonJson;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Tests\Rfq\Fixtures\DraftStoreGolden;

/**
 * Plan 2.4 and 2.6: the draft store against a real SQL Server.
 *
 * `DraftStoreParityTest` proves the line and change-log rules. This proves the
 * SQL around them, on the schema `create-rfq-schema.sql` makes:
 *
 *  - a create and an edit write **exactly** what the source store wrote for
 *    the same input (the golden fixture), in all four tables;
 *  - a stale save changes nothing;
 *  - two processes saving the same revision at once: one wins, one conflicts;
 *  - a save waits on another save's lock rather than reading past it;
 *  - 500 lines (more than one insert chunk), Unicode and NaN survive.
 *
 * Needs a local SQL Server ({@see LocalRfqSqlServer}); skips without one.
 */
#[Group('local-sql-server')]
final class AzureSqlRfqDraftStoreTest extends TestCase
{
    private PDO $pdo;

    private int $nextId = 0;

    protected function setUp(): void
    {
        $pdo = LocalRfqSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set RFQ_TEST_SQLSERVER and RFQ_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalRfqSqlServer::truncate($pdo);
    }

    public function testCreateAndEditWriteExactlyWhatTheSourceWrote(): void
    {
        $create = DraftStoreGolden::tables('create');
        $store = $this->store(fixedIds: true);

        $this->nextId = 0;
        $id = $store->create(new NewDraft(
            source: 'web',
            label: 'ACME RFQ',
            quoteDetails: PythonJson::decodeObject($create['draft']['quote_details'] ?? null),
            rows: DraftStoreGolden::createRows(),
            creatorEmail: ' Rep@TriCab.com ',
            branch: 'vic',
        ));
        self::assertSame($create['draft']['id'], $id);
        self::assertSame(self::expected($create), $this->state($id));

        $this->nextId = DraftStoreGolden::int('edit', 'ids_start');
        $revision = $store->saveContent(
            $id,
            1,
            DraftStoreGolden::object('edit', 'input_header'),
            DraftStoreGolden::lines('edit', 'input_rows'),
            'in_progress',
            new DraftActor('rep@tricab.com', 'sales', 'VIC'),
            'simple',
        );
        self::assertSame(2, $revision);
        // The source wrote exactly this, plus one deliberate addition: the save
        // now records whose author the quote carries (SalesRepAttribution). The
        // rep did not change here (same person; no operator code confirmed).
        $expected = self::expected(DraftStoreGolden::tables('edit'));
        $last = count($expected['activity']) - 1;
        $detail = PythonJson::decodeObject($expected['activity'][$last]['detail'] ?? null);
        $expected['activity'][$last]['detail'] = PythonJson::encode($detail + ['author_source' => 'kept']);
        self::assertSame($expected, $this->state($id));
    }

    public function testSavingMakesTheWorkerTheSalesRepInBothFields(): void
    {
        $store = $this->store();
        $id = $store->create(new NewDraft('email', 'rfq', ['author' => 'SCOTTH', 'customer_code' => '1'], [['raw_text' => 'x']], creatorEmail: 'scott@tricab.com'));
        $saved = $store->load($id);
        self::assertNotNull($saved);

        $store->saveContent($id, 1, $saved->quoteDetails, $saved->rows, 'in_progress', new DraftActor('Felicity@TriCab.com', 'sales', 'VIC', 'felicityb'));

        $after = $store->load($id);
        self::assertNotNull($after);
        self::assertSame('felicity@tricab.com', $after->columns['assignee_email']);
        self::assertSame('FELICITYB', $after->quoteDetails['author']);
        self::assertSame('scott@tricab.com', $after->columns['created_by_email'], 'the creator is history and stays');
        $detail = PythonJson::decodeObject($this->scalar("SELECT TOP 1 [detail] FROM [rfq].[rfq_draft_activity] WHERE [draft_id] = '{$id}' AND [event_type] = 'content_updated'"));
        self::assertSame(['from' => 'scott@tricab.com', 'to' => 'felicity@tricab.com'], $detail['assignee'] ?? null);
        self::assertSame('operator', $detail['author_source'] ?? null);
        $changes = $detail['header_changes'] ?? null;
        self::assertIsArray($changes);
        self::assertContains(['field' => 'author', 'from' => 'SCOTTH', 'to' => 'FELICITYB'], $changes);
    }

    public function testStaleRevisionIsRefusedAndWritesNothing(): void
    {
        $store = $this->store();
        $id = $store->create($this->draft(3));
        $actor = new DraftActor('a@tricab.com', 'sales', 'VIC');
        $saved = $store->load($id);
        self::assertNotNull($saved);
        $store->saveContent($id, 1, ['region' => 'NSW'], $saved->rows, 'in_progress', $actor);
        $before = $this->state($id);

        try {
            $store->saveContent($id, 1, ['region' => 'QLD'], [], 'in_progress', $actor);
            self::fail('A save based on revision 1 must be refused once revision 2 exists.');
        } catch (RfqRevisionConflict $conflict) {
            self::assertSame(2, $conflict->currentRevision);
        }
        self::assertSame($before, $this->state($id), 'the refused save must leave every table as it was');
    }

    public function testTwoProcessesSavingTheSameRevisionOneWinsOneConflicts(): void
    {
        $store = $this->store();
        $id = $store->create($this->draft(120));
        $barrier = sys_get_temp_dir() . '/rfq-race-' . bin2hex(random_bytes(4));
        $processes = [];
        foreach (['first', 'second'] as $who) {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/Fixtures/save-once.php', $id, '1', $who, $barrier],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$who],
            );
            self::assertIsResource($process);
            $processes[$who] = $process;
        }
        usleep(300_000);
        touch($barrier);
        $results = [];
        foreach ($processes as $who => $process) {
            $results[$who] = trim((string) stream_get_contents($pipes[$who][1])) . trim((string) stream_get_contents($pipes[$who][2]));
            proc_close($process);
        }
        @unlink($barrier);

        sort($results);
        self::assertSame(['conflict 2', 'saved 2'], $results, 'exactly one save of revision 1 may win');
        self::assertSame(2, (int) $this->scalar("SELECT [revision] FROM [rfq].[rfq_draft_quotes] WHERE [id] = '{$id}'"));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM [rfq].[rfq_draft_activity] WHERE [draft_id] = '{$id}' AND [event_type] = 'content_updated'"));
        self::assertSame(120, (int) $this->scalar("SELECT COUNT(*) FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = '{$id}'"));
    }

    public function testASaveWaitsOnAnotherSavesLockInsteadOfReadingPastIt(): void
    {
        $store = $this->store();
        $id = $store->create($this->draft(2));

        // Session A: a save in progress, holding the draft's update lock.
        $a = $this->connection();
        $a->beginTransaction();
        $a->exec("SELECT [revision] FROM [rfq].[rfq_draft_quotes] WITH (UPDLOCK, HOLDLOCK, ROWLOCK) WHERE [id] = '{$id}'");

        // Session B gives up after 1 s instead of waiting forever: it must block.
        $b = $this->connection();
        $b->exec('SET LOCK_TIMEOUT 1000');
        $storeB = new AzureSqlRfqDraftStore(new PdoSqlConnection($b, new RequestPerformanceMetrics()), $this->clock());
        $started = microtime(true);
        try {
            $storeB->saveContent($id, 1, [], [], 'in_progress', new DraftActor('b@tricab.com', 'sales', null));
            self::fail('B saved while A held the lock.');
        } catch (Throwable $blocked) {
            self::assertStringContainsString('Lock request time out period exceeded', $blocked->getMessage() . ($blocked->getPrevious()?->getMessage() ?? ''), 'B must have been waiting on A\'s lock (SQL Server error 1222)');
        }
        self::assertGreaterThanOrEqual(0.9, microtime(true) - $started);

        // A finishes its save; B, retried, now sees revision 2 and conflicts.
        $a->exec("UPDATE [rfq].[rfq_draft_quotes] SET [revision] = 2 WHERE [id] = '{$id}'");
        $a->commit();
        $this->expectException(RfqRevisionConflict::class);
        $storeB->saveContent($id, 1, [], [], 'in_progress', new DraftActor('b@tricab.com', 'sales', null));
    }

    public function testFiveHundredLinesUnicodeAndNaNSurvive(): void
    {
        $store = $this->store();
        $id = $store->create($this->draft(500));
        $saved = $store->load($id);
        self::assertNotNull($saved);
        self::assertCount(500, $saved->rows);
        self::assertSame('Ø 16mm² × 3 core — 😀 499', $saved->rows[499]['raw_text']);
        self::assertNan($saved->rows[7]['score']);

        $rows = $saved->rows;
        $rows[10]['margin_percent'] = 55;
        unset($rows[20]);
        $revision = $store->saveContent($id, 1, ['region' => 'VIC'], array_values($rows), 'in_progress', new DraftActor('a@tricab.com', 'sales', 'VIC'));
        self::assertSame(2, $revision);

        $edits = $this->all("SELECT [field], [row_order] FROM [rfq].[rfq_draft_row_edits] WHERE [draft_id] = '{$id}' ORDER BY [field]");
        self::assertSame([['line', '20'], ['margin_percent', '10']], $edits);
        $reloaded = $store->load($id);
        self::assertNotNull($reloaded);
        self::assertCount(499, $reloaded->rows);
        self::assertSame(PythonJson::encode($saved->rows[0]['line_uid']), PythonJson::encode($reloaded->rows[0]['line_uid']));
        self::assertSame(
            $this->scalar("SELECT [row_data] FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = '{$id}' AND [row_order] = 7"),
            PythonJson::encode(array_diff_key($reloaded->rows[7], ['_draft_row_id' => 1, '_system_snapshot_valid' => 1])),
            'a line read and written back is byte-identical, NaN included',
        );
    }

    public function testRunningSyncAndArchiveRefuseEdits(): void
    {
        $store = $this->store();
        $actor = new DraftActor('a@tricab.com', 'sales', null);
        foreach (['sync_started_at' => RfqDraftNotEditable::SYNC_RUNNING, 'archived_at' => RfqDraftNotEditable::ARCHIVED] as $column => $reason) {
            $id = $store->create($this->draft(1));
            $this->pdo->exec("UPDATE [rfq].[rfq_draft_quotes] SET [{$column}] = N'2026-09-28T00:00:00+00:00' WHERE [id] = '{$id}'");
            try {
                $store->saveContent($id, 1, [], [], 'in_progress', $actor);
                self::fail("{$column} must refuse the save");
            } catch (RfqDraftNotEditable $refused) {
                self::assertSame($reason, $refused->reason);
            }
        }
        self::assertNull($store->saveContent(str_repeat('0', 32), 1, [], [], 'new', $actor));
        self::assertNull($store->load('not-an-id'));
    }

    private function scalar(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @return list<list<?string>> */
    private function all(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            self::assertIsArray($row);
            $rows[] = array_values(array_map(static fn (mixed $v): ?string => is_scalar($v) ? (string) $v : null, $row));
        }

        return $rows;
    }

    private function store(bool $fixedIds = false): AzureSqlRfqDraftStore
    {
        return new AzureSqlRfqDraftStore(
            new PdoSqlConnection($this->pdo, new RequestPerformanceMetrics()),
            $this->clock(),
            $fixedIds ? fn (): string => sprintf('%032x', ++$this->nextId) : null,
        );
    }

    private function connection(): PDO
    {
        return LocalRfqSqlServer::open(
            (string) getenv('RFQ_TEST_SQLSERVER'),
            LocalRfqSqlServer::DATABASE,
            getenv('RFQ_TEST_SQLSERVER_USER') ?: 'sa',
            (string) getenv('RFQ_TEST_SQLSERVER_PASSWORD'),
        );
    }

    private function clock(): Clock
    {
        $frozen = new DateTimeImmutable(DraftStoreGolden::frozenNow());

        return new class ($frozen) implements Clock {
            public function __construct(private readonly DateTimeImmutable $now)
            {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }

    private function draft(int $lines): NewDraft
    {
        $rows = [];
        for ($i = 0; $i < $lines; $i++) {
            $rows[] = [
                'raw_text' => "Ø 16mm² × 3 core — 😀 {$i}",
                'final_product_code' => "c1-test{$i}",
                'length' => ['total_length' => 100 + $i, 'number_of_lengths' => 1],
                'margin_percent' => 30,
                'score' => $i === 7 ? NAN : 0.5,
            ];
        }

        return new NewDraft('web', 'test', ['customer_code' => '1'], $rows, creatorEmail: 'a@tricab.com', branch: 'VIC');
    }

    /**
     * The four tables for one draft, as {@see DraftStoreGolden::tables()} shapes them.
     *
     * @return array{draft: list<array<string, ?string>>, rows: list<array<string, ?string>>, edits: list<array<string, ?string>>, activity: list<array<string, ?string>>}
     */
    private function state(string $id): array
    {
        $q = function (string $sql) use ($id): array {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$id]);

            return array_values(array_map(DraftStoreGolden::textRow(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
        };

        $draft = $q('SELECT * FROM [rfq].[rfq_draft_quotes] WHERE [id] = ?');
        foreach ($draft as $i => $row) {
            // The platform's own column: the sequence fills it, and the source's golden state has none.
            self::assertMatchesRegularExpression('/^[1-9]\d*$/', (string) ($row['rfq_number'] ?? ''), 'a new draft is numbered');
            unset($draft[$i]['rfq_number']);
        }

        return [
            'draft' => $draft,
            'rows' => $q('SELECT [id], [draft_id], [row_order], [row_data], [system_row_data], [system_snapshot_valid], [line_uid] FROM [rfq].[rfq_draft_rows] WHERE [draft_id] = ? ORDER BY [row_order]'),
            'edits' => $q('SELECT * FROM [rfq].[rfq_draft_row_edits] WHERE [draft_id] = ? ORDER BY [id]'),
            'activity' => $q('SELECT * FROM [rfq].[rfq_draft_activity] WHERE [draft_id] = ? ORDER BY [id]'),
        ];
    }

    /**
     * @param array{draft: array<string, ?string>, rows: list<array<string, ?string>>, edits: list<array<string, ?string>>, activity: list<array<string, ?string>>} $tables
     *
     * @return array{draft: list<array<string, ?string>>, rows: list<array<string, ?string>>, edits: list<array<string, ?string>>, activity: list<array<string, ?string>>}
     */
    private static function expected(array $tables): array
    {
        return ['draft' => [$tables['draft']]] + $tables;
    }
}
