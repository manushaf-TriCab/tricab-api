<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskBoardStore;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskMember;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Service\TaskBoardService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * The review step, priority, task groups and project templates (13 Oct 2026, items 5 and 9 of TRI-TASK-UX-PLAN.md),
 * on the in-memory stores.
 */
final class TaskBoardServiceTest extends TestCase
{
    private InMemoryTaskStore $store;

    private InMemoryTaskBoardStore $boardStore;

    private TaskService $tasks;

    private TaskSetupService $setup;

    private TaskBoardService $board;

    private FixedClock $clock;

    /** A team member: their own tasks only. */
    private TaskActor $una;

    /** A team member who reviews. */
    private TaskActor $rae;

    /** A manager: every task. */
    private TaskActor $max;

    protected function setUp(): void
    {
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(11, 'Una User', 'una@example.test');
        $directory->add(12, 'Max Manager', 'max@example.test', TaskMember::ROLE_MANAGER);
        $directory->add(13, 'Rae Reviewer', 'rae@example.test');
        $directory->add(14, 'Ada Admin', 'ada@example.test', TaskMember::ROLE_ADMIN);
        $directory->add(15, 'Lin Manager', 'lin@example.test', TaskMember::ROLE_MANAGER);
        $this->store = new InMemoryTaskStore();
        $this->boardStore = new InMemoryTaskBoardStore();
        $this->clock = FixedClock::utc('2026-10-13 01:00:00');
        $time = new TaskTime($this->clock, 'Australia/Melbourne');
        $this->tasks = new TaskService($this->store, $this->store, $directory, $time, $this->boardStore);
        $this->setup = new TaskSetupService($this->store, $directory, $time);
        $this->board = new TaskBoardService($this->boardStore, $this->store, $this->store, $this->tasks, $this->setup, $directory, $time);

        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->rae = new TaskActor(13, 'Rae Reviewer', 'rae@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [
            ...$base, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE, Module::PERMISSION_TASK_SETUP_MANAGE,
        ]);
    }

    private static function id(mixed $row): string
    {
        self::assertIsArray($row);
        self::assertIsString($row['id']);

        return $row['id'];
    }

    private function project(string $name = 'Odoo Project'): string
    {
        return self::id($this->setup->createProject($this->max, ['name' => $name]));
    }

    // ==== statuses and the review step (item 5) ================================================

    public function test_a_new_task_starts_active_and_any_of_the_three_statuses_can_be_set(): void
    {
        $task = $this->tasks->create($this->max, ['name' => 'Sales module']);
        self::assertSame('active', $task['status']);
        foreach (['on_hold', 'completed', 'active'] as $status) {
            self::assertSame($status, $this->tasks->update($this->max, self::id($task), ['status' => $status])['status']);
        }
    }

    public function test_only_the_reviewer_or_a_manager_marks_a_reviewed_task_done(): void
    {
        $id = self::id($this->tasks->create($this->max, ['name' => 'Bank rec', 'preparer_id' => '11', 'reviewer_id' => '13']));

        try {
            $this->tasks->update($this->una, $id, ['status' => 'completed']);
            self::fail('The preparer should not be able to mark it done.');
        } catch (TaskForbidden $e) {
            self::assertSame('Only the reviewer can mark this task done. Use Send for review instead.', $e->getMessage());
        }
        self::assertSame('on_hold', $this->tasks->update($this->una, $id, ['status' => 'on_hold'])['status']);
        self::assertSame('completed', $this->tasks->update($this->rae, $id, ['status' => 'completed'])['status']);

        // A manager may close any task, and with no reviewer the preparer closes it.
        $other = self::id($this->tasks->create($this->max, ['name' => 'Accruals', 'preparer_id' => '11', 'reviewer_id' => '13']));
        self::assertSame('completed', $this->tasks->update($this->max, $other, ['status' => 'completed'])['status']);
        $solo = self::id($this->tasks->create($this->una, ['name' => 'Filing', 'preparer_id' => '11']));
        self::assertSame('completed', $this->tasks->update($this->una, $solo, ['status' => 'completed'])['status']);
    }

    public function test_the_preparer_cannot_change_or_drop_the_reviewer_and_close_the_task_in_one_save(): void
    {
        $id = self::id($this->tasks->create($this->max, ['name' => 'Bank rec', 'preparer_id' => '11', 'reviewer_id' => '13']));

        foreach ([['reviewer_id' => '12'], ['reviewer_id' => '11'], ['reviewer_id' => null]] as $change) {
            try {
                $this->tasks->update($this->una, $id, ['status' => 'completed'] + $change);
                self::fail('Expected a refusal for ' . json_encode($change, JSON_THROW_ON_ERROR));
            } catch (TaskForbidden) {
            }
        }
        self::assertSame('active', $this->tasks->get($this->max, $id)['status']);
    }

    public function test_a_member_cannot_create_a_task_already_done_for_someone_else_to_review(): void
    {
        $this->expectException(TaskForbidden::class);
        $this->tasks->create($this->una, ['name' => 'Bank rec', 'preparer_id' => '11', 'reviewer_id' => '13', 'status' => 'completed']);
    }

    public function test_an_unfinished_dependency_blocks_only_done(): void
    {
        $gate = self::id($this->tasks->create($this->max, ['name' => 'Gather statements']));
        $id = self::id($this->tasks->create($this->max, ['name' => 'Reconcile', 'dependencies' => [$gate]]));

        self::assertSame('on_hold', $this->tasks->update($this->max, $id, ['status' => 'on_hold'])['status']);
        self::assertSame('active', $this->tasks->update($this->max, $id, ['status' => 'active'])['status']);
        try {
            $this->tasks->update($this->max, $id, ['status' => 'completed']);
            self::fail('Done should be blocked.');
        } catch (TaskConflict $e) {
            self::assertStringContainsString('Gather statements', $e->getMessage());
        }
    }

    public function test_overdue_is_shown_only_for_an_active_task_not_on_hold_or_done(): void
    {
        $late = ['name' => 'Late', 'due_date' => '2026-10-01'];
        self::assertSame('overdue', $this->tasks->create($this->max, $late + ['status' => 'active'])['display_status']);
        self::assertSame('on_hold', $this->tasks->create($this->max, $late + ['status' => 'on_hold'])['display_status']);
        self::assertSame('completed', $this->tasks->create($this->max, $late + ['status' => 'completed'])['display_status']);
        self::assertSame('active', $this->tasks->create($this->max, ['name' => 'Fine'])['display_status']);
    }

    // ==== priority =============================================================================

    public function test_priority_is_set_cleared_and_checked(): void
    {
        $task = $this->tasks->create($this->max, ['name' => 'Sales module', 'priority' => 'critical']);
        self::assertSame('critical', $task['priority']);
        self::assertNull($this->tasks->update($this->max, self::id($task), ['priority' => ''])['priority']);
        self::assertNull($this->tasks->create($this->max, ['name' => 'No priority'])['priority']);

        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('Priority must be critical, high, medium or low');
        $this->tasks->update($this->max, self::id($task), ['priority' => 'urgent']);
    }

    // ==== groups (item 9) ======================================================================

    public function test_groups_are_listed_in_order_renamed_reordered_and_hold_only_their_projects_tasks(): void
    {
        $project = $this->project();
        $studying = $this->board->createGroup($project, ['name' => 'Odoo Studying', 'color' => '#a855f7']);
        $implementation = $this->board->createGroup($project, ['name' => 'Odoo Implementation']);
        self::assertSame([0, 1], array_column($this->board->groups($project), 'sort_order'));

        $this->board->updateGroup(self::id($implementation), ['sort_order' => -1, 'name' => 'Implementation']);
        self::assertSame(['Implementation', 'Odoo Studying'], array_column($this->board->groups($project), 'name'));

        $task = $this->tasks->create($this->max, ['name' => 'Sales module', 'project_id' => $project, 'group_id' => self::id($studying)]);
        self::assertSame(self::id($studying), $task['group_id']);

        $elsewhere = $this->project('Audit');
        try {
            $this->tasks->create($this->max, ['name' => 'Wrong board', 'project_id' => $elsewhere, 'group_id' => self::id($studying)]);
            self::fail('A group of another project should be refused.');
        } catch (TaskInvalid $e) {
            self::assertSame('That group belongs to another project', $e->getMessage());
        }

        // Moving the task to another project leaves its group behind.
        self::assertNull($this->tasks->update($this->max, self::id($task), ['project_id' => $elsewhere])['group_id']);
    }

    public function test_deleting_a_group_keeps_its_tasks_in_the_project_ungrouped(): void
    {
        $project = $this->project();
        $group = self::id($this->board->createGroup($project, ['name' => 'Odoo Studying']));
        $task = self::id($this->tasks->create($this->max, ['name' => 'Sales module', 'project_id' => $project, 'group_id' => $group]));

        self::assertSame(['deleted' => true], $this->board->deleteGroup($group));

        $after = $this->tasks->get($this->max, $task);
        self::assertNull($after['group_id']);
        self::assertSame($project, $after['project_id']);
        self::assertSame([], $this->board->groups($project));
        $this->expectException(TaskNotFound::class);
        $this->board->updateGroup($group, ['name' => 'Gone']);
    }

    public function test_dragging_orders_a_groups_tasks_and_moves_a_task_to_another_group(): void
    {
        $project = $this->project();
        $ar = self::id($this->board->createGroup($project, ['name' => 'AR']));
        $ap = self::id($this->board->createGroup($project, ['name' => 'AP']));
        $one = self::id($this->tasks->create($this->max, ['name' => 'One', 'project_id' => $project, 'group_id' => $ar]));
        $two = self::id($this->tasks->create($this->max, ['name' => 'Two', 'project_id' => $project, 'group_id' => $ar]));
        $three = self::id($this->tasks->create($this->max, ['name' => 'Three', 'project_id' => $project, 'group_id' => $ap]));
        self::assertNull($this->tasks->get($this->max, $one)['sort_order']);
        $updatedBefore = $this->tasks->get($this->max, $one)['updated_date'];

        // Two above One in AR; Three dragged from AP to the bottom of AR; AP left empty.
        $this->clock->advance('+1 second');
        $result = $this->board->orderTasks($project, ['groups' => [
            ['group_id' => $ar, 'task_ids' => [$two, $one, $three]],
            ['group_id' => $ap, 'task_ids' => []],
        ]], $this->max);
        self::assertSame(['updated' => 3], $result);
        self::assertSame([0, $ar], [$this->tasks->get($this->max, $two)['sort_order'], $this->tasks->get($this->max, $two)['group_id']]);
        self::assertSame(1, $this->tasks->get($this->max, $one)['sort_order']);
        self::assertSame([2, $ar], [$this->tasks->get($this->max, $three)['sort_order'], $this->tasks->get($this->max, $three)['group_id']]);
        // A new place is the board's layout, not a change to the task; a new group is (Nick Han, 21 Oct 2026).
        self::assertSame($updatedBefore, $this->tasks->get($this->max, $one)['updated_date']);
        self::assertCount(1, $this->tasks->history($this->max, $one), 'only the creation');
        $moved = $this->tasks->history($this->max, $three)[0];
        self::assertSame(['group', 'AP', 'AR', 'Max Manager'], [$moved['field'], $moved['from_value'], $moved['to_value'], $moved['changed_by']]);

        // The same order again changes nothing.
        self::assertSame(['updated' => 0], $this->board->orderTasks($project, ['groups' => [['group_id' => $ar, 'task_ids' => [$two, $one, $three]]]]));

        // A task that changes group in its form goes to the end of the new one, and the change is logged as a drag's is.
        $this->clock->advance('+1 second');
        self::assertNull($this->tasks->update($this->max, $three, ['group_id' => $ap])['sort_order']);
        $moved = $this->tasks->history($this->max, $three)[0];
        self::assertSame(['group', 'AR', 'AP'], [$moved['field'], $moved['from_value'], $moved['to_value']]);
    }

    public function test_ordering_refuses_another_projects_task_or_group_and_a_task_sent_twice(): void
    {
        $project = $this->project();
        $group = self::id($this->board->createGroup($project, ['name' => 'AR']));
        $mine = self::id($this->tasks->create($this->max, ['name' => 'Mine', 'project_id' => $project, 'group_id' => $group]));
        $elsewhere = $this->project('Audit');
        $theirGroup = self::id($this->board->createGroup($elsewhere, ['name' => 'Other']));
        $theirs = self::id($this->tasks->create($this->max, ['name' => 'Theirs', 'project_id' => $elsewhere]));

        $cases = [
            'Some of these tasks are not on this board. Refresh the page and try again.' => [['group_id' => $group, 'task_ids' => [$mine, $theirs]]],
            'That group belongs to another project' => [['group_id' => $theirGroup, 'task_ids' => [$mine]]],
            'A task is sent twice, or its id is not text' => [['group_id' => $group, 'task_ids' => [$mine, $mine]]],
            'A group is sent twice' => [['group_id' => $group, 'task_ids' => [$mine]], ['group_id' => $group, 'task_ids' => []]],
        ];
        foreach ($cases as $message => $groups) {
            try {
                $this->board->orderTasks($project, ['groups' => $groups]);
                self::fail('Expected: ' . $message);
            } catch (TaskInvalid $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        self::assertNull($this->tasks->get($this->max, $mine)['sort_order']);

        // The ungrouped tasks are a group of their own (null).
        self::assertSame(['updated' => 1], $this->board->orderTasks($project, ['groups' => [['group_id' => null, 'task_ids' => [$mine]]]]));
        self::assertNull($this->tasks->get($this->max, $mine)['group_id']);
    }

    // ==== project templates (item 9) ===========================================================

    /** @return array<string, mixed> */
    private function odooTemplate(): array
    {
        return $this->board->createProjectTemplate($this->max, [
            'name' => 'Odoo rollout',
            'description' => 'Study, then implement',
            'groups' => [
                ['name' => 'Odoo Studying', 'color' => '#a855f7', 'tasks' => [
                    ['name' => '1. Getting Started Module', 'priority' => 'critical', 'preparer_id' => '11', 'due_offset_days' => 7],
                    ['name' => '2. Accounting and Finance Module', 'priority' => 'critical', 'preparer_id' => '11', 'reviewer_id' => '13', 'due_offset_days' => '14'],
                ]],
                ['name' => 'Odoo Implementation', 'tasks' => [
                    ['name' => 'Customer List', 'priority' => 'medium', 'preparer_id' => '12'],
                ]],
            ],
        ]);
    }

    public function test_a_project_template_is_saved_listed_renamed_and_its_name_is_unique(): void
    {
        $template = $this->odooTemplate();
        self::assertSame(3, $template['task_count']);
        self::assertSame(['Odoo Studying', 'Odoo Implementation'], array_column((array) $template['groups'], 'name'));
        self::assertSame('12', $template['created_by_id']);
        self::assertSame(['Odoo rollout'], array_column($this->board->projectTemplates(), 'name'));

        $renamed = $this->board->updateProjectTemplate(self::id($template), ['name' => 'Odoo rollout v2', 'groups' => []]);
        self::assertSame(0, $renamed['task_count']);

        $this->board->createProjectTemplate($this->max, ['name' => 'Month-end close']);
        $this->expectException(TaskConflict::class);
        $this->board->updateProjectTemplate(self::id($template), ['name' => 'month-end CLOSE']);
    }

    public function test_a_bad_template_is_refused_with_where_the_problem_is(): void
    {
        $cases = [
            'bad priority' => [[['name' => 'G', 'tasks' => [['name' => 'T', 'priority' => 'urgent']]]], 'Priority must be critical, high, medium or low ("T" in "G")'],
            'not a member' => [[['name' => 'G', 'tasks' => [['name' => 'T', 'preparer_id' => '99']]]], 'The preparer and reviewer must be members of Tri-Task ("T" in "G")'],
            'bad offset' => [[['name' => 'G', 'tasks' => [['name' => 'T', 'due_offset_days' => 'soon']]]], 'Due days must be a whole number of days after the start ("T" in "G")'],
            'no group name' => [[['tasks' => []]], 'Name is required'],
            'not a list' => [['G' => []], 'Groups must be a list'],
        ];
        foreach ($cases as $why => [$groups, $message]) {
            try {
                $this->board->createProjectTemplate($this->max, ['name' => 'T ' . $why, 'groups' => $groups]);
                self::fail($why . ' should be refused.');
            } catch (TaskInvalid $e) {
                self::assertSame($message, $e->getMessage(), $why);
            }
        }
        self::assertSame([], $this->board->projectTemplates());
    }

    public function test_using_a_template_makes_the_project_its_groups_and_its_tasks_with_due_dates_from_the_start(): void
    {
        $template = $this->odooTemplate();

        $out = $this->board->applyProjectTemplate($this->max, self::id($template), ['name' => 'Odoo Project', 'start_date' => '2026-11-02']);

        self::assertSame(3, $out['tasks_created']);
        self::assertSame('Odoo Project', $out['project']['name']);
        self::assertSame('active', $out['project']['status']);
        self::assertSame(3, $out['project']['task_count']);
        $projectId = self::id($out['project']);
        $groups = $this->board->groups($projectId);
        self::assertSame(['Odoo Studying', 'Odoo Implementation'], array_column($groups, 'name'));
        self::assertSame('#a855f7', $groups[0]['color']);

        $made = [];
        foreach ($this->tasks->list($this->max) as $task) {
            self::assertIsString($task['name']);
            $made[$task['name']] = $task;
        }
        $first = $made['1. Getting Started Module'];
        self::assertSame($projectId, $first['project_id']);
        self::assertSame($groups[0]['id'], $first['group_id']);
        self::assertSame('2026-11-09', $first['due_date']);
        self::assertSame('critical', $first['priority']);
        self::assertSame('active', $first['status']);
        self::assertSame('11', $first['preparer_id']);
        // No reviewer named: the default reviewer, the first Manager by name who is not the preparer.
        self::assertSame('15', $first['reviewer_id']);
        self::assertSame('13', $made['2. Accounting and Finance Module']['reviewer_id']);
        self::assertSame('2026-11-16', $made['2. Accounting and Finance Module']['due_date']);
        self::assertSame('', $made['Customer List']['due_date']);
        // Max prepares it, so the other Manager reviews.
        self::assertSame('15', $made['Customer List']['reviewer_id']);
        self::assertSame($groups[1]['id'], $made['Customer List']['group_id']);
    }

    public function test_a_template_cannot_make_a_project_whose_name_is_taken(): void
    {
        $template = $this->odooTemplate();
        $this->project('Odoo Project');

        try {
            $this->board->applyProjectTemplate($this->max, self::id($template), ['name' => 'Odoo Project']);
            self::fail('A taken project name should be refused.');
        } catch (TaskConflict) {
        }
        self::assertCount(1, $this->setup->listProjects($this->max));
        self::assertSame([], $this->tasks->list($this->max));
    }

    public function test_a_project_is_saved_as_a_template_with_due_dates_as_days_from_its_first(): void
    {
        $project = $this->project();
        $group = self::id($this->board->createGroup($project, ['name' => 'Odoo Studying']));
        $this->tasks->create($this->max, ['name' => 'Sales module', 'project_id' => $project, 'group_id' => $group, 'due_date' => '2026-11-05', 'priority' => 'high', 'preparer_id' => '11']);
        $this->clock->advance('+1 second');
        $this->tasks->create($this->max, ['name' => 'Getting started', 'project_id' => $project, 'group_id' => $group, 'due_date' => '2026-11-02']);
        $this->clock->advance('+1 second');
        $this->tasks->create($this->max, ['name' => 'Loose end', 'project_id' => $project]);
        $this->tasks->create($this->max, ['name' => 'Another project', 'project_id' => $this->project('Audit')]);

        $template = $this->board->saveProjectAsTemplate($this->max, $project, ['name' => 'Odoo rollout']);

        self::assertSame('Odoo rollout', $template['name']);
        $groups = (array) $template['groups'];
        self::assertSame(['Odoo Studying', TaskBoardService::UNGROUPED], array_column($groups, 'name'));
        self::assertIsArray($groups[0]);
        $tasks = (array) $groups[0]['tasks'];
        self::assertSame(['Sales module', 'Getting started'], array_column($tasks, 'name'));
        self::assertSame([3, 0], array_column($tasks, 'due_offset_days'));
        self::assertSame(['high', null], array_column($tasks, 'priority'));
        self::assertIsArray($groups[1]);
        self::assertSame(['Loose end'], array_column((array) $groups[1]['tasks'], 'name'));
    }

    public function test_the_default_reviewer_prefers_a_manager_then_an_administrator(): void
    {
        self::assertSame(15, $this->board->defaultReviewer(11));   // Lin Manager before Max Manager by name
        self::assertSame(12, $this->board->defaultReviewer(15));
        self::assertSame(15, $this->board->defaultReviewer(null));
    }

    public function test_a_project_template_carries_several_people_and_older_templates_still_apply(): void
    {
        $template = $this->board->createProjectTemplate($this->max, ['name' => 'Close', 'groups' => [['name' => 'G', 'tasks' => [
            ['name' => 'Two and two', 'preparer_ids' => ['11', '13'], 'reviewer_ids' => ['12', '15']],
            ['name' => 'Old shape', 'preparer_id' => '11', 'reviewer_id' => '12'],
            ['name' => 'Default reviewer', 'preparer_ids' => ['12']],
        ]]]]);
        $stored = ((array) ((array) ((array) $template['groups'])[0])['tasks']);
        self::assertSame(['11', '13'], ((array) $stored[0])['preparer_ids']);
        self::assertSame('11', ((array) $stored[0])['preparer_id']);
        self::assertSame(['12'], ((array) $stored[1])['reviewer_ids']);

        $out = $this->board->applyProjectTemplate($this->max, self::id($template), ['name' => 'November close']);

        self::assertSame(3, $out['tasks_created']);
        $byName = [];
        foreach ($this->tasks->list($this->max) as $task) {
            $byName[Typed::string($task['name'])] = $task;
        }
        self::assertSame([['11', '13'], ['12', '15']], [$byName['Two and two']['preparer_ids'], $byName['Two and two']['reviewer_ids']]);
        self::assertSame([['11'], ['12']], [$byName['Old shape']['preparer_ids'], $byName['Old shape']['reviewer_ids']]);
        // The default reviewer is a manager who is not one of the preparers.
        self::assertSame(['15'], $byName['Default reviewer']['reviewer_ids']);

        $saved = $this->board->saveProjectAsTemplate($this->max, Typed::string(((array) $out['project'])['id']), ['name' => 'Again']);
        $again = ((array) ((array) $saved['groups'])[0]);
        $savedTasks = [];
        foreach ((array) $again['tasks'] as $item) {
            $savedTasks[Typed::string(((array) $item)['name'])] = (array) $item;
        }
        self::assertSame([['11', '13'], ['12', '15']], [$savedTasks['Two and two']['preparer_ids'], $savedTasks['Two and two']['reviewer_ids']]);
    }
}
