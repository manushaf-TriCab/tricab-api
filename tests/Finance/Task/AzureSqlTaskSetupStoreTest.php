<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\AzureSqlTaskSetupStore;
use TriCab\Api\Infrastructure\Database\PdoSqlConnection;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\LocalFinanceSqlServer;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskEntity;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskProject;
use TriCab\Core\Finance\Task\Domain\TaskStatementArea;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * The reference lists over `create-finance-task-schema.sql`, against a real local SQL Server
 * ({@see LocalFinanceSqlServer}); skipped without one. The filtered unique indexes, the guards
 * the service reads counts for, the transaction and a unicode round trip.
 */
#[Group('local-sql-server')]
final class AzureSqlTaskSetupStoreTest extends TestCase
{
    private PDO $pdo;

    private AzureSqlTaskSetupStore $store;

    private TaskSetupService $service;

    private TaskActor $manager;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        $this->pdo = $pdo;
        LocalFinanceSqlServer::truncate($pdo);
        $this->store = new AzureSqlTaskSetupStore(new PdoSqlConnection($pdo, new RequestPerformanceMetrics()));
        $this->service = new TaskSetupService(
            $this->store,
            new InMemoryFinanceTaskMemberDirectory(),
            new TaskTime(FixedClock::utc('2026-10-07 01:02:03.456789'), 'Australia/Melbourne'),
        );
        $this->manager = new TaskActor(7, 'Mia', 'mia@example.test', [Module::PERMISSION_TASK_SETUP_MANAGE]);
    }

    private static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-07 01:02:03.456789', new DateTimeZone('UTC'));
    }

    private function project(string $id, string $name, ?DateTimeImmutable $deleted = null): TaskProject
    {
        return new TaskProject($id, $name, '', '', 'active', '', $deleted, $deleted === null ? null : 7, self::at(), self::at());
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    private function insertTask(string $id, ?string $project, ?string $entity, string $area, ?int $preparer, ?int $reviewer, string $status = 'in_progress', bool $binned = false): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO [finance].[task_tasks] ([id], [name], [category], [status], [progress], [project_id], [entity_id], [finance_statement_area], [preparer_user_id], [reviewer_user_id], [deleted_at])
             VALUES (:id, :name, N\'routine\', :status, 0, :project, :entity, :area, :preparer, :reviewer, :deleted)',
        );
        self::assertInstanceOf(PDOStatement::class, $statement);
        $statement->execute([
            'id' => $id, 'name' => 'Task ' . $id, 'status' => $status, 'project' => $project, 'entity' => $entity, 'area' => $area,
            'preparer' => $preparer, 'reviewer' => $reviewer, 'deleted' => $binned ? '2026-10-07 00:00:00' : null,
        ]);
    }

    public function test_a_project_round_trips_with_unicode_and_six_digit_time(): void
    {
        $created = $this->service->createProject($this->manager, ['name' => 'Clôture — mois ✓', 'description' => "Line one\nLine two  ", 'target_date' => '2026-10-31', 'color' => '#6366F1']);
        $id = $created['id'];
        self::assertIsString($id);

        $found = $this->store->findProject($id);

        self::assertNotNull($found);
        self::assertSame('Clôture — mois ✓', $found->name);
        self::assertSame('Line one Line two', $found->description === '' ? '' : preg_replace('/\s+/', ' ', trim($found->description)));
        self::assertSame('2026-10-31', $found->targetDate);
        self::assertSame('2026-10-07 01:02:03.456789', $found->createdDate->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-10-07T01:02:03.456789+00:00', $created['created_date']);
    }

    public function test_a_live_project_name_is_unique_but_a_binned_one_does_not_block_and_restore_is_refused(): void
    {
        $this->store->insertProject($this->project('a0000000000000000000000000000001', 'Close'));

        try {
            $this->store->insertProject($this->project('a0000000000000000000000000000002', 'CLOSE'));
            self::fail('Expected a conflict from the index.');
        } catch (TaskConflict $e) {
            self::assertSame('A project with that name already exists', $e->getMessage());
        }

        $binned = $this->project('a0000000000000000000000000000003', 'Audit', self::at());
        $this->store->insertProject($binned);
        $this->store->insertProject($this->project('a0000000000000000000000000000004', 'audit'));
        self::assertSame(2, $this->scalar("SELECT COUNT(*) FROM [finance].[task_projects] WHERE [name] = N'Audit'"));

        $binned->deletedAt = null;
        $this->expectException(TaskConflict::class);
        $this->store->updateProject($binned);
    }

    public function test_the_service_refuses_first_and_the_index_is_the_last_word_on_a_race(): void
    {
        $this->service->createProject($this->manager, ['name' => 'Close']);
        try {
            $this->service->createProject($this->manager, ['name' => 'close']);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('A project with that name already exists', $e->getMessage());
        }
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[task_projects]'));
    }

    public function test_soft_delete_bin_restore_and_permanent_delete(): void
    {
        $p = $this->service->createProject($this->manager, ['name' => 'Close']);
        $id = $p['id'];
        self::assertIsString($id);

        $this->service->deleteProject($this->manager, $id);
        self::assertSame([], $this->service->listProjects($this->manager));
        $bin = $this->service->listProjects($this->manager, true);
        self::assertSame(['Close'], array_column($bin, 'name'));
        self::assertSame('2026-10-07T01:02:03.456789+00:00', $bin[0]['deleted_at']);
        self::assertSame(7, $this->scalar("SELECT [deleted_by] FROM [finance].[task_projects] WHERE [id] = '" . $id . "'"));

        $this->service->restoreProject($this->manager, $id);
        self::assertSame(['Close'], array_column($this->service->listProjects($this->manager), 'name'));

        $this->service->deleteProject($this->manager, $id);
        $this->service->deleteProject($this->manager, $id, true);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_projects]'));
    }

    public function test_project_counts_use_live_tasks_and_the_callers_visibility(): void
    {
        $p = $this->service->createProject($this->manager, ['name' => 'Close']);
        $id = $p['id'];
        self::assertIsString($id);
        $this->insertTask('d0000000000000000000000000000001', $id, null, '', 11, null);
        $this->insertTask('d0000000000000000000000000000002', $id, null, '', null, 11, 'completed');
        $this->insertTask('d0000000000000000000000000000003', $id, null, '', 12, null);
        $this->insertTask('d0000000000000000000000000000004', $id, null, '', 11, null, 'in_progress', true);

        self::assertSame([$id => [3, 1]], $this->store->projectTaskCounts(null));
        self::assertSame([$id => [2, 1]], $this->store->projectTaskCounts(11));
        self::assertSame([], $this->store->projectTaskCounts(99));
        self::assertSame(4, $this->store->projectTaskCount($id));
        self::assertSame(3, $this->store->projectLiveTaskCount($id));
    }

    public function test_binning_a_project_bins_its_live_tasks_and_restoring_it_brings_back_only_those(): void
    {
        $id = $this->createProject('Close');
        $other = $this->createProject('Other');
        $live1 = 'd0000000000000000000000000000001';
        $live2 = 'd0000000000000000000000000000002';
        $early = 'd0000000000000000000000000000003';
        $elsewhere = 'd0000000000000000000000000000004';
        $this->insertTask($live1, $id, null, '', 11, null);
        $this->insertTask($live2, $id, null, '', 11, null);
        $this->insertTask($early, $id, null, '', 11, null, 'in_progress', true);
        $this->insertTask($elsewhere, $other, null, '', 11, null);

        $this->service->deleteProject($this->manager, $id);

        $stamp = '2026-10-07 01:02:03.456789';
        foreach ([$live1, $live2] as $task) {
            self::assertSame($stamp, $this->taskColumn($task, 'CONVERT(VARCHAR(30), [deleted_at], 121)'));
            self::assertSame('7', $this->taskColumn($task, '[deleted_by]'));
        }
        self::assertSame('2026-10-07 00:00:00.000000', $this->taskColumn($early, 'CONVERT(VARCHAR(30), [deleted_at], 121)'));
        self::assertNull($this->taskColumn($elsewhere, '[deleted_at]'));
        self::assertSame([$id => 2], $this->store->binnedProjectTaskCounts());
        self::assertSame(2, $this->service->listProjects($this->manager, true)[0]['binned_task_count']);

        $this->service->restoreProject($this->manager, $id);

        self::assertNull($this->taskColumn($live1, '[deleted_at]'));
        self::assertNull($this->taskColumn($live1, '[deleted_by]'));
        self::assertNull($this->taskColumn($live2, '[deleted_at]'));
        self::assertNotNull($this->taskColumn($early, '[deleted_at]'));
        self::assertSame([], $this->store->binnedProjectTaskCounts());
        self::assertSame([2, 0], $this->store->projectTaskCounts(null)[$id]);
    }

    public function test_permanent_delete_removes_binned_tasks_with_dependencies_and_history_but_not_live_ones(): void
    {
        $id = $this->createProject('Close');
        $other = $this->createProject('Other');
        $a = 'd0000000000000000000000000000001';
        $b = 'd0000000000000000000000000000002';
        $outside = 'd0000000000000000000000000000003';
        $this->insertTask($a, $id, null, '', 11, null);
        $this->insertTask($b, $id, null, '', 11, null);
        $this->insertTask($outside, $other, null, '', 11, null);
        $this->pdo->exec("INSERT INTO [finance].[task_dependencies] ([id], [task_id], [depends_on_task_id]) VALUES ('e0000000000000000000000000000001', '{$b}', '{$a}'), ('e0000000000000000000000000000002', '{$outside}', '{$a}')");
        $this->pdo->exec("INSERT INTO [finance].[task_history] ([id], [task_id], [to_status], [changed_at]) VALUES ('f0000000000000000000000000000001', '{$a}', N'in_progress', SYSUTCDATETIME()), ('f0000000000000000000000000000002', '{$outside}', N'in_progress', SYSUTCDATETIME())");

        $this->service->deleteProject($this->manager, $id);
        // One task restored on its own is live: the permanent delete is refused and nothing goes.
        $this->pdo->exec("UPDATE [finance].[task_tasks] SET [deleted_at] = NULL, [deleted_by] = NULL WHERE [id] = '{$b}'");
        try {
            $this->service->deleteProject($this->manager, $id, true);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('1 task(s) still belong to this project', $e->getMessage());
        }
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM [finance].[task_tasks]'));

        $this->pdo->exec("UPDATE [finance].[task_tasks] SET [deleted_at] = SYSUTCDATETIME() WHERE [id] = '{$b}'");
        self::assertSame(['deleted' => true, 'permanent' => true], $this->service->deleteProject($this->manager, $id, true));

        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[task_tasks]'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_dependencies]'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[task_history]'));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_projects] WHERE [id] = \'' . $id . '\''));
    }

    public function test_binning_a_project_is_all_or_nothing(): void
    {
        $id = $this->createProject('Close');
        $task = 'd0000000000000000000000000000001';
        $this->insertTask($task, $id, null, '', 11, null);
        // Make the task write fail after the project stamp was written.
        $this->pdo->exec('ALTER TABLE [finance].[task_tasks] ADD CONSTRAINT [CK_tt6_never] CHECK ([deleted_by] IS NULL OR [deleted_by] < 0)');
        try {
            $this->service->deleteProject($this->manager, $id);
            self::fail('Expected the database to refuse.');
        } catch (\Throwable) {
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_projects] WHERE [deleted_at] IS NOT NULL'), 'the project stamp rolled back');
            self::assertNull($this->taskColumn($task, '[deleted_at]'));
        } finally {
            $this->pdo->exec('ALTER TABLE [finance].[task_tasks] DROP CONSTRAINT [CK_tt6_never]');
        }
    }

    private function createProject(string $name): string
    {
        $id = $this->service->createProject($this->manager, ['name' => $name])['id'];
        self::assertIsString($id);

        return $id;
    }

    private function taskColumn(string $id, string $expression): ?string
    {
        $statement = $this->pdo->query("SELECT {$expression} FROM [finance].[task_tasks] WHERE [id] = '{$id}'");
        self::assertInstanceOf(PDOStatement::class, $statement);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    public function test_areas_and_entities_are_unique_by_name_now_and_guarded_by_their_tasks(): void
    {
        $area = $this->service->createArea(['name' => 'Cash', 'category' => 'cash_flow']);
        $entity = $this->service->createEntity(['name' => 'Alpha Pty', 'code' => 'AP']);
        foreach ([
            fn () => $this->service->createArea(['name' => 'CASH']),
            fn () => $this->service->createEntity(['name' => 'alpha pty']),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a conflict.');
            } catch (TaskConflict) {
                $this->addToAssertionCount(1);
            }
        }
        // The index itself, bypassing the service's pre-check.
        $now = self::at();
        try {
            $this->store->insertArea(new TaskStatementArea('c0000000000000000000000000000009', 'cash', 'other', '', 0, $now, $now));
            self::fail('Expected the index to refuse.');
        } catch (TaskConflict $e) {
            self::assertSame('A statement area with that name already exists', $e->getMessage());
        }
        try {
            $this->store->insertEntity(new TaskEntity('b0000000000000000000000000000009', 'ALPHA PTY', '', '', '', true, $now, $now));
            self::fail('Expected the index to refuse.');
        } catch (TaskConflict $e) {
            self::assertSame('An entity with that name already exists', $e->getMessage());
        }

        $areaId = $area['id'];
        $entityId = $entity['id'];
        self::assertIsString($areaId);
        self::assertIsString($entityId);
        $this->insertTask('d0000000000000000000000000000001', null, $entityId, 'Cash', null, null, 'in_progress', true);
        self::assertSame(['deleted' => true], $this->service->deleteArea($areaId), 'a binned task does not hold an area');
        try {
            $this->service->deleteEntity($entityId);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('1 task(s) still use this entity', $e->getMessage(), 'a binned task still holds an entity');
        }
        $this->insertTask('d0000000000000000000000000000002', null, null, 'Equity', null, null);
        self::assertSame(1, $this->store->areaLiveTaskCount('equity'));
    }

    public function test_bulk_areas_are_one_transaction_and_a_late_failure_leaves_none(): void
    {
        $this->service->createArea(['name' => 'Cash']);
        try {
            $this->service->bulkCreateAreas([['name' => 'Revenue'], ['name' => 'Equity'], ['name' => '']]);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM [finance].[task_statement_areas]'));

        // A failure inside the writes (here: a clash the pre-check could not see) rolls the batch back.
        $now = self::at();
        $this->store->transactional(function () use ($now): void {
            $this->store->insertArea(new TaskStatementArea('c0000000000000000000000000000001', 'Rolled back', 'other', '', 0, $now, $now));
        });
        try {
            $this->store->transactional(function () use ($now): void {
                $this->store->insertArea(new TaskStatementArea('c0000000000000000000000000000002', 'Also rolled back', 'other', '', 0, $now, $now));
                $this->store->insertArea(new TaskStatementArea('c0000000000000000000000000000003', 'cash', 'other', '', 0, $now, $now));
            });
            self::fail('Expected a conflict.');
        } catch (TaskConflict) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(['Cash', 'Rolled back'], array_column($this->service->listAreas(), 'name'));
        self::assertFalse((new PdoSqlConnection($this->pdo, new RequestPerformanceMetrics()))->inTransaction());
    }

    public function test_areas_sort_by_sort_order_then_name_and_templates_round_trip(): void
    {
        $this->service->createArea(['name' => 'b', 'sort_order' => 2]);
        $this->service->createArea(['name' => 'z', 'sort_order' => 1]);
        $this->service->createArea(['name' => 'a', 'sort_order' => 2]);
        self::assertSame(['z', 'a', 'b'], array_column($this->service->listAreas(), 'name'));

        $t = $this->service->createTemplate(['name' => 'Rapprochement — banque', 'requirements' => "- a\n- b", 'progress' => 25, 'due_date' => '2026-10-31', 'due_date_requested' => true, 'category' => 'ad_hoc']);
        $id = $t['id'];
        self::assertIsString($id);
        $found = $this->store->findTemplate($id);
        self::assertNotNull($found);
        self::assertSame("- a\n- b", $found->requirements);
        self::assertSame(25, $found->defaultProgress);
        self::assertTrue($found->dueDateRequested);
        self::assertNull($found->preparerUserId);
        self::assertSame('ad_hoc', $found->category);
        $this->service->deleteTemplate($id);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_templates]'));
    }

    public function test_project_groups_round_trip_in_order_and_a_project_keeps_its_group(): void
    {
        $odoo = $this->service->createProjectGroup(['name' => 'Odoo — mise en œuvre', 'color' => '#6366F1']);
        $audit = $this->service->createProjectGroup(['name' => 'Audit']);
        $odooId = $odoo['id'];
        self::assertIsString($odooId);
        self::assertSame(1, $audit['sort_order']);
        $this->service->updateProjectGroup($odooId, ['sort_order' => 2, 'color' => '']);

        $groups = $this->store->projectGroups();
        self::assertSame(['Audit', 'Odoo — mise en œuvre'], array_map(static fn ($g): string => $g->name, $groups));
        $found = $this->store->findProjectGroup($odooId);
        self::assertNotNull($found);
        self::assertNull($found->color);
        self::assertSame(2, $found->sortOrder);
        self::assertSame('2026-10-07 01:02:03.456789', $found->createdDate->format('Y-m-d H:i:s.u'));
        self::assertNull($this->store->findProjectGroup('ffffffffffffffffffffffffffffffff'));

        $project = $this->service->createProject($this->manager, ['name' => 'Close', 'group_id' => $odooId]);
        $projectId = $project['id'];
        self::assertIsString($projectId);
        self::assertSame($odooId, $this->store->findProject($projectId)?->groupId);
        self::assertSame($odooId, $this->service->listProjects($this->manager)[0]['group_id']);
        $this->service->updateProject($this->manager, $projectId, ['group_id' => null]);
        self::assertNull($this->store->findProject($projectId)?->groupId);
    }

    public function test_deleting_a_project_group_ungroups_live_and_binned_projects_in_one_transaction(): void
    {
        $group = $this->service->createProjectGroup(['name' => 'Odoo']);
        $groupId = $group['id'];
        self::assertIsString($groupId);
        $live = $this->project('a0000000000000000000000000000001', 'Live');
        $live->groupId = $groupId;
        $this->store->insertProject($live);
        $binned = $this->project('a0000000000000000000000000000002', 'Binned', self::at());
        $binned->groupId = $groupId;
        $this->store->insertProject($binned);
        $this->store->insertProject($this->project('a0000000000000000000000000000003', 'Loose'));

        self::assertSame(['deleted' => true], $this->service->deleteProjectGroup($groupId));

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_project_groups]'));
        self::assertSame(3, $this->scalar('SELECT COUNT(*) FROM [finance].[task_projects]'), 'no project is deleted');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM [finance].[task_projects] WHERE [group_id] IS NOT NULL'));
        self::assertFalse((new PdoSqlConnection($this->pdo, new RequestPerformanceMetrics()))->inTransaction());
    }
}
