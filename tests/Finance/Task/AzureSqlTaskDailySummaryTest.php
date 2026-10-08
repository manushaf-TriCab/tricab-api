<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Audit\AzureSqlFinanceAuditLog;
use TriCab\Api\Finance\Jobs\AzureSqlFinanceJobClaims;
use TriCab\Api\Finance\Mail\AzureSqlFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\AzureSqlFinanceMailLedger;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Api\Finance\Task\AzureSqlTaskMailLinks;
use TriCab\Api\Finance\Task\AzureSqlTaskReportRecipientStore;
use TriCab\Api\Finance\Task\AzureSqlTaskSetupStore;
use TriCab\Api\Finance\Task\AzureSqlTaskStore;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobOutcome;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRunner;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskReportRecipient;
use TriCab\Core\Finance\Task\Service\TaskDailySummaryJob;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * The daily summary over the real tables on a local SQL Server ({@see LocalFinanceSqlServer}); skipped without one,
 * with the real runner, claims and audit table and mail switched off, so a send is a `suppressed` ledger row and can
 * go nowhere: one pass leaves one suppressed ledger row, one link, one audit row, and a second pass the same minute
 * sends and records nothing more.
 */
#[Group('local-sql-server')]
final class AzureSqlTaskDailySummaryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    public function test_the_due_tasks_come_back_ordered_and_a_pass_leaves_one_suppressed_row_one_link_one_audit_row_and_a_second_pass_nothing(): void
    {
        $this->pdo->exec("INSERT INTO [finance].[mailboxes] ([id], [email], [display_name], [purpose], [is_active]) VALUES ('11111111111111111111111111111111', N'finance@example.test', N'Équipe finance', N'', 1)");
        $this->pdo->exec("INSERT INTO [finance].[mailbox_grants] ([id], [application_key], [mailbox_id], [can_read], [can_send]) VALUES ('a1111111111111111111111111111111', N'TASK', '11111111111111111111111111111111', 0, 1)");
        $connection = new PdoSqlConnection($this->pdo, new RequestPerformanceMetrics());
        $clock = FixedClock::utc('2026-10-05 21:00:00');   // 08:00 on 6 Oct in Melbourne
        $time = new TaskTime($clock, 'Australia/Melbourne');
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(11, 'Una Üser', 'una@example.test');
        $store = new AzureSqlTaskStore($connection);
        $tasks = new TaskService($store, new AzureSqlTaskSetupStore($connection), $directory, $time);
        $admin = new TaskActor(12, 'Mo', 'mo@example.test', [Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_READ_ALL]);
        $tasks->create($admin, ['name' => 'Zeta late', 'due_date' => '2026-10-01', 'preparer_id' => 11]);
        $tasks->create($admin, ['name' => 'Alpha late', 'due_date' => '2026-10-01']);
        $tasks->create($admin, ['name' => 'Today', 'due_date' => '2026-10-06']);
        $tasks->create($admin, ['name' => 'Tomorrow', 'due_date' => '2026-10-07']);
        $tasks->create($admin, ['name' => 'Done', 'due_date' => '2026-10-01', 'status' => 'completed']);
        $tasks->create($admin, ['name' => 'No date']);

        self::assertSame(['Alpha late', 'Zeta late', 'Today'], array_map(static fn ($t): string => $t->name, $store->dueOnOrBefore('2026-10-06')));

        $recipients = new AzureSqlTaskReportRecipientStore($connection);
        $at = new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('UTC'));
        $recipients->insert(new TaskReportRecipient('r0000000000000000000000000000001', 'boss@example.test', 'Boss', null, $at, $at));
        $ledger = new AzureSqlFinanceMailLedger($connection);
        $job = new TaskDailySummaryJob(
            $store, $directory, $recipients,
            new FinanceMailService(new AzureSqlFinanceMailboxRegistry($connection), $ledger, new DisabledFinanceMailTransport()),
            $ledger, new AzureSqlTaskMailLinks($connection), $time, 'finance@example.test',
        );
        $runner = new FinanceJobRunner(
            new FinanceJobRegistry([$job]),
            new AzureSqlFinanceJobClaims($connection),
            new AzureSqlFinanceAuditLog($connection),
            static fn (): DateTimeImmutable => $clock->now(),
        );

        $first = $runner->runDue();

        self::assertSame([FinanceJobOutcome::RAN], array_map(static fn (FinanceJobOutcome $o): string => $o->status, $first));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[mail_deliveries] WHERE [status] = N'suppressed' AND [application_key] = N'TASK' AND [idempotency_key] = N'daily-summary:2026-10-06:boss@example.test'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[task_mail_links] WHERE [kind] = N'daily_summary' AND [task_id] IS NULL"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[audit_events] WHERE [action] = N'finance.job.succeeded'"));
        $details = $this->pdo->query("SELECT [details_json] FROM [finance].[audit_events] WHERE [action] = N'finance.job.succeeded'");
        self::assertInstanceOf(PDOStatement::class, $details);
        $json = Typed::string($details->fetchColumn());
        self::assertStringContainsString('"overdue":2', $json);
        self::assertStringNotContainsString('Zeta late', $json);
        self::assertStringNotContainsString('boss@example.test', $json);

        $second = $runner->runDue();

        self::assertSame([FinanceJobOutcome::ALREADY_CLAIMED], array_map(static fn (FinanceJobOutcome $o): string => $o->status, $second));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[mail_deliveries]'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[audit_events] WHERE [action] LIKE N\'finance.job.%\''));
    }
}
