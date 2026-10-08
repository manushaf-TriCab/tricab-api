<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
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
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Domain\TaskReportRecipient;
use TriCab\Core\Finance\Task\Service\TaskMailService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * The mail link table, the recipients table and the ledger lookup over a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one. With the mail-off transport a whole send
 * runs end to end over the real tables: no network call is possible.
 */
#[Group('local-sql-server')]
final class AzureSqlTaskMailTest extends TestCase
{
    private PDO $pdo;

    private PdoSqlConnection $connection;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function test_links_are_listed_newest_first_for_their_task_only(): void
    {
        $links = new AzureSqlTaskMailLinks($this->connection);
        $links->insert(new TaskMailLink('a0000000000000000000000000000001', 'task_email', 'd0000000000000000000000000000001', 'e0000000000000000000000000000001', self::at('2026-10-07 01:00:00.000001')));
        $links->insert(new TaskMailLink('a0000000000000000000000000000002', 'reminder', 'd0000000000000000000000000000001', 'e0000000000000000000000000000002', self::at('2026-10-07 01:00:00.000002')));
        $links->insert(new TaskMailLink('a0000000000000000000000000000003', 'task_email', 'd0000000000000000000000000000002', 'e0000000000000000000000000000003', self::at('2026-10-07 02:00:00')));

        $found = $links->forTask('d0000000000000000000000000000001');

        self::assertSame(['a0000000000000000000000000000002', 'a0000000000000000000000000000001'], array_map(static fn (TaskMailLink $l): string => $l->id, $found));
        self::assertSame('2026-10-07 01:00:00.000002', $found[0]->createdAt->format('Y-m-d H:i:s.u'));
        self::assertSame([], $links->forTask('ffffffffffffffffffffffffffffffff'));
    }

    public function test_a_link_survives_the_permanent_delete_of_its_task_and_is_refused_a_bad_kind(): void
    {
        $links = new AzureSqlTaskMailLinks($this->connection);
        $links->insert(new TaskMailLink('a0000000000000000000000000000001', 'task_email', 'd0000000000000000000000000000009', 'e0000000000000000000000000000001', self::at('2026-10-07 01:00:00')));

        self::assertCount(1, $links->forTask('d0000000000000000000000000000009'), 'no foreign key to a task that no longer exists');
        $this->expectException(\Throwable::class);
        $links->insert(new TaskMailLink('a0000000000000000000000000000002', 'bogus', null, 'e0000000000000000000000000000002', self::at('2026-10-07 01:00:00')));
    }

    public function test_the_recipients_are_unique_by_email_case_insensitively_and_sort_by_email(): void
    {
        $store = new AzureSqlTaskReportRecipientStore($this->connection);
        $now = self::at('2026-10-07 01:02:03.456789');
        $store->insert(new TaskReportRecipient('r0000000000000000000000000000002', 'zed@example.test', 'Zed', 12, $now, $now));
        $store->insert(new TaskReportRecipient('r0000000000000000000000000000001', 'amy@example.test', 'Amélie', null, $now, $now));

        try {
            $store->insert(new TaskReportRecipient('r0000000000000000000000000000003', 'AMY@Example.test', 'Dup', null, $now, $now));
            self::fail('Expected the index to refuse.');
        } catch (TaskConflict $e) {
            self::assertSame('That address is already a recipient', $e->getMessage());
        }

        self::assertSame(['amy@example.test', 'zed@example.test'], array_map(static fn (TaskReportRecipient $r): string => $r->email, $store->all()));
        self::assertSame('Amélie', $store->findByEmail('AMY@example.test')?->name);
        self::assertSame(12, $store->find('r0000000000000000000000000000002')?->userId);
        $store->delete('r0000000000000000000000000000002');
        self::assertNull($store->find('r0000000000000000000000000000002'));
    }

    public function test_the_ledger_finds_a_delivery_by_id(): void
    {
        $ledger = new AzureSqlFinanceMailLedger($this->connection);

        self::assertNull($ledger->findById('ffffffffffffffffffffffffffffffff'));
    }

    public function test_a_whole_send_with_mail_off_leaves_one_suppressed_ledger_row_and_one_link_over_the_real_tables(): void
    {
        $this->pdo->exec("INSERT INTO [finance].[mailboxes] ([id], [email], [display_name], [purpose], [is_active]) VALUES ('11111111111111111111111111111111', N'finance@example.test', N'Équipe finance', N'', 1)");
        $this->pdo->exec("INSERT INTO [finance].[mailbox_grants] ([id], [application_key], [mailbox_id], [can_read], [can_send]) VALUES ('a1111111111111111111111111111111', N'TASK', '11111111111111111111111111111111', 0, 1)");
        $registry = new AzureSqlFinanceMailboxRegistry($this->connection);
        $ledger = new AzureSqlFinanceMailLedger($this->connection);
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(11, 'Una User', 'una@example.test');
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:00:00.123456'), 'Australia/Melbourne');
        $tasks = new TaskService(new AzureSqlTaskStore($this->connection), new AzureSqlTaskSetupStore($this->connection), $directory, $time);
        $mail = new TaskMailService(
            $tasks, $directory, new AzureSqlTaskReportRecipientStore($this->connection),
            new FinanceMailService($registry, $ledger, new DisabledFinanceMailTransport()), $ledger, $registry,
            new InMemoryFinanceAccessScopeReader(), new AzureSqlTaskMailLinks($this->connection), $time, 'finance@example.test',
        );
        $actor = new TaskActor(11, 'Una User', 'una@example.test', [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_MAIL_SEND]);
        $task = $tasks->create($actor, ['name' => 'Rapprochement', 'preparer_id' => 11]);
        self::assertIsString($task['id']);

        self::assertSame([['email' => 'finance@example.test', 'label' => 'Équipe finance']], $mail->sendMailboxes($actor)['mailboxes']);
        $out = $mail->send($actor, ['kind' => 'task_email', 'emails' => [['task_id' => $task['id'], 'to' => 'una@example.test', 'subject' => 'Clôture — rappel', 'body' => "Bonjour,\n\nMerci."]]]);

        self::assertSame(0, $out['sent']);
        self::assertSame('Mail is switched off', $out['results'][0]['error']);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[mail_deliveries] WHERE [status] = N'suppressed' AND [application_key] = N'TASK'"));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[task_mail_links]'));
        $history = $mail->history($actor, $task['id']);
        self::assertSame(['Clôture — rappel'], array_column($history, 'subject'));
        self::assertSame(['suppressed'], array_column($history, 'status'));
        self::assertSame(['una@example.test'], $history[0]['to']);
        // The key is stored: task-<kind>:<link id>.
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[mail_deliveries] d JOIN [finance].[task_mail_links] l ON l.[delivery_id] = d.[id] AND d.[idempotency_key] = N'task-task_email:' + l.[id]"));
    }
}
