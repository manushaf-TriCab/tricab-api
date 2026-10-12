<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\AzureSqlTaskBoardStore;
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
use TriCab\Core\Finance\Task\Domain\TaskMember;
use TriCab\Core\Finance\Task\Domain\TaskProjectTemplate;
use TriCab\Core\Finance\Task\Service\TaskBoardService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * The board's tables (`alter-finance-task-board.sql`) against a real local SQL Server ({@see LocalFinanceSqlServer});
 * skipped without one: the new task columns, groups, a template's JSON round trip and unique name, a template
 * applied in one transaction, and a project with groups deleted for good.
 */
#[Group('local-sql-server')]
final class AzureSqlTaskBoardStoreTest extends TestCase
{
    private AzureSqlTaskBoardStore $board;

    private TaskService $tasks;

    private TaskSetupService $setup;

    private TaskBoardService $service;

    private TaskActor $manager;

    protected function setUp(): void
    {
        $pdo = LocalFinanceSqlServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        LocalFinanceSqlServer::truncate($pdo);
        $connection = new PdoSqlConnection($pdo, new RequestPerformanceMetrics());
        $store = new AzureSqlTaskStore($connection);
        $setupStore = new AzureSqlTaskSetupStore($connection);
        $this->board = new AzureSqlTaskBoardStore($connection);
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(7, 'Mia', 'mia@example.test', TaskMember::ROLE_MANAGER);
        $directory->add(8, 'Oli', 'oli@example.test');
        $time = new TaskTime(FixedClock::utc('2026-10-13 01:02:03.456789'), 'Australia/Melbourne');
        $this->tasks = new TaskService($store, $setupStore, $directory, $time, $this->board);
        $this->setup = new TaskSetupService($setupStore, $directory, $time);
        $this->service = new TaskBoardService($this->board, $store, $setupStore, $this->tasks, $this->setup, $directory, $time);
        $this->manager = new TaskActor(7, 'Mia', 'mia@example.test', [
            Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE,
            Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_SETUP_MANAGE, Module::PERMISSION_TASK_TASKS_DELETE,
        ]);
    }

    public function test_a_template_with_unicode_round_trips_and_its_name_is_unique(): void
    {
        $template = $this->service->createProjectTemplate($this->manager, [
            'name' => 'Clôture mensuelle',
            'groups' => [['name' => 'Études — Odoo', 'color' => '#a855f7', 'tasks' => [['name' => 'Rapprochement', 'priority' => 'high', 'due_offset_days' => 5]]]],
        ]);
        self::assertIsString($template['id']);
        $read = $this->board->findProjectTemplate($template['id']);
        self::assertInstanceOf(TaskProjectTemplate::class, $read);
        self::assertSame('Études — Odoo', $read->groups[0]['name']);
        $tasks = $read->groups[0]['tasks'];
        self::assertIsArray($tasks);
        self::assertIsArray($tasks[0]);
        self::assertSame(5, $tasks[0]['due_offset_days']);

        $this->expectException(TaskConflict::class);
        $this->service->createProjectTemplate($this->manager, ['name' => 'CLÔTURE MENSUELLE']);
    }

    public function test_applying_a_template_writes_the_project_groups_and_tasks_with_priority_and_group(): void
    {
        $template = $this->service->createProjectTemplate($this->manager, [
            'name' => 'Odoo rollout',
            'groups' => [
                ['name' => 'Odoo Studying', 'tasks' => [['name' => 'Sales Module', 'priority' => 'critical', 'preparer_id' => '8', 'due_offset_days' => 7]]],
                ['name' => 'Odoo Implementation', 'tasks' => [['name' => 'Customer List']]],
            ],
        ]);
        self::assertIsString($template['id']);

        $out = $this->service->applyProjectTemplate($this->manager, $template['id'], ['name' => 'Odoo Project', 'start_date' => '2026-11-02']);

        self::assertSame(2, $out['tasks_created']);
        $byName = [];
        foreach ($this->tasks->list($this->manager) as $task) {
            self::assertIsString($task['name']);
            $byName[$task['name']] = $task;
        }
        self::assertSame('critical', $byName['Sales Module']['priority']);
        self::assertSame('2026-11-09', $byName['Sales Module']['due_date']);
        self::assertSame('7', $byName['Sales Module']['reviewer_id']);
        self::assertSame($out['groups'][0]['id'], $byName['Sales Module']['group_id']);
        self::assertSame($out['groups'][1]['id'], $byName['Customer List']['group_id']);

        // Statuses beyond the first two are stored.
        self::assertIsString($byName['Customer List']['id']);
        self::assertSame('stuck', $this->tasks->update($this->manager, $byName['Customer List']['id'], ['status' => 'stuck'])['status']);
    }

    public function test_deleting_a_group_ungroups_its_tasks_and_an_empty_project_with_groups_can_be_deleted(): void
    {
        $project = $this->setup->createProject($this->manager, ['name' => 'Audit']);
        self::assertIsString($project['id']);
        $group = $this->service->createGroup($project['id'], ['name' => 'Fieldwork']);
        self::assertIsString($group['id']);
        $task = $this->tasks->create($this->manager, ['name' => 'Count stock', 'project_id' => $project['id'], 'group_id' => $group['id']]);
        self::assertIsString($task['id']);

        $this->service->deleteGroup($group['id']);
        self::assertNull($this->tasks->get($this->manager, $task['id'])['group_id']);

        $this->tasks->delete($this->manager, $task['id']);
        $this->tasks->delete($this->manager, $task['id'], true);
        $this->service->createGroup($project['id'], ['name' => 'Another']);
        $this->setup->deleteProject($this->manager, $project['id']);
        self::assertSame(['deleted' => true, 'permanent' => true], $this->setup->deleteProject($this->manager, $project['id'], true));
        self::assertSame([], $this->board->groupsFor($project['id']));
    }

    public function test_a_group_round_trips_its_time_to_the_microsecond(): void
    {
        $project = $this->setup->createProject($this->manager, ['name' => 'Close']);
        self::assertIsString($project['id']);
        $this->service->createGroup($project['id'], ['name' => 'Week 1', 'color' => '#10B981']);

        $groups = $this->board->groupsFor($project['id']);
        self::assertCount(1, $groups);
        self::assertSame('#10B981', $groups[0]->color);
        self::assertEquals(new DateTimeImmutable('2026-10-13 01:02:03.456789', new DateTimeZone('UTC')), $groups[0]->createdDate);
    }
}
