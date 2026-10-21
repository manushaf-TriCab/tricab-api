<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use DateTimeImmutable;
use PDOException;
use PHPUnit\Framework\TestCase;
use TriCab\Api\CableFit\Persistence\AzureSqlResearchRunRepository;
use TriCab\Api\Infrastructure\Database\DatabaseUnavailable;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\CableFit\Domain\ResearchAssessment;
use TriCab\Core\CableFit\Domain\ResearchCost;
use TriCab\Core\CableFit\Domain\ResearchDailyLimitReached;
use TriCab\Core\CableFit\Domain\ResearchFinding;
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

    public function test_latest_for_candidates_picks_each_candidates_newest_run_in_one_query(): void
    {
        $connection = new FakeSqlConnection([self::activeRow()]);

        $latest = (new AzureSqlResearchRunRepository($connection))->latestForCandidates([self::CANDIDATE, 'not-a-guid', self::CANDIDATE]);

        self::assertSame([self::CANDIDATE], array_keys($latest));
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $latest[self::CANDIDATE]->publicId);
        self::assertCount(1, $connection->calls);
        $call = $connection->calls[0];
        self::assertStringContainsString('c.[public_id] IN (:c0)', $call['sql']);
        self::assertStringContainsString('ORDER BY l.[requested_at] DESC, l.[run_id] DESC', $call['sql']);
        self::assertSame(['c0' => self::CANDIDATE], $call['params']);
    }

    public function test_latest_for_no_valid_candidates_sends_nothing(): void
    {
        $connection = new FakeSqlConnection();

        self::assertSame([], (new AzureSqlResearchRunRepository($connection))->latestForCandidates(['not-a-guid']));
        self::assertSame([], $connection->calls);
    }

    public function test_an_evaluate_run_is_inserted_with_its_revision_codes_and_rule_check(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([]);                                // findActive
        $connection->queueFetch([['used' => 0, 'oldest' => null]]); // daily count
        $connection->queueFetch([['run_id' => 5]]);                 // insert
        $run = ResearchRun::queued('22222222-2222-4222-8222-222222222222', self::CANDIDATE, 'Drag Chain', ['a' => 1], ['iec.ch'], 7, 'User', new DateTimeImmutable('2026-10-21T10:00:00Z'), 3, 'DRAG_CHAIN', 'AS_NZS_2802', ['overallScore' => 64]);

        (new AzureSqlResearchRunRepository($connection))->queueWithinLimits($run, 10, new DateTimeImmutable('2026-10-21T10:00:00Z'));

        $insert = $connection->calls[2];
        self::assertStringContainsString('[candidate_revision_number], [application_code], [standard_code], [rule_check_json]', $insert['sql']);
        self::assertSame(3, $insert['params']['revision']);
        self::assertSame('DRAG_CHAIN', $insert['params']['applicationCode']);
        self::assertSame('AS_NZS_2802', $insert['params']['standardCode']);
        self::assertSame('{"overallScore":64}', $insert['params']['ruleCheck']);
    }

    public function test_reuse_looks_for_the_newest_succeeded_run_with_a_null_standard_as_is_null(): void
    {
        $connection = new FakeSqlConnection([self::activeRow() + ['status' => 'succeeded', 'candidate_revision_number' => 3, 'application_code' => 'DRAG_CHAIN']]);

        $run = (new AzureSqlResearchRunRepository($connection))->findReusable(self::CANDIDATE, 3, 'DRAG_CHAIN', null, 'Drag Chain');

        self::assertNotNull($run);
        self::assertSame(3, $run->candidateRevision);
        $call = $connection->calls[0];
        self::assertStringContainsString("r.[status] = N'succeeded'", $call['sql']);
        self::assertStringContainsString('r.[candidate_revision_number] = :revision AND r.[application_code] = :applicationCode', $call['sql']);
        self::assertStringContainsString('r.[standard_code] IS NULL', $call['sql']);
        self::assertStringNotContainsString(':standardCode', $call['sql']);
        self::assertStringNotContainsString(':application ', $call['sql'] . ' ');
        self::assertStringContainsString('ORDER BY r.[finished_at] DESC', $call['sql']);
        self::assertSame(['candidate' => self::CANDIDATE, 'revision' => 3, 'applicationCode' => 'DRAG_CHAIN'], $call['params']);
    }

    public function test_reuse_for_other_also_matches_the_description_and_a_standard_by_value(): void
    {
        $connection = new FakeSqlConnection();

        self::assertNull((new AzureSqlResearchRunRepository($connection))->findReusable(self::CANDIDATE, 2, 'OTHER', 'AS_NZS_2802', 'Festoon'));

        $call = $connection->calls[0];
        self::assertStringContainsString('r.[standard_code] = :standardCode', $call['sql']);
        self::assertStringContainsString('r.[application] = :application', $call['sql']);
        self::assertSame('AS_NZS_2802', $call['params']['standardCode']);
        self::assertSame('Festoon', $call['params']['application']);
    }

    public function test_success_saves_each_stance_and_the_verdict_and_assessment_and_reads_them_back(): void
    {
        $connection = new FakeSqlConnection();
        $finding = new ResearchFinding('f-1', 'p', 'c', 'r', 'https://iec.ch/a', 't', null, null, 'undated', 'high', 0, 'concern');
        $assessment = ResearchAssessment::fromModel(['verdict' => 'likely_unsuitable', 'cons' => [['point' => 'x', 'findingRefs' => [0]]]], [0 => 'f-1']);

        (new AzureSqlResearchRunRepository($connection))->markSucceeded('22222222-2222-4222-8222-222222222222', [$finding], ['iec.ch'], new ResearchCost('m', 1, 1, 1), 0, new DateTimeImmutable('2026-10-21T10:00:00Z'), $assessment);

        self::assertStringContainsString('[sort_order], [stance])', $connection->calls[1]['sql']);
        self::assertSame('concern', $connection->calls[1]['params']['stance']);
        $update = $connection->calls[2];
        self::assertStringContainsString('[verdict] = :verdict, [assessment_json] = :assessment', $update['sql']);
        self::assertSame('likely_unsuitable', $update['params']['verdict']);
        self::assertIsString($update['params']['assessment']);
        $stored = json_decode($update['params']['assessment'], true);
        self::assertIsArray($stored);
        /** @var array{cons: list<array{findingIds: list<string>}>} $stored */
        self::assertSame(['f-1'], $stored['cons'][0]['findingIds']);

        $read = new FakeSqlConnection([self::activeRow() + ['status' => 'succeeded', 'verdict' => 'likely_unsuitable', 'assessment_json' => $update['params']['assessment'], 'rule_check_json' => '{"overallScore":64}']]);
        $run = (new AzureSqlResearchRunRepository($read))->find('22222222-2222-4222-8222-222222222222');
        self::assertNotNull($run);
        self::assertSame('likely_unsuitable', $run->verdict);
        self::assertSame($assessment->toArray(), $run->assessment?->toArray());
        self::assertSame(['overallScore' => 64], $run->ruleCheck);
    }

    public function test_a_run_from_before_c4_reads_back_with_nulls(): void
    {
        $run = (new AzureSqlResearchRunRepository(new FakeSqlConnection([self::activeRow()])))->find('22222222-2222-4222-8222-222222222222');

        self::assertNotNull($run);
        self::assertNull($run->candidateRevision);
        self::assertNull($run->applicationCode);
        self::assertNull($run->verdict);
        self::assertNull($run->assessment);
        self::assertNull($run->ruleCheck);
    }
}
