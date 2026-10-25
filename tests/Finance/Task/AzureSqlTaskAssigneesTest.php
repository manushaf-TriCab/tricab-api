<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\AzureSqlTaskSetupStore;
use TriCab\Api\Finance\Task\AzureSqlTaskStore;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * Several preparers and reviewers over `alter-finance-task-assignees.sql`, against a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one: the link rows in order, the people filter in SQL, a row an older
 * API wrote with only the old columns, the first-person columns kept in step, the deletes, templates, and the script's
 * backfill run twice.
 */
#[Group('local-sql-server')]
final class AzureSqlTaskAssigneesTest extends TestCase
{
    private PDO $pdo;

    private TaskService $tasks;

    private TaskSetupService $setup;

    private AzureSqlTaskStore $store;

    private TaskActor $max;

    private TaskActor $una;

    private TaskActor $ivy;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $this->store = new AzureSqlTaskStore($connection);
        $setupStore = new AzureSqlTaskSetupStore($connection);
        $directory = new InMemoryFinanceTaskMemberDirectory();
        foreach ([11 => 'Una User', 12 => 'Max Manager', 13 => 'Rae Reviewer', 16 => 'Ivy User'] as $id => $name) {
            $directory->add($id, $name, 'm' . $id . '@example.test');
        }
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:02:03.456789'), 'Australia/Melbourne');
        $this->tasks = new TaskService($this->store, $setupStore, $directory, $time);
        $this->setup = new TaskSetupService($setupStore, $directory, $time);
        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->ivy = new TaskActor(16, 'Ivy User', 'ivy@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE, Module::PERMISSION_TASK_SETUP_MANAGE]);
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private static function id(mixed $row): string
    {
        self::assertIsArray($row);
        self::assertIsString($row['id']);

        return $row['id'];
    }

    /** @return list<string> the task's people of one role as stored, in order */
    private function linked(string $taskId, string $role): array
    {
        $statement = $this->pdo->query("SELECT [user_id] FROM [finance].[task_assignees] WHERE [task_id] = '" . $taskId . "' AND [role] = N'" . $role . "' ORDER BY [sort_order]");
        self::assertInstanceOf(PDOStatement::class, $statement);

        return array_values(array_map(static fn (mixed $id): string => is_scalar($id) ? (string) $id : '', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function test_people_round_trip_in_order_and_the_old_columns_hold_the_first_of_each(): void
    {
        $t = $this->tasks->create($this->max, ['name' => 'Rec', 'preparer_ids' => ['16', '11'], 'reviewer_ids' => ['13', '12', '11']]);
        $id = self::id($t);

        $got = $this->tasks->get($this->max, $id);

        self::assertSame([['16', '11'], ['13', '12', '11']], [$got['preparer_ids'], $got['reviewer_ids']]);
        self::assertSame(['16', '13'], [$got['preparer_id'], $got['reviewer_id']]);
        self::assertSame(['16', '11'], $this->linked($id, 'preparer'));
        self::assertSame(16, $this->scalar("SELECT [preparer_user_id] FROM [finance].[task_tasks] WHERE [id] = '" . $id . "'"));
        self::assertSame(13, $this->scalar("SELECT [reviewer_user_id] FROM [finance].[task_tasks] WHERE [id] = '" . $id . "'"));
    }

    public function test_an_update_adds_removes_and_reorders_and_a_save_that_changes_nobody_writes_no_link_rows(): void
    {
        $id = self::id($this->tasks->create($this->max, ['name' => 'Rec', 'preparer_ids' => ['11', '16'], 'reviewer_ids' => ['13']]));

        $this->tasks->update($this->max, $id, ['preparer_ids' => ['16', '12'], 'reviewer_ids' => []]);

        self::assertSame(['16', '12'], $this->linked($id, 'preparer'));
        self::assertSame([], $this->linked($id, 'reviewer'));
        $row = $this->tasks->get($this->max, $id);
        self::assertSame([['16', '12'], [], '16', null], [$row['preparer_ids'], $row['reviewer_ids'], $row['preparer_id'], $row['reviewer_id']]);
        self::assertSame(0, $this->scalar("SELECT COUNT(*) FROM [finance].[task_tasks] WHERE [id] = '" . $id . "' AND [reviewer_user_id] IS NOT NULL"));

        $this->tasks->update($this->max, $id, ['progress' => 10]);
        self::assertSame(['16', '12'], $this->linked($id, 'preparer'));
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM [finance].[task_assignees]'));
    }

    public function test_anyone_on_the_task_sees_it_in_sql_and_the_project_counts_count_it_once(): void
    {
        $project = self::id($this->setup->createProject($this->max, ['name' => 'Close']));
        $this->tasks->create($this->max, ['name' => 'Shared', 'project_id' => $project, 'preparer_ids' => ['11', '16'], 'reviewer_ids' => ['11', '16']]);
        $this->tasks->create($this->max, ['name' => 'Una only', 'project_id' => $project, 'preparer_ids' => ['11']]);

        self::assertSame(['Shared'], array_column($this->tasks->list($this->ivy), 'name'));
        self::assertSame(2, count($this->tasks->list($this->una)));
        $counts = new AzureSqlTaskSetupStore(new PdoSqlConnection($this->pdo, new RequestPerformanceMetrics()));
        self::assertSame([$project => [2, 0]], $counts->projectTaskCounts(11), 'a task with the person twice is one task');
        self::assertSame([$project => [1, 0]], $counts->projectTaskCounts(16));
    }

    public function test_a_row_with_only_the_old_columns_still_reads_and_is_seen(): void
    {
        $id = 'e0000000000000000000000000000001';
        $this->pdo->exec("INSERT INTO [finance].[task_tasks] ([id], [name], [description], [requirements], [notes], [category], [finance_statement_area], [preparer_user_id], [reviewer_user_id], [status], [progress], [due_date_requested], [urgent], [created_date], [updated_date])
            VALUES ('" . $id . "', N'Old row', N'', N'', N'', N'routine', N'', 11, 13, N'active', 0, 0, 0, SYSUTCDATETIME(), SYSUTCDATETIME())");

        $got = $this->tasks->get($this->una, $id);

        self::assertSame([['11'], ['13']], [$got['preparer_ids'], $got['reviewer_ids']]);
        self::assertSame(['Old row'], array_column($this->tasks->list($this->una), 'name'));
        // The first save writes its link rows.
        $this->tasks->update($this->max, $id, ['preparer_ids' => ['11', '16']]);
        self::assertSame(['11', '16'], $this->linked($id, 'preparer'));
    }

    public function test_deleting_a_task_for_good_or_a_binned_project_removes_its_people(): void
    {
        $project = self::id($this->setup->createProject($this->max, ['name' => 'Close']));
        $a = self::id($this->tasks->create($this->max, ['name' => 'A', 'project_id' => $project, 'preparer_ids' => ['11', '16']]));
        $b = self::id($this->tasks->create($this->max, ['name' => 'B', 'project_id' => $project, 'reviewer_ids' => ['11', '16']]));
        $this->tasks->setUserState($this->una, $b, ['flagged' => true]);   // a per-person mark holds a foreign key to the task
        $this->tasks->delete($this->max, $a);
        $this->tasks->delete($this->max, $a, true);
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM [finance].[task_assignees]'));

        $this->setup->deleteProject($this->max, $project);
        $this->setup->deleteProject($this->max, $project, true);

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_tasks]'), $b);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_assignees]'));
    }

    public function test_a_template_keeps_its_people_in_order_and_deleting_it_removes_them(): void
    {
        $t = $this->setup->createTemplate(['name' => 'Rec', 'preparer_ids' => ['16', '11'], 'reviewer_ids' => ['13']]);
        $id = self::id($t);

        $got = $this->setup->listTemplates()[0];
        self::assertSame([['16', '11'], ['13'], '16'], [$got['preparer_ids'], $got['reviewer_ids'], $got['preparer_id']]);

        $this->setup->updateTemplate($id, ['preparer_ids' => ['11'], 'reviewer_ids' => ['13', '12']]);
        $got = $this->setup->listTemplates()[0];
        self::assertSame([['11'], ['13', '12']], [$got['preparer_ids'], $got['reviewer_ids']]);
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM [finance].[task_template_assignees]'));

        $this->setup->deleteTemplate($id);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_template_assignees]'));
    }

    public function test_the_script_backfills_from_the_old_columns_and_a_second_run_changes_nothing(): void
    {
        $this->pdo->exec("INSERT INTO [finance].[task_tasks] ([id], [name], [description], [requirements], [notes], [category], [finance_statement_area], [preparer_user_id], [reviewer_user_id], [status], [progress], [due_date_requested], [urgent], [created_date], [updated_date])
            VALUES ('e0000000000000000000000000000002', N'Before', N'', N'', N'', N'routine', N'', 11, NULL, N'active', 0, 0, 0, SYSUTCDATETIME(), SYSUTCDATETIME())");
        $this->pdo->exec("INSERT INTO [finance].[task_templates] ([id], [name], [category], [preparer_user_id], [reviewer_user_id], [default_progress], [due_date_requested], [created_date], [updated_date])
            VALUES ('e1000000000000000000000000000001', N'Tpl', N'routine', NULL, 12, 0, 0, SYSUTCDATETIME(), SYSUTCDATETIME())");
        $linkedTask = self::id($this->tasks->create($this->max, ['name' => 'Linked', 'preparer_ids' => ['16', '11']]));

        foreach ([1, 2] as $_run) {
            $script = file_get_contents(dirname(__DIR__, 3) . '/database/scripts/development/alter-finance-task-assignees.sql');
            self::assertIsString($script);
            foreach (preg_split('/^\s*GO\s*$/mi', $script) ?: [] as $batch) {
                if (trim($batch) !== '') {
                    $this->pdo->exec($batch);
                }
            }
            self::assertSame(['11'], $this->linked('e0000000000000000000000000000002', 'preparer'));
            self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM [finance].[task_template_assignees] WHERE [template_id] = 'e1000000000000000000000000000001' AND [role] = N'reviewer' AND [user_id] = 12"));
            self::assertSame(['16', '11'], $this->linked($linkedTask, 'preparer'), 'a task that has people is left as it is');
            self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM [finance].[task_assignees]'));
        }
    }
}
