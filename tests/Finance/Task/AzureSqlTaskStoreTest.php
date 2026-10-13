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
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * Tasks over `create-finance-task-schema.sql`, against a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one: the transaction around a bulk update, the
 * history written with the change, the Bin and the permanent delete's order, long text, a large
 * person id, and the visibility filter in SQL.
 */
#[Group('local-sql-server')]
final class AzureSqlTaskStoreTest extends TestCase
{
    private const BIG_ID = 9007199254740993;   // one past what a double holds exactly

    private PDO $pdo;

    private FixedClock $clock;

    private TaskService $tasks;

    private TaskSetupService $setup;

    private TaskActor $max;

    private TaskActor $una;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $store = new AzureSqlTaskStore($connection);
        $setupStore = new AzureSqlTaskSetupStore($connection);
        $directory = new InMemoryFinanceTaskMemberDirectory();
        foreach ([11 => 'Una User', 12 => 'Max Manager', self::BIG_ID => 'Big Id'] as $id => $name) {
            $directory->add($id, $name, 'm' . $id . '@example.test');
        }
        $this->clock = FixedClock::utc('2026-10-07 01:02:03.456789');
        $time = new TaskTime($this->clock, 'Australia/Melbourne');
        $this->tasks = new TaskService($store, $setupStore, $directory, $time);
        $this->setup = new TaskSetupService($setupStore, $directory, $time);

        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE, Module::PERMISSION_TASK_SETUP_MANAGE]);
    }

    private function rows(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM [finance].[' . $table . ']');
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private static function id(mixed $row): string
    {
        self::assertIsArray($row);
        self::assertIsString($row['id']);

        return $row['id'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function make(string $name, array $data = []): array
    {
        $this->clock->advance('+1 second');

        return $this->tasks->create($this->max, ['name' => $name] + $data);
    }

    public function test_a_task_round_trips_with_long_unicode_text_dates_and_a_large_person_id(): void
    {
        $long = str_repeat("Réconciliation bancaire — ligne ✓\n", 1_500);
        $t = $this->make('Clôture — mois ✓', ['description' => $long, 'requirements' => '- a', 'notes' => 'n', 'preparer_id' => (string) self::BIG_ID, 'reviewer_id' => 11, 'due_date' => '2026-10-31', 'urgent' => true, 'due_date_requested' => true, 'progress' => 45, 'category' => 'ad_hoc']);

        $got = $this->tasks->get($this->max, self::id($t));

        self::assertSame('Clôture — mois ✓', $got['name']);
        self::assertSame(trim($long), $got['description']);
        self::assertSame((string) self::BIG_ID, $got['preparer_id']);
        self::assertSame('11', $got['reviewer_id']);
        self::assertSame('2026-10-31', $got['due_date']);
        self::assertTrue($got['urgent']);
        self::assertTrue($got['due_date_requested']);
        self::assertSame(45, $got['progress']);
        self::assertSame('ad_hoc', $got['category']);
        self::assertSame('2026-10-07T01:02:04.456789+00:00', $got['created_date']);
        self::assertSame('12', $got['created_by_id']);
    }

    public function test_the_register_is_ordered_newest_change_first_and_filtered_for_a_member_in_sql(): void
    {
        $this->make('First', ['preparer_id' => 11]);
        $this->make('Second', ['preparer_id' => 12]);
        $this->make('Third', ['reviewer_id' => 11]);

        self::assertSame(['Third', 'Second', 'First'], array_column($this->tasks->list($this->max), 'name'));
        self::assertSame(['Third', 'First'], array_column($this->tasks->list($this->una), 'name'));
    }

    public function test_a_status_change_and_its_history_are_written_together_and_a_creation_row_exists(): void
    {
        $t = $this->make('x', ['preparer_id' => 12]);
        $id = self::id($t);
        $this->clock->advance('+1 second');
        $this->tasks->update($this->max, $id, ['status' => 'completed']);

        $rows = $this->tasks->history($this->max, $id);

        self::assertSame([['active', 'completed'], [null, 'active']], array_map(
            static fn (array $row): array => [$row['from_status'], $row['to_status']],
            $rows,
        ));
        self::assertSame('Max Manager', $rows[0]['changed_by']);
        self::assertCount(1, $this->tasks->historyByStatus('completed'));
        self::assertCount(2, $this->tasks->historyByStatus(null));
    }

    public function test_a_failing_bulk_update_leaves_nothing_behind_in_the_database(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');
        $gate = $this->make('Gate');
        $c = $this->make('C', ['dependencies' => [self::id($gate)]]);
        $before = $this->rows('task_history');

        try {
            $this->tasks->bulkUpdate($this->max, [
                ['id' => self::id($a), 'status' => 'completed', 'progress' => 100],
                ['id' => self::id($b), 'name' => 'Renamed'],
                ['id' => self::id($c), 'status' => 'completed'],
            ]);
            self::fail('Expected a conflict.');
        } catch (TaskConflict) {
            $this->addToAssertionCount(1);
        }

        self::assertSame($before, $this->rows('task_history'), 'no history row survived');
        self::assertSame('B', $this->tasks->get($this->max, self::id($b))['name']);
        self::assertSame('active', $this->tasks->get($this->max, self::id($a))['status']);
        self::assertFalse((new PdoSqlConnection($this->pdo, new RequestPerformanceMetrics()))->inTransaction());
    }

    public function test_a_good_bulk_update_commits_every_row_with_its_history(): void
    {
        $a = $this->make('A');
        $b = $this->make('B');

        $out = $this->tasks->bulkUpdate($this->max, [['id' => self::id($a), 'status' => 'completed'], ['id' => self::id($b), 'progress' => 70]]);

        self::assertSame(['completed', 'active'], array_column($out, 'status'));
        self::assertSame(70, $out[1]['progress']);
        self::assertCount(1, $this->tasks->historyByStatus('completed'));
    }

    public function test_dependencies_replace_and_clear_and_the_cycle_check_reads_binned_tasks(): void
    {
        $a = $this->make('A');
        $b = $this->make('B', ['dependencies' => [self::id($a)]]);
        $c = $this->make('C', ['dependencies' => [self::id($b)]]);
        self::assertSame([self::id($b)], $this->tasks->get($this->max, self::id($c))['dependencies']);

        $this->tasks->delete($this->max, self::id($b));
        try {
            $this->tasks->update($this->max, self::id($a), ['dependencies' => [self::id($c)]]);
            self::fail('Expected a refusal.');
        } catch (\TriCab\Core\Finance\Task\Domain\TaskInvalid $e) {
            self::assertSame('Those dependencies would make the task wait on itself', $e->getMessage());
        }
        self::assertSame([], $this->tasks->update($this->max, self::id($c), ['dependencies' => []])['dependencies']);
        self::assertSame(1, $this->rows('task_dependencies'), 'only B waiting for A remains');
    }

    public function test_a_permanent_delete_removes_children_first_and_leaves_other_tasks_alone(): void
    {
        $a = $this->make('A');
        $b = $this->make('B', ['dependencies' => [self::id($a)]]);
        $c = $this->make('C', ['dependencies' => [self::id($b)]]);
        $this->tasks->update($this->max, self::id($a), ['status' => 'completed']);
        $this->tasks->delete($this->max, self::id($b));

        $this->tasks->delete($this->max, self::id($b), true);

        self::assertSame(2, $this->rows('task_tasks'));
        self::assertSame(0, $this->rows('task_dependencies'));
        self::assertSame(3, $this->rows('task_history'), 'A has two rows, C one; B\'s went');
        self::assertSame([], $this->tasks->get($this->max, self::id($c))['dependencies']);
    }

    public function test_the_bin_hides_tasks_from_the_register_and_restore_returns_them(): void
    {
        $t = $this->make('x');
        $id = self::id($t);
        $this->tasks->delete($this->max, $id);

        self::assertSame([], $this->tasks->list($this->max));
        self::assertSame(['x'], array_column($this->tasks->list($this->max, true), 'name'));
        $this->tasks->restore($this->max, $id);
        self::assertSame(['x'], array_column($this->tasks->list($this->max), 'name'));
    }

    public function test_project_entity_and_area_guards_read_the_real_tasks(): void
    {
        $project = $this->setup->createProject($this->max, ['name' => 'Close']);
        $entity = $this->setup->createEntity(['name' => 'Alpha Pty']);
        $area = $this->setup->createArea(['name' => 'Cash']);
        $this->make('x', ['project_id' => self::id($project), 'entity_id' => self::id($entity), 'finance_statement_area' => 'Cash', 'preparer_id' => 11]);

        self::assertSame(1, $this->setup->listProjects($this->max)[0]['task_count']);
        self::assertSame(1, $this->setup->listProjects($this->una)[0]['task_count']);
        self::assertSame('Close', $this->tasks->list($this->max)[0]['project_name']);
        foreach ([
            fn () => $this->setup->deleteEntity(self::id($entity)),
            fn () => $this->setup->deleteArea(self::id($area)),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a conflict.');
            } catch (TaskConflict) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
