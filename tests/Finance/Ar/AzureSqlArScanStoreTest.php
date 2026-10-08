<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ar\AzureSqlArScanStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Ar\Domain\ArConflict;
use TriCab\Core\Finance\Ar\Domain\ArScanRun;

/**
 * The scan runs over `create-finance-ar-schema.sql`, against a real local SQL Server ({@see LocalFinanceSqlServer});
 * skipped without one. What the twin cannot prove: the filtered unique index that is the claim (one `running` scan per
 * mailbox), the expired claim failed first in the same transaction, six-digit times, the watermark query and the
 * overlap filter.
 */
#[Group('local-sql-server')]
final class AzureSqlArScanStoreTest extends TestCase
{
    private const MAILBOX = 'ar@example.test';

    private PDO $pdo;

    private AzureSqlArScanStore $store;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->store = new AzureSqlArScanStore(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()));
    }

    private function newRun(string $id, string $startedAt = '2026-10-07 01:00:00.123456', string $mailbox = self::MAILBOX, ?string $from = null, ?string $to = null, int $claimMinutes = 30, ?int $userId = null): ArScanRun
    {
        $at = ArFixtures::utc($startedAt);

        return new ArScanRun($id, $at, null, $mailbox, ArScanRun::RUNNING, null, 0, 0, 0, 0, '', $from, $to, $userId, 'Olive Operator', 0, 0, $at->modify('+' . $claimMinutes . ' minutes'));
    }

    private function begin(ArScanRun $run): void
    {
        $this->store->begin($run, $run->startedAt);
    }

    private function runOf(string $id): ArScanRun
    {
        return $this->store->find($id) ?? self::fail('No run ' . $id);
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    public function test_a_run_round_trips_with_six_digit_time_and_a_person_id_past_two_to_the_53(): void
    {
        $this->begin($this->newRun('r1', from: '2026-10-05', to: '2026-10-06', userId: 9007199254740993));

        $found = $this->runOf('r1');

        self::assertSame('2026-10-07 01:00:00.123456', $found->startedAt->format('Y-m-d H:i:s.u'));
        self::assertSame([ArScanRun::RUNNING, '2026-10-05', '2026-10-06', 9007199254740993, 'Olive Operator', self::MAILBOX], [$found->status, $found->fromDate, $found->toDate, $found->requestedByUserId, $found->requestedBy, $found->mailbox]);
        self::assertSame('2026-10-07 01:30:00.123456', $found->claimExpiresAt?->format('Y-m-d H:i:s.u'));
        self::assertNull($found->finishedAt);
        self::assertNull($this->store->find('missing'));
    }

    public function test_a_second_running_scan_of_one_mailbox_is_refused_but_another_mailbox_is_free(): void
    {
        $this->begin($this->newRun('r1'));

        try {
            $this->store->begin($this->newRun('r2', '2026-10-07 01:10:00'), ArFixtures::utc('2026-10-07 01:10:00'));
            self::fail('Expected the claim to refuse a second scan.');
        } catch (ArConflict $busy) {
            self::assertSame('A scan of this mailbox is already running', $busy->getMessage());
        }
        $this->begin($this->newRun('r3', mailbox: 'other@example.test'));

        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM [finance].[ar_scan_runs] WHERE [status] = N'running'"));
        self::assertNull($this->store->find('r2'));
    }

    public function test_a_run_whose_claim_expired_is_failed_first_and_the_new_one_starts(): void
    {
        $this->begin($this->newRun('old'));

        $this->store->begin($this->newRun('new', '2026-10-07 01:31:00'), ArFixtures::utc('2026-10-07 01:31:00'));

        $old = $this->runOf('old');
        self::assertSame(ArScanRun::FAILED, $old->status);
        self::assertSame('The scan stopped without finishing', $old->errors);
        self::assertNull($old->claimExpiresAt);
        self::assertSame(ArScanRun::RUNNING, $this->runOf('new')->status);
    }

    public function test_bumping_adds_to_the_counters_and_moves_the_claim_only_while_running(): void
    {
        $this->begin($this->newRun('r1'));

        $this->store->bump('r1', ['total_messages' => 5], ArFixtures::utc('2026-10-07 02:00:00'));
        $this->store->bump('r1', ['processed_messages' => 1, 'total_fetched' => 1, 'new_records' => 1, 'failed_enrichments' => 1], ArFixtures::utc('2026-10-07 02:10:00'));
        $this->store->bump('r1', ['processed_messages' => 1, 'total_fetched' => 1, 'skipped_records' => 1], ArFixtures::utc('2026-10-07 02:20:00'));

        $run = $this->runOf('r1');
        self::assertSame([5, 2, 2, 1, 1, 1], [$run->totalMessages, $run->processedMessages, $run->totalFetched, $run->newRecords, $run->skippedRecords, $run->failedEnrichments]);
        self::assertSame('2026-10-07 02:20:00', $run->claimExpiresAt?->format('Y-m-d H:i:s'));

        $this->store->finish('r1', ArScanRun::COMPLETED, ArFixtures::utc('2026-10-07 03:00:00'), null, $run);
        $this->store->bump('r1', ['processed_messages' => 1], ArFixtures::utc('2026-10-07 09:00:00'));
        self::assertNull($this->runOf('r1')->claimExpiresAt, 'a finished run does not take the claim back');
    }

    public function test_finishing_releases_the_claim_stores_the_watermark_and_frees_the_mailbox(): void
    {
        $this->begin($this->newRun('r1'));
        $final = $this->newRun('r1')->with(totalFetched: 3, newRecords: 2, skippedRecords: 1, failedEnrichments: 1, errors: "Jev error: x\nGemini error: y");

        $this->store->finish('r1', ArScanRun::COMPLETED, ArFixtures::utc('2026-10-07 01:05:00'), ArFixtures::utc('2026-10-06 22:15:30.654321'), $final);

        $run = $this->runOf('r1');
        self::assertSame(ArScanRun::COMPLETED, $run->status);
        self::assertSame('2026-10-06 22:15:30.654321', $run->watermark?->format('Y-m-d H:i:s.u'));
        self::assertSame([3, 2, 1, 1], [$run->totalFetched, $run->newRecords, $run->skippedRecords, $run->failedEnrichments]);
        self::assertSame("Jev error: x\nGemini error: y", $run->errors);
        self::assertNull($run->claimExpiresAt);
        $this->begin($this->newRun('r2', '2026-10-07 02:00:00'));
        self::assertSame(ArScanRun::RUNNING, $this->runOf('r2')->status);
    }

    public function test_the_latest_watermark_is_the_highest_of_the_completed_runs_of_that_mailbox(): void
    {
        self::assertNull($this->store->latestWatermark(self::MAILBOX));
        foreach ([['a', '2026-10-01 00:00:00', 'completed', '2026-10-01 00:00:00'], ['b', '2026-10-03 00:00:00', 'completed', '2026-10-03 00:00:00'], ['c', '2026-10-02 00:00:00', 'completed', '2026-10-02 00:00:00'], ['d', '2026-10-05 00:00:00', 'failed', null], ['e', '2026-10-06 00:00:00', 'stopped', null]] as [$id, $started, $status, $mark]) {
            $run = $this->newRun($id, $started);
            $this->begin($run);
            $this->store->finish($id, $status, ArFixtures::utc($started), $mark === null ? null : ArFixtures::utc($mark), $run);
        }
        $other = $this->newRun('z', '2026-10-09 00:00:00', 'other@example.test');
        $this->begin($other);
        $this->store->finish('z', 'completed', ArFixtures::utc('2026-10-09 00:00:00'), ArFixtures::utc('2026-10-09 00:00:00'), $other);

        self::assertSame('2026-10-03 00:00:00', $this->store->latestWatermark(self::MAILBOX)?->format('Y-m-d H:i:s'));
    }

    public function test_overlapping_finds_completed_and_running_period_scans_only_newest_first(): void
    {
        foreach ([
            ['p1', '2026-10-01 00:00:00', '2026-10-01', '2026-10-03', 'completed'],
            ['p2', '2026-10-02 00:00:00', '2026-10-04', '2026-10-06', 'completed'],
            ['p3', '2026-10-03 00:00:00', '2026-10-04', '2026-10-06', 'failed'],
            ['p4', '2026-10-04 00:00:00', '2026-10-20', '2026-10-21', 'completed'],
            ['w1', '2026-10-05 00:00:00', null, null, 'completed'],
        ] as [$id, $started, $from, $to, $status]) {
            $run = $this->newRun($id, $started, from: $from, to: $to);
            $this->begin($run);
            $this->store->finish($id, $status, ArFixtures::utc($started), null, $run);
        }

        $found = $this->store->overlapping('2026-10-03', '2026-10-05');

        self::assertSame(['p2', 'p1'], array_map(static fn (ArScanRun $r): string => $r->id, $found));
        self::assertCount(1, $this->store->overlapping('2026-10-03', '2026-10-05', 1));
        self::assertSame([], $this->store->overlapping('2026-11-01', '2026-11-02'));
    }

    public function test_recent_is_newest_first_and_limited(): void
    {
        foreach (['a' => '2026-10-01 00:00:00', 'b' => '2026-10-03 00:00:00', 'c' => '2026-10-02 00:00:00'] as $id => $started) {
            $run = $this->newRun($id, $started);
            $this->begin($run);
            $this->store->finish($id, ArScanRun::COMPLETED, ArFixtures::utc($started), null, $run);
        }

        self::assertSame(['b', 'c'], array_map(static fn (ArScanRun $r): string => $r->id, $this->store->recent(2)));
        self::assertCount(3, $this->store->recent(100));
    }
}
