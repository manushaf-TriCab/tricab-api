<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use DateTimeImmutable;
use PDOException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\CableFit\Persistence\AzureSqlResearchRunRepository;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\CableFit\Domain\ResearchDailyLimitReached;
use TriCab\Core\CableFit\Domain\ResearchRun;
use TriCab\Core\CableFit\Domain\ResearchRunActive;

/** Statement shape, locking and the duplicate-key mapping of queueWithinLimits, against the recording connection double. */
final class AzureSqlResearchRunRepositoryTest extends TestCase
{
    private const CANDIDATE = '11111111-1111-4111-8111-111111111111';

    private static function newRun(): ResearchRun
    {
        return ResearchRun::queued('22222222-2222-4222-8222-222222222222', self::CANDIDATE, 'app', ['a' => 1], ['iec.ch'], 7, 'User', new DateTimeImmutable('2026-10-19T10:00:00Z'));
    }

    /** @return array<string, mixed> */
    private static function activeRow(): array
    {
        return [
            'public_id' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA', 'candidate_public_id' => self::CANDIDATE, 'status' => 'running',
            'application' => 'x', 'parameters_json' => '{}', 'sources_used_json' => '[]', 'model' => null,
            'input_tokens' => null, 'output_tokens' => null, 'search_requests' => null, 'dropped_findings' => 0, 'error' => null,
            'requested_by_user_id' => 7, 'requested_by_name' => 'User', 'requested_at' => '2026-10-19 09:00:00.0000000',
            'started_at' => null, 'finished_at' => null, 'finding_count' => 0,
        ];
    }

    private static function duplicateKey(int $code): DatabaseUnavailable
    {
        $pdo = new PDOException("Cannot insert duplicate key row in object 'cablefit.research_runs' with unique index 'UX_cablefit_research_runs_one_active'.");
        $pdo->errorInfo = ['23000', $code, 'duplicate key'];

        return DatabaseUnavailable::fromThrowable($pdo);
    }

    public function test_it_checks_the_active_run_then_counts_with_locks_then_inserts_in_one_transaction(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([]);                       // no active run
        $connection->queueFetch([['used' => 2, 'oldest' => '2026-10-19 08:00:00.0000000']]); // window
        $connection->queueFetch([['run_id' => 1]]);        // insert OUTPUT

        (new AzureSqlResearchRunRepository($connection))->queueWithinLimits(self::newRun(), 3, new DateTimeImmutable('2026-10-19T10:00:00Z'));

        self::assertTrue($connection->committed);
        self::assertFalse($connection->rolledBack);
        self::assertCount(3, $connection->calls);
        self::assertStringContainsString("IN (N'queued', N'running')", $connection->calls[0]['sql']);
        self::assertStringContainsString('WITH (UPDLOCK, HOLDLOCK)', $connection->calls[1]['sql']);
        self::assertSame(7, $connection->calls[1]['params']['userId']);
        self::assertSame('2026-10-18 10:00:00.000000', $connection->calls[1]['params']['since']);
        self::assertStringStartsWith('INSERT INTO [cablefit].[research_runs]', $connection->calls[2]['sql']);
    }

    public function test_an_active_run_is_refused_before_anything_is_counted_or_written(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([self::activeRow()]);

        try {
            (new AzureSqlResearchRunRepository($connection))->queueWithinLimits(self::newRun(), 3, new DateTimeImmutable('2026-10-19T10:00:00Z'));
            self::fail('Expected ResearchRunActive');
        } catch (ResearchRunActive $active) {
            self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $active->activeRunPublicId);
        }
        self::assertTrue($connection->rolledBack);
        self::assertCount(1, $connection->calls);
    }

    public function test_the_cap_throws_with_retry_at_24_hours_after_the_oldest_run_and_inserts_nothing(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([]);
        $connection->queueFetch([['used' => 3, 'oldest' => '2026-10-19 08:30:00.0000000']]);

        try {
            (new AzureSqlResearchRunRepository($connection))->queueWithinLimits(self::newRun(), 3, new DateTimeImmutable('2026-10-19T10:00:00Z'));
            self::fail('Expected ResearchDailyLimitReached');
        } catch (ResearchDailyLimitReached $limit) {
            self::assertSame(3, $limit->limit);
            self::assertSame('2026-10-20T08:30:00+00:00', $limit->retryAt->format(DATE_ATOM));
        }
        self::assertTrue($connection->rolledBack);
        self::assertCount(2, $connection->calls);
    }

    public function test_a_duplicate_key_on_the_one_active_index_becomes_run_active(): void
    {
        foreach ([2601, 2627] as $code) {
            $connection = new FakeSqlConnection();
            $connection->queueFetch([]);                     // no active run seen first
            $connection->queueFetch([['used' => 0, 'oldest' => null]]);
            $connection->failNextWrite = self::duplicateKey($code);
            $connection->queueFetch([self::activeRow()]);    // the winner, re-read

            try {
                (new AzureSqlResearchRunRepository($connection))->queueWithinLimits(self::newRun(), 3, new DateTimeImmutable('2026-10-19T10:00:00Z'));
                self::fail("Expected ResearchRunActive for $code");
            } catch (ResearchRunActive $active) {
                self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $active->activeRunPublicId, (string) $code);
            }
            self::assertTrue($connection->rolledBack, (string) $code);
        }
    }

    public function test_other_database_failures_are_not_mapped(): void
    {
        $pdo = new PDOException('Cannot insert duplicate key row in object with unique index UQ_cablefit_research_runs_public_id.');
        $pdo->errorInfo = ['23000', 2627, 'dup'];
        $connection = new FakeSqlConnection();
        $connection->queueFetch([]);
        $connection->queueFetch([['used' => 0, 'oldest' => null]]);
        $connection->failNextWrite = DatabaseUnavailable::fromThrowable($pdo);

        $this->expectException(DatabaseUnavailable::class);

        (new AzureSqlResearchRunRepository($connection))->queueWithinLimits(self::newRun(), 3, new DateTimeImmutable('2026-10-19T10:00:00Z'));
    }
}
