<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Audit\AzureSqlFinanceAuditLog;
use TriCab\Api\Finance\Jobs\AzureSqlFinanceJobClaims;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Shared\Audit\FinanceAuditEvent;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobOutcome;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRunner;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;

/**
 * The claim table and the audit log over `create-finance-jobs-schema.sql`, against a real local
 * SQL Server ({@see LocalFinanceSqlServer}); skipped without one. This is where the "a due slot
 * runs once" guarantee lives: the primary key, not PHP.
 */
#[Group('local-sql-server')]
final class AzureSqlFinanceJobsTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlFinanceJobClaims $claims;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->claims = new AzureSqlFinanceJobClaims(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()));
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, string|null>
     */
    private static function strings(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : null;
        }

        return $out;
    }

    /**
     * @return array<string, string|null>
     */
    private function row(string $job, string $slot): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM [finance].[job_runs] WHERE [job_name] = ? AND [slot] = ?');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute([$job, $slot]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return self::strings($row);
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function auditRows(): array
    {
        $statement = $this->pdo->query('SELECT * FROM [finance].[audit_events] ORDER BY [created_at]');
        self::assertInstanceOf(PDOStatement::class, $statement);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            self::assertIsArray($row);
            $rows[] = self::strings($row);
        }

        return $rows;
    }

    public function test_a_slot_is_claimed_once_and_a_different_slot_or_job_is_a_separate_claim(): void
    {
        $now = self::at('2026-10-07 01:00:00');

        $first = $this->claims->claim('daily-summary', 'D2026-10-07@06:00@Australia/Melbourne', $now, 1800);
        self::assertNotNull($first);
        self::assertSame(32, strlen($first));
        self::assertNull($this->claims->claim('daily-summary', 'D2026-10-07@06:00@Australia/Melbourne', $now, 1800));
        self::assertNotNull($this->claims->claim('daily-summary', 'D2026-10-08@06:00@Australia/Melbourne', $now, 1800));
        self::assertNotNull($this->claims->claim('other-job', 'D2026-10-07@06:00@Australia/Melbourne', $now, 1800));
    }

    public function test_a_second_connection_cannot_take_a_claim_the_first_holds(): void
    {
        $other = new AzureSqlFinanceJobClaims(new PdoSqlConnection(
            LocalFinanceSqlServer::open(
                (string) getenv('FINANCE_TEST_SQLSERVER'),
                LocalFinanceSqlServer::database(),
                getenv('FINANCE_TEST_SQLSERVER_USER') ?: 'sa',
                (string) getenv('FINANCE_TEST_SQLSERVER_PASSWORD'),
            ),
            new RequestPerformanceMetrics(),
        ));
        $now = self::at('2026-10-07 01:00:00');

        $mine = $this->claims->claim('j', 's', $now, 1800);
        $theirs = $other->claim('j', 's', $now, 1800);

        self::assertNotNull($mine);
        self::assertNull($theirs);
        self::assertSame(1, (int) $this->rowCount('job_runs'));
    }

    public function test_the_slot_has_one_row_however_many_claim_it(): void
    {
        $now = self::at('2026-10-07 01:00:00');
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $tokens[] = $this->claims->claim('j', 's', $now, 1800);
        }

        self::assertCount(1, array_filter($tokens, static fn (?string $t): bool => $t !== null));
        self::assertSame('1', $this->rowCount('job_runs'));
    }

    public function test_finishing_records_the_outcome_with_the_summary_and_a_six_digit_time(): void
    {
        $token = $this->claims->claim('j', 's', self::at('2026-10-07 01:00:00'), 1800);
        self::assertNotNull($token);

        $this->claims->finish('j', 's', $token, true, null, ['sent' => 3, 'note' => 'café ✓'], self::at('2026-10-07 01:00:02.123456'));

        $row = $this->row('j', 's');
        self::assertSame('succeeded', $row['status']);
        self::assertSame('2026-10-07 01:00:02.123456', $row['finished_at']);
        self::assertNull($row['error_message']);
        self::assertSame(['sent' => 3, 'note' => 'café ✓'], json_decode((string) $row['summary_json'], true));
    }

    public function test_a_failure_keeps_its_error_cut_to_five_hundred_characters(): void
    {
        $token = $this->claims->claim('j', 's', self::at('2026-10-07 01:00:00'), 1800);
        self::assertNotNull($token);

        $this->claims->finish('j', 's', $token, false, str_repeat('é', 700), [], self::at('2026-10-07 01:00:01'));

        $row = $this->row('j', 's');
        self::assertSame('failed', $row['status']);
        self::assertSame(500, mb_strlen((string) $row['error_message']));
    }

    public function test_a_finished_slot_is_never_taken_over_however_old_it_is(): void
    {
        $token = $this->claims->claim('j', 's', self::at('2026-10-07 01:00:00'), 1800);
        self::assertNotNull($token);
        $this->claims->finish('j', 's', $token, true, null, [], self::at('2026-10-07 01:00:01'));

        self::assertNull($this->claims->claim('j', 's', self::at('2026-10-09 01:00:00'), 1800));
    }

    public function test_a_claim_still_running_after_the_stale_period_is_taken_over_and_the_dead_owner_cannot_finish_it(): void
    {
        $dead = $this->claims->claim('j', 's', self::at('2026-10-07 01:00:00'), 1800);
        self::assertNotNull($dead);

        self::assertNull($this->claims->claim('j', 's', self::at('2026-10-07 01:29:59'), 1800), 'still within the period');
        $new = $this->claims->claim('j', 's', self::at('2026-10-07 01:30:00'), 1800);
        self::assertNotNull($new);
        self::assertNotSame($dead, $new);

        $this->claims->finish('j', 's', $dead, false, 'late and wrong', [], self::at('2026-10-07 01:31:00'));
        self::assertSame('running', $this->row('j', 's')['status'], 'the dead owner changed nothing');

        $this->claims->finish('j', 's', $new, true, null, ['ok' => 1], self::at('2026-10-07 01:32:00'));
        self::assertSame('succeeded', $this->row('j', 's')['status']);
    }

    public function test_the_audit_log_appends_a_row_with_json_details_and_a_null_actor(): void
    {
        $log = new AzureSqlFinanceAuditLog(new PdoSqlConnection($this->pdo, new RequestPerformanceMetrics()));

        $log->record(new FinanceAuditEvent('finance.job.succeeded', 'job', 'daily-summary', ['slot' => 'D1', 'summary' => ['sent' => 2]], self::at('2026-10-07 01:00:02.500000')));
        $log->record(new FinanceAuditEvent('finance.mail.sent', 'mail_delivery', null, [], self::at('2026-10-07 01:00:03'), 42, 'AP'));

        $all = $this->auditRows();
        self::assertCount(2, $all);
        self::assertSame(['FINANCE', null, 'finance.job.succeeded', 'job', 'daily-summary'], [$all[0]['application_key'], $all[0]['actor_user_id'], $all[0]['action'], $all[0]['entity_type'], $all[0]['entity_id']]);
        self::assertSame(['slot' => 'D1', 'summary' => ['sent' => 2]], json_decode((string) $all[0]['details_json'], true));
        self::assertSame('2026-10-07 01:00:02.500000', $all[0]['created_at']);
        self::assertSame(['AP', '42', null], [$all[1]['application_key'], $all[1]['actor_user_id'], $all[1]['entity_id']]);
        self::assertSame('{}', $all[1]['details_json']);
    }

    public function test_the_runner_over_the_real_tables_runs_a_due_job_once_and_audits_it(): void
    {
        $connection = new PdoSqlConnection($this->pdo, new RequestPerformanceMetrics());
        $job = new ScriptedFinanceJob('daily-summary', FinanceJobSchedule::dailyAt('06:00'), static fn (): array => ['sent' => 4]);
        $now = self::at('2026-10-06 19:30:00'); // 06:30 in Melbourne
        $runner = new FinanceJobRunner(
            new FinanceJobRegistry([$job]),
            $this->claims,
            new AzureSqlFinanceAuditLog($connection),
            static fn (): DateTimeImmutable => $now,
        );

        $first = $runner->runDue();
        $second = $runner->runDue();

        self::assertSame(FinanceJobOutcome::RAN, $first[0]->status);
        self::assertSame(FinanceJobOutcome::ALREADY_CLAIMED, $second[0]->status);
        self::assertSame(1, $job->runs);
        self::assertSame('1', $this->rowCount('job_runs'));
        self::assertSame('1', $this->rowCount('audit_events'));
    }

    private function rowCount(string $table): string
    {
        $statement = $this->pdo->query("SELECT COUNT(*) FROM [finance].[{$table}]");
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (string) $statement->fetchColumn();
    }
}
