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
use TriCab\Api\Finance\Task\AzureSqlTaskCalendarInvites;
use TriCab\Api\Finance\Task\AzureSqlTaskMailLinks;
use TriCab\Api\Finance\Task\AzureSqlTaskReportRecipientStore;
use TriCab\Api\Finance\Task\AzureSqlTaskSetupStore;
use TriCab\Api\Finance\Task\AzureSqlTaskStore;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Api\Tests\Finance\Mail\RecordingFinanceMailTransport;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskCalendarInvite;
use TriCab\Core\Finance\Task\Service\TaskCalendarInviteService;
use TriCab\Core\Finance\Task\Service\TaskMailService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * Calendar invites over `alter-finance-task-calendar-invites.sql` on a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one. Runs the same scenarios as the in-memory twin.
 */
#[Group('local-sql-server')]
final class AzureSqlTaskCalendarInviteTest extends TestCase
{
    use TaskCalendarInviteScenarios;

    private PDO $pdo;

    private AzureSqlTaskStore $taskStore;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(11, 'Una User', 'una@example.test');
        $directory->add(12, 'Max Manager', 'max@example.test');
        $directory->add(13, 'Rae Reviewer', 'rae@example.test');
        $directory->add(14, 'Out Of Mail', '');
        $directory->add(16, 'Ivy User', 'ivy@example.test');
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:02:03.456789'), 'Australia/Melbourne');
        $this->taskStore = new AzureSqlTaskStore($connection);
        $setup = new AzureSqlTaskSetupStore($connection);
        $this->store = new AzureSqlTaskCalendarInvites($connection);
        $this->tasks = new TaskService($this->taskStore, $setup, $directory, $time, null, $this->store);
        $registry = new AzureSqlFinanceMailboxRegistry($connection);
        $ledger = new AzureSqlFinanceMailLedger($connection);
        $this->transport = new RecordingFinanceMailTransport();
        $sender = new FinanceMailService($registry, $ledger, $this->transport, null, null);
        $links = new AzureSqlTaskMailLinks($connection);
        $mail = new TaskMailService($this->tasks, $directory, new AzureSqlTaskReportRecipientStore($connection), $sender, $ledger, $registry, new InMemoryFinanceAccessScopeReader(), $links, $time, '', true);
        $this->invites = new TaskCalendarInviteService($this->tasks, $mail, $setup, $directory, $sender, $links, $this->store, $time);
        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN, Module::PERMISSION_TASK_MAIL_SEND];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE]);
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private function runScript(): void
    {
        $script = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/alter-finance-task-calendar-invites.sql');
        self::assertIsString($script);
        foreach (preg_split('/^\s*GO\s*$/mi', $script) ?: [] as $batch) {
            if (trim($batch) !== '') {
                $this->pdo->exec($batch);
            }
        }
    }

    public function test_the_script_can_run_again_and_changes_no_row(): void
    {
        $id = $this->make();
        $this->invites->send($this->una, [$id]);
        $before = $this->scalar('SELECT COUNT(*) FROM [finance].[task_calendar_invites]');

        $this->runScript();
        $this->runScript();

        self::assertSame($before, $this->scalar('SELECT COUNT(*) FROM [finance].[task_calendar_invites]'));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM sys.check_constraints WHERE [name] = N'CK_finance_task_mail_links_kind' AND [definition] LIKE N'%calendar_invite%'"));
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[task_mail_links] WHERE [kind] = N'calendar_invite'"));
    }

    public function test_a_row_is_replaced_not_added_and_round_trips_its_values(): void
    {
        $id = $this->make();
        $at = new DateTimeImmutable('2026-10-07 01:02:03.456789', new DateTimeZone('UTC'));
        $this->store->save(new TaskCalendarInvite($id, 'uid-é@tricab.ai', 0, '2026-10-30', 11, 'una@example.test', ['a@x.test', 'b@x.test'], $at));
        $this->store->save(new TaskCalendarInvite($id, 'uid-é@tricab.ai', 1, '2026-11-06', null, 'max@example.test', ['a@x.test'], $at->modify('+1 day')));

        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[task_calendar_invites]'));
        $found = $this->store->find($id);
        self::assertSame(['uid-é@tricab.ai', 1, '2026-11-06', null, 'max@example.test', ['a@x.test']], [$found?->uid, $found?->sequence, $found?->dueDateSent, $found?->sentBy, $found?->organizerEmail, $found?->recipients]);
        self::assertSame('2026-10-08 01:02:03.456789', $found?->sentAt->format('Y-m-d H:i:s.u'));
        self::assertNull($this->store->find('ffffffffffffffffffffffffffffffff'));
        self::assertSame([$id], array_keys($this->store->all()));
    }

    public function test_permanently_deleting_a_task_takes_its_invite_row_with_it(): void
    {
        $id = $this->make();
        $this->invites->send($this->una, [$id]);
        $this->tasks->delete($this->max, $id);
        $this->tasks->delete($this->max, $id, true);

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_calendar_invites]'));
    }

    public function test_a_list_reads_every_invite_in_one_extra_query(): void
    {
        $a = $this->make(['name' => 'A']);
        $b = $this->make(['name' => 'B']);
        $this->make(['name' => 'C']);
        $this->invites->send($this->una, [$a, $b]);

        $rows = $this->tasks->list($this->max);
        $with = array_values(array_filter($rows, static fn (array $t): bool => $t['calendar_invite'] !== null));

        self::assertCount(2, $with);
        self::assertCount(3, $rows);
    }
}
