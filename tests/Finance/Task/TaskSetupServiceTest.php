<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskSetupStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Domain\TaskRecord;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** The four reference lists and their rules, on the in-memory twin (T2.3). */
final class TaskSetupServiceTest extends TestCase
{
    private InMemoryFinanceTaskMemberDirectory $directory;

    private TaskSetupService $service;

    private TaskActor $manager;

    /** @var list<TaskRecord> the tasks the store pretends exist, for the "still in use" rules */
    private array $tasks = [];

    protected function setUp(): void
    {
        $this->directory = new InMemoryFinanceTaskMemberDirectory();
        $this->tasks = [];
        $tasks = &$this->tasks;
        $store = new class ($tasks) extends InMemoryTaskSetupStore {
            /** @param list<TaskRecord> $rows */
            public function __construct(private array &$rows)
            {
            }

            protected function taskRows(): array
            {
                return $this->rows;
            }

            protected function forgetTasks(array $ids): void
            {
                $this->rows = array_values(array_filter($this->rows, static fn (TaskRecord $t): bool => !in_array($t->id, $ids, true)));
            }
        };
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:00:00'), 'Australia/Melbourne');
        $this->service = new TaskSetupService($store, $this->directory, $time);
        $this->manager = new TaskActor(7, 'Mia Manager', 'mia@example.test', [Module::PERMISSION_TASK_SETUP_MANAGE, Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_READ_ALL]);
    }

    private function task(string $id, ?string $project = null, ?string $entity = null, string $area = '', bool $binned = false): TaskRecord
    {
        $now = new DateTimeImmutable('2026-10-07 01:00:00', new DateTimeZone('UTC'));

        return $this->tasks[] = new TaskRecord(
            $id, 'T ' . $id, '', '', '', 'routine', $area, $entity, $project, null, null, 'in_progress', 0, null, false, false,
            null, null, $binned ? $now : null, null, $now, $now,
        );
    }

    /** @return array<string, mixed> */
    private function project(string $name = 'Month end'): array
    {
        return $this->service->createProject($this->manager, ['name' => $name]);
    }

    private static function id(mixed $row): string
    {
        self::assertIsArray($row);
        self::assertIsString($row['id']);

        return $row['id'];
    }

    // ==== projects =============================================================================

    public function test_planning_is_gone_and_a_page_still_sending_it_gets_active(): void
    {
        $p = $this->service->createProject($this->manager, ['name' => 'Year end', 'status' => 'planning']);

        self::assertSame('active', $p['status']);
    }

    public function test_a_project_is_created_with_the_sources_defaults_and_stripped_text(): void
    {
        $p = $this->service->createProject($this->manager, ['name' => "  Month end \u{00A0}", 'description' => ' notes ']);

        self::assertSame('Month end', $p['name']);
        self::assertSame('notes', $p['description']);
        self::assertSame('active', $p['status']);
        self::assertSame('', $p['target_date']);
        self::assertNull($p['group_id']);
        self::assertSame(0, $p['task_count']);
        self::assertFalse($p['is_deleted']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', self::id($p));
        self::assertSame('2026-10-07T01:00:00.000000+00:00', $p['created_date']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badProjects(): iterable
    {
        yield 'no name' => [[], 'Name is required'];
        yield 'blank name' => [['name' => '   '], 'Name is required'];
        yield 'null name' => [['name' => null], 'Name is required'];
        yield 'long name' => [['name' => str_repeat('é', 201)], 'Name must be at most 200 characters'];
        yield 'long colour' => [['name' => 'x', 'color' => str_repeat('a', 21)], 'Color must be at most 20 characters'];
        yield 'bad status' => [['name' => 'x', 'status' => 'overdue'], 'Status must be active, completed or on_hold'];
        yield 'bad date format' => [['name' => 'x', 'target_date' => '07/10/2026'], 'Dates must be yyyy-MM-dd'];
        yield 'not a real date' => [['name' => 'x', 'target_date' => '2026-02-30'], 'That is not a real date'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badProjects')]
    public function test_a_bad_project_is_refused_with_the_sources_message(array $data, string $message): void
    {
        try {
            $this->service->createProject($this->manager, $data);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame(422, $e->httpStatus());
        }
    }

    public function test_a_real_date_and_a_leap_day_are_accepted_and_an_empty_one_clears(): void
    {
        $p = $this->service->createProject($this->manager, ['name' => 'a', 'target_date' => '2028-02-29']);
        self::assertSame('2028-02-29', $p['target_date']);
        $p = $this->service->updateProject($this->manager, self::id($p), ['target_date' => '']);
        self::assertSame('', $p['target_date']);
    }

    public function test_a_live_project_name_is_unique_case_insensitively_on_create_and_rename(): void
    {
        $this->project('Close');
        $other = $this->project('Audit');

        try {
            $this->project('CLOSE');
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('A project with that name already exists', $e->getMessage());
            self::assertSame(409, $e->httpStatus());
        }
        $this->expectException(TaskConflict::class);
        $this->service->updateProject($this->manager, self::id($other), ['name' => 'close']);
    }

    public function test_renaming_a_project_to_its_own_name_is_not_a_clash(): void
    {
        $p = $this->project('Close');

        $renamed = $this->service->updateProject($this->manager, self::id($p), ['name' => 'CLOSE', 'status' => 'on_hold']);

        self::assertSame('CLOSE', $renamed['name']);
        self::assertSame('on_hold', $renamed['status']);
    }

    public function test_projects_list_by_name_and_the_bin_separately(): void
    {
        $b = $this->project('beta');
        $this->project('Alpha');
        $this->project('Gamma');
        $this->service->deleteProject($this->manager, self::id($b));

        self::assertSame(['Alpha', 'Gamma'], array_column($this->service->listProjects($this->manager), 'name'));
        $bin = $this->service->listProjects($this->manager, true);
        self::assertSame(['beta'], array_column($bin, 'name'));
        self::assertTrue($bin[0]['is_deleted']);
        self::assertSame('2026-10-07T01:00:00.000000+00:00', $bin[0]['deleted_at']);
    }

    public function test_a_binned_project_cannot_be_edited_and_does_not_block_its_name(): void
    {
        $p = $this->project('Close');
        $this->service->deleteProject($this->manager, self::id($p));

        $again = $this->project('close');
        self::assertNotSame($p['id'], $again['id']);
        try {
            $this->service->updateProject($this->manager, self::id($p), ['name' => 'x']);
            self::fail('Expected not found.');
        } catch (TaskNotFound $e) {
            self::assertSame('Project not found', $e->getMessage());
        }
    }

    public function test_restoring_into_a_live_name_is_refused_and_otherwise_works(): void
    {
        $p = $this->project('Close');
        $this->service->deleteProject($this->manager, self::id($p));
        $this->project('close');

        try {
            $this->service->restoreProject($this->manager, self::id($p));
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('A live project already has that name; rename one first', $e->getMessage());
        }

        $q = $this->project('Other');
        $this->service->deleteProject($this->manager, self::id($q));
        $restored = $this->service->restoreProject($this->manager, self::id($q));
        self::assertFalse($restored['is_deleted']);
        self::assertNull($restored['deleted_at']);
    }

    public function test_delete_is_soft_idempotent_and_permanent_only_from_the_bin(): void
    {
        $p = $this->project('Close');
        $id = self::id($p);

        try {
            $this->service->deleteProject($this->manager, $id, true);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('Move the project to the Bin before deleting it permanently', $e->getMessage());
        }
        self::assertSame(['deleted' => true, 'permanent' => false], $this->service->deleteProject($this->manager, $id));
        self::assertSame(['deleted' => true, 'permanent' => false], $this->service->deleteProject($this->manager, $id));
        self::assertSame(['deleted' => true, 'permanent' => true], $this->service->deleteProject($this->manager, $id, true));
        $this->expectException(TaskNotFound::class);
        $this->service->deleteProject($this->manager, $id);
    }

    public function test_a_project_with_a_live_task_cannot_be_deleted_for_good_and_nothing_is_removed(): void
    {
        $id = self::id($this->project('Close'));
        $this->service->deleteProject($this->manager, $id);
        // A task restored on its own after the cascade, plus one still in the Bin.
        $live = $this->task('live', $id);
        $this->task('binned', $id, null, '', true);

        try {
            $this->service->deleteProject($this->manager, $id, true);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('1 task(s) still belong to this project', $e->getMessage());
            self::assertSame(['count' => 1], $e->extra);
        }
        self::assertCount(2, $this->tasks);
        self::assertSame($live, $this->tasks[0]);
    }

    public function test_binning_a_project_bins_its_live_tasks_with_the_same_stamp_and_leaves_the_rest(): void
    {
        $id = self::id($this->project('Close'));
        $other = self::id($this->project('Other'));
        $a = $this->task('a', $id);
        $b = $this->task('b', $id);
        $early = $this->task('early', $id, null, '', true);
        $early->deletedAt = new DateTimeImmutable('2026-09-01 00:00:00', new DateTimeZone('UTC'));
        $early->deletedBy = 3;
        $elsewhere = $this->task('else', $other);
        $none = $this->task('none');

        $this->service->deleteProject($this->manager, $id);

        $project = $this->service->listProjects($this->manager, true)[0];
        self::assertSame(2, $project['binned_task_count']);
        foreach ([$a, $b] as $task) {
            self::assertNotNull($task->deletedAt);
            self::assertSame(7, $task->deletedBy);
            self::assertSame('2026-10-07 01:00:00', $task->deletedAt->format('Y-m-d H:i:s'));
        }
        self::assertSame($a->deletedAt?->format('c'), $b->deletedAt?->format('c'));
        self::assertSame('2026-09-01', $early->deletedAt->format('Y-m-d'));
        self::assertSame(3, $early->deletedBy);
        self::assertNull($elsewhere->deletedAt);
        self::assertNull($none->deletedAt);
        // It has left the live list, so only the other project (and its one task) is counted there.
        self::assertSame([['Other', 1]], array_map(static fn (array $p): array => [$p['name'], $p['task_count']], $this->service->listProjects($this->manager)));
    }

    public function test_restoring_a_project_brings_back_only_the_tasks_that_went_with_it(): void
    {
        $id = self::id($this->project('Close'));
        $a = $this->task('a', $id);
        $early = $this->task('early', $id, null, '', true);
        $early->deletedAt = new DateTimeImmutable('2026-09-01 00:00:00', new DateTimeZone('UTC'));
        $early->deletedBy = 3;
        $this->service->deleteProject($this->manager, $id);

        $restored = $this->service->restoreProject($this->manager, $id);

        self::assertFalse($restored['is_deleted']);
        self::assertSame(1, $restored['task_count']);
        self::assertSame(0, $restored['binned_task_count']);
        self::assertNull($a->deletedAt);
        self::assertNull($a->deletedBy);
        self::assertNotNull($early->deletedAt);
        self::assertSame(3, $early->deletedBy);
    }

    public function test_a_refused_restore_leaves_the_tasks_in_the_bin(): void
    {
        $id = self::id($this->project('Close'));
        $a = $this->task('a', $id);
        $this->service->deleteProject($this->manager, $id);
        $this->project('Close');

        try {
            $this->service->restoreProject($this->manager, $id);
            self::fail('Expected a conflict.');
        } catch (TaskConflict) {
            self::assertNotNull($a->deletedAt);
        }
    }

    public function test_permanently_deleting_a_binned_project_deletes_its_binned_tasks_only(): void
    {
        $id = self::id($this->project('Close'));
        $other = self::id($this->project('Other'));
        $this->task('a', $id);
        $this->task('early', $id, null, '', true);
        $this->task('else', $other);
        $this->service->deleteProject($this->manager, $id);

        self::assertSame(['deleted' => true, 'permanent' => true], $this->service->deleteProject($this->manager, $id, true));

        self::assertSame(['else'], array_map(static fn (TaskRecord $t): string => $t->id, $this->tasks));
    }

    public function test_binning_is_all_or_nothing(): void
    {
        $store = new class extends InMemoryTaskSetupStore {
            public function binProjectTasks(string $projectId, DateTimeImmutable $at, ?int $by): void
            {
                throw new RuntimeException('The database went away');
            }
        };
        $service = new TaskSetupService($store, $this->directory, new TaskTime(FixedClock::utc('2026-10-07 01:00:00'), 'Australia/Melbourne'));
        $id = self::id($service->createProject($this->manager, ['name' => 'Close']));

        try {
            $service->deleteProject($this->manager, $id);
            self::fail('Expected a failure.');
        } catch (RuntimeException) {
            self::assertNull($store->findProject($id)?->deletedAt);
        }
    }

    public function test_project_counts_cover_live_tasks_only_and_a_caller_sees_only_their_own(): void
    {
        $id = self::id($this->project('Close'));
        $own = $this->task('own', $id);
        $own->setPreparers([11]);
        $this->task('other', $id);
        $this->task('binned', $id, null, '', true);
        $done = $this->task('done', $id);
        $done->status = 'completed';
        $done->setReviewers([11]);

        $all = $this->service->listProjects($this->manager)[0];
        self::assertSame(3, $all['task_count']);
        self::assertSame(1, $all['completed_count']);

        $mine = $this->service->listProjects(new TaskActor(11, 'Una', 'una@example.test', [Module::PERMISSION_TASK_TASKS_READ]))[0];
        self::assertSame(2, $mine['task_count'], 'a caller without READ_ALL counts the tasks they prepare or review');
        self::assertSame(1, $mine['completed_count']);
    }

    // ==== project groups (Nick Han, 19 Oct 2026) ===============================================

    public function test_a_project_group_is_created_last_and_listed_by_sort_order_then_id(): void
    {
        $a = $this->service->createProjectGroup(['name' => '  Odoo  ', 'color' => '#6366F1']);
        $b = $this->service->createProjectGroup(['name' => 'Audit', 'color' => '']);

        self::assertSame(['id', 'name', 'color', 'sort_order', 'created_date', 'updated_date'], array_keys($a));
        self::assertSame('Odoo', $a['name']);
        self::assertSame('#6366F1', $a['color']);
        self::assertSame(0, $a['sort_order']);
        self::assertNull($b['color'], 'an empty colour is none');
        self::assertSame(1, $b['sort_order']);
        self::assertSame('2026-10-07T01:00:00.000000+00:00', $a['created_date']);

        $this->service->updateProjectGroup(self::id($a), ['sort_order' => '5']);
        self::assertSame(['Audit', 'Odoo'], array_column($this->service->listProjectGroups(), 'name'));
        self::assertSame(6, $this->service->createProjectGroup(['name' => 'Tax'])['sort_order'], 'placed after the highest');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badProjectGroups(): iterable
    {
        yield 'no name' => [[], 'Name is required'];
        yield 'blank name' => [['name' => '  '], 'Name is required'];
        yield 'long name' => [['name' => str_repeat('a', 201)], 'Name must be at most 200 characters'];
        yield 'long colour' => [['name' => 'x', 'color' => str_repeat('a', 21)], 'Color must be at most 20 characters'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badProjectGroups')]
    public function test_a_bad_project_group_is_refused(array $data, string $message): void
    {
        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage($message);
        $this->service->createProjectGroup($data);
    }

    public function test_there_are_at_most_fifty_project_groups(): void
    {
        for ($i = 1; $i <= TaskSetupService::MAX_PROJECT_GROUPS; ++$i) {
            $this->service->createProjectGroup(['name' => 'G' . $i]);
        }

        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('There can be at most 50 project groups');
        $this->service->createProjectGroup(['name' => 'One more']);
    }

    public function test_a_project_group_updates_partially_and_is_404_when_missing(): void
    {
        $id = self::id($this->service->createProjectGroup(['name' => 'Odoo', 'color' => '#111']));

        $renamed = $this->service->updateProjectGroup($id, ['name' => 'Odoo rollout']);
        self::assertSame('Odoo rollout', $renamed['name']);
        self::assertSame('#111', $renamed['color']);
        self::assertNull($this->service->updateProjectGroup($id, ['color' => null])['color']);

        try {
            $this->service->updateProjectGroup($id, ['sort_order' => '1.5']);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame('Sort order must be a whole number', $e->getMessage());
        }
        try {
            $this->service->updateProjectGroup('nope', ['name' => 'x']);
            self::fail('Expected a 404.');
        } catch (TaskNotFound $e) {
            self::assertSame('Group not found', $e->getMessage());
        }
        $this->expectException(TaskNotFound::class);
        $this->service->deleteProjectGroup('nope');
    }

    public function test_a_project_is_put_in_a_group_moved_out_and_refused_an_unknown_one(): void
    {
        $group = self::id($this->service->createProjectGroup(['name' => 'Odoo']));

        $p = $this->service->createProject($this->manager, ['name' => 'Close', 'group_id' => $group]);
        self::assertSame($group, $p['group_id']);
        $id = self::id($p);

        self::assertSame($group, $this->service->updateProject($this->manager, $id, ['name' => 'Close 2'])['group_id'], 'omitted is unchanged');
        self::assertNull($this->service->updateProject($this->manager, $id, ['group_id' => ''])['group_id']);
        self::assertSame($group, $this->service->updateProject($this->manager, $id, ['group_id' => $group])['group_id']);
        self::assertNull($this->service->updateProject($this->manager, $id, ['group_id' => null])['group_id']);

        foreach (['nope', 12, ['x']] as $bad) {
            try {
                $this->service->updateProject($this->manager, $id, ['group_id' => $bad]);
                self::fail('Expected a refusal.');
            } catch (TaskInvalid $e) {
                self::assertSame('That group does not exist', $e->getMessage());
            }
        }
        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('That group does not exist');
        $this->service->createProject($this->manager, ['name' => 'Audit', 'group_id' => 'nope']);
    }

    public function test_deleting_a_project_group_ungroups_its_live_and_binned_projects_and_deletes_none(): void
    {
        $group = self::id($this->service->createProjectGroup(['name' => 'Odoo']));
        $other = self::id($this->service->createProjectGroup(['name' => 'Audit']));
        $live = self::id($this->service->createProject($this->manager, ['name' => 'Live', 'group_id' => $group]));
        $binned = self::id($this->service->createProject($this->manager, ['name' => 'Binned', 'group_id' => $group]));
        $this->service->deleteProject($this->manager, $binned);
        $elsewhere = self::id($this->service->createProject($this->manager, ['name' => 'Elsewhere', 'group_id' => $other]));

        self::assertSame(['deleted' => true], $this->service->deleteProjectGroup($group));

        self::assertSame(['Audit'], array_column($this->service->listProjectGroups(), 'name'));
        $groups = array_column($this->service->listProjects($this->manager), 'group_id', 'id');
        self::assertSame([$elsewhere => $other, $live => null], $groups);
        self::assertSame([$binned => null], array_column($this->service->listProjects($this->manager, true), 'group_id', 'id'));
    }

    // ==== templates ============================================================================

    public function test_a_template_is_created_with_defaults_and_listed_by_name(): void
    {
        $this->service->createTemplate(['name' => 'Zeta']);
        $t = $this->service->createTemplate(['name' => 'alpha', 'category' => 'ad_hoc', 'progress' => '40', 'due_date' => '2026-10-31', 'due_date_requested' => 'yes']);

        self::assertSame('ad_hoc', $t['category']);
        self::assertSame(40, $t['progress']);
        self::assertSame('2026-10-31', $t['due_date']);
        self::assertTrue($t['due_date_requested']);
        self::assertNull($t['preparer_id']);
        self::assertSame(['alpha', 'Zeta'], array_column($this->service->listTemplates(), 'name'));
        self::assertSame('routine', $this->service->listTemplates()[1]['category']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badTemplates(): iterable
    {
        yield 'no name' => [[], 'Name is required'];
        yield 'bad category' => [['name' => 'x', 'category' => 'weekly'], 'Category must be routine or ad_hoc'];
        yield 'progress 101' => [['name' => 'x', 'progress' => 101], 'Progress must be a number from 0 to 100'];
        yield 'progress text' => [['name' => 'x', 'default_progress' => 'lots'], 'Progress must be a number from 0 to 100'];
        yield 'progress negative' => [['name' => 'x', 'progress' => -1], 'Progress must be a number from 0 to 100'];
        yield 'bad date' => [['name' => 'x', 'due_date' => 'tomorrow'], 'Dates must be yyyy-MM-dd'];
        yield 'stranger preparer' => [['name' => 'x', 'preparer_id' => '99'], 'The preparer and reviewer must be members of Tri-Task'];
        yield 'junk reviewer' => [['name' => 'x', 'reviewer_id' => 'abc'], 'The preparer and reviewer must be members of Tri-Task'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badTemplates')]
    public function test_a_bad_template_is_refused_with_the_sources_message(array $data, string $message): void
    {
        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage($message);
        $this->service->createTemplate($data);
    }

    public function test_a_template_names_members_by_text_id_and_progress_rounds_half_to_even(): void
    {
        $this->directory->add(9, 'Una User', 'una@example.test');

        $t = $this->service->createTemplate(['name' => 'x', 'preparer_id' => '9', 'reviewer_id' => 9, 'progress' => 2.5]);

        self::assertSame('9', $t['preparer_id']);
        self::assertSame('9', $t['reviewer_id']);
        self::assertSame(2, $t['progress'], "Python's round(2.5) is 2");
        $cleared = $this->service->updateTemplate(self::id($t), ['preparer_id' => '', 'reviewer_id' => null, 'progress' => 3.5]);
        self::assertNull($cleared['preparer_id']);
        self::assertNull($cleared['reviewer_id']);
        self::assertSame(4, $cleared['progress']);
    }

    public function test_a_template_updates_partially_deletes_hard_and_is_404_when_missing(): void
    {
        $t = $this->service->createTemplate(['name' => 'x', 'description' => 'd']);
        $id = self::id($t);

        $u = $this->service->updateTemplate($id, ['requirements' => '- a']);
        self::assertSame('d', $u['description'], 'what was not sent is kept');
        self::assertSame('- a', $u['requirements']);
        self::assertSame(['deleted' => true], $this->service->deleteTemplate($id));
        self::assertSame([], $this->service->listTemplates());
        foreach ([fn () => $this->service->updateTemplate($id, []), fn () => $this->service->deleteTemplate($id)] as $call) {
            try {
                $call();
                self::fail('Expected not found.');
            } catch (TaskNotFound $e) {
                self::assertSame('Template not found', $e->getMessage());
            }
        }
    }

    // ==== statement areas ======================================================================

    public function test_an_area_is_created_with_defaults_and_listed_by_sort_order_then_name(): void
    {
        $this->service->createArea(['name' => 'Cash']);
        $this->service->createArea(['name' => 'Assets', 'category' => 'balance_sheet', 'sort_order' => '5']);
        $this->service->createArea(['name' => 'Revenue', 'category' => 'income_statement', 'sort_order' => 1.9]);

        $list = $this->service->listAreas();

        self::assertSame(['Cash', 'Revenue', 'Assets'], array_column($list, 'name'));
        self::assertSame('other', $list[0]['category']);
        self::assertSame(1, $list[1]['sort_order'], 'a float is cut, as int() does');
        self::assertSame(5, $list[2]['sort_order']);
    }

    public function test_an_area_refuses_an_unknown_category_a_fractional_text_sort_order_and_a_missing_name(): void
    {
        foreach ([
            [['name' => 'x', 'category' => 'tax'], 'Unknown statement category'],
            [['name' => 'x', 'sort_order' => '5.7'], 'Sort order must be a whole number'],
            [['name' => 'x', 'sort_order' => 'many'], 'Sort order must be a whole number'],
            [[], 'Name is required'],
            [['name' => str_repeat('a', 301)], 'Name must be at most 300 characters'],
        ] as [$data, $message]) {
            try {
                $this->service->createArea($data);
                self::fail('Expected a refusal: ' . $message);
            } catch (TaskInvalid $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_an_area_name_is_unique_on_create_and_rename_now(): void
    {
        $a = $this->service->createArea(['name' => 'Cash']);
        $b = $this->service->createArea(['name' => 'Equity']);

        foreach ([
            fn () => $this->service->createArea(['name' => 'CASH']),
            fn () => $this->service->updateArea(self::id($b), ['name' => 'cash']),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a conflict.');
            } catch (TaskConflict $e) {
                self::assertSame('A statement area with that name already exists', $e->getMessage());
            }
        }
        self::assertSame('Cash', $this->service->updateArea(self::id($a), ['name' => 'Cash', 'description' => 'd'])['name']);
    }

    public function test_bulk_areas_skip_existing_and_repeated_names_and_return_only_what_was_created(): void
    {
        $this->service->createArea(['name' => 'Cash']);

        $created = $this->service->bulkCreateAreas([
            ['name' => 'cash'],
            ['name' => 'Revenue', 'category' => 'income_statement'],
            ['name' => 'REVENUE'],
            ['name' => 'Equity', 'category' => 'equity', 'sort_order' => 3],
        ]);

        self::assertSame(['Revenue', 'Equity'], array_column($created, 'name'));
        self::assertCount(3, $this->service->listAreas());
    }

    public function test_bulk_areas_are_all_or_nothing_and_bounded(): void
    {
        try {
            $this->service->bulkCreateAreas([['name' => 'Good'], ['name' => '']]);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame('Name is required', $e->getMessage());
        }
        self::assertSame([], $this->service->listAreas(), 'one bad item creates none');

        foreach ([[], 'text', null, ['a' => ['name' => 'x']]] as $bad) {
            try {
                $this->service->bulkCreateAreas($bad);
                self::fail('Expected a refusal.');
            } catch (TaskInvalid $e) {
                self::assertSame('Send a list of statement areas', $e->getMessage());
            }
        }
        $many = array_map(static fn (int $i): array => ['name' => 'Area ' . $i], range(1, 101));
        try {
            $this->service->bulkCreateAreas($many);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame('Up to 100 areas at once', $e->getMessage());
        }
        self::assertCount(100, $this->service->bulkCreateAreas(array_slice($many, 0, 100)));
    }

    public function test_an_item_that_is_not_an_object_reads_as_a_missing_name(): void
    {
        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('Name is required');
        $this->service->bulkCreateAreas(['Cash']);
    }

    public function test_an_area_used_by_a_live_task_cannot_be_deleted_but_a_binned_one_does_not_count(): void
    {
        $a = $this->service->createArea(['name' => 'Cash']);
        $this->task('t1', null, null, 'cash', true);
        self::assertSame(['deleted' => true], $this->service->deleteArea(self::id($a)));

        $b = $this->service->createArea(['name' => 'Equity']);
        $this->task('t2', null, null, 'Equity');
        $this->task('t3', null, null, 'Equity');
        try {
            $this->service->deleteArea(self::id($b));
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('2 task(s) still use this area', $e->getMessage());
            self::assertSame(['count' => 2], $e->extra);
        }
        $this->expectException(TaskNotFound::class);
        $this->expectExceptionMessage('Statement area not found');
        $this->service->deleteArea(self::id($a));
    }

    // ==== entities =============================================================================

    public function test_an_entity_is_created_active_by_default_and_listed_by_name(): void
    {
        $this->service->createEntity(['name' => 'Zed Pty']);
        $e = $this->service->createEntity(['name' => 'Alpha Pty', 'code' => ' AP ', 'color' => '#6366F1', 'is_active' => 'false']);

        self::assertSame('AP', $e['code']);
        self::assertFalse($e['is_active']);
        self::assertSame(['Alpha Pty', 'Zed Pty'], array_column($this->service->listEntities(), 'name'));
        self::assertTrue($this->service->listEntities()[1]['is_active']);
    }

    public function test_entity_validation_and_unique_names(): void
    {
        foreach ([
            [[], 'Name is required'],
            [['name' => str_repeat('a', 201)], 'Name must be at most 200 characters'],
            [['name' => 'x', 'code' => str_repeat('a', 41)], 'Code must be at most 40 characters'],
        ] as [$data, $message]) {
            try {
                $this->service->createEntity($data);
                self::fail('Expected a refusal.');
            } catch (TaskInvalid $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        $a = $this->service->createEntity(['name' => 'Alpha Pty']);
        $b = $this->service->createEntity(['name' => 'Beta Pty']);
        foreach ([
            fn () => $this->service->createEntity(['name' => 'ALPHA PTY']),
            fn () => $this->service->updateEntity(self::id($b), ['name' => 'alpha pty']),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a conflict.');
            } catch (TaskConflict $e) {
                self::assertSame('An entity with that name already exists', $e->getMessage());
            }
        }
        self::assertSame('Alpha Pty', $this->service->updateEntity(self::id($a), ['code' => 'A'])['name']);
    }

    public function test_an_entity_used_by_any_task_even_a_binned_one_cannot_be_deleted(): void
    {
        $e = $this->service->createEntity(['name' => 'Alpha Pty']);
        $this->task('t1', null, self::id($e), '', true);

        try {
            $this->service->deleteEntity(self::id($e));
            self::fail('Expected a conflict.');
        } catch (TaskConflict $failure) {
            self::assertSame('1 task(s) still use this entity', $failure->getMessage());
            self::assertSame(['count' => 1], $failure->extra);
        }
        $this->tasks = [];
        self::assertSame(['deleted' => true], $this->service->deleteEntity(self::id($e)));
        $this->expectException(TaskNotFound::class);
        $this->expectExceptionMessage('Entity not found');
        $this->service->updateEntity(self::id($e), []);
    }

    public function test_unicode_survives_every_list(): void
    {
        $this->service->createProject($this->manager, ['name' => 'Fin — Ünïcode ✓']);
        $this->service->createEntity(['name' => 'Société Générale']);
        $this->service->createArea(['name' => 'Trésorerie']);

        self::assertSame('Fin — Ünïcode ✓', $this->service->listProjects($this->manager)[0]['name']);
        self::assertSame('Société Générale', $this->service->listEntities()[0]['name']);
        self::assertSame('Trésorerie', $this->service->listAreas()[0]['name']);
    }

    public function test_a_template_holds_several_preparers_and_reviewers_and_answers_the_first_for_older_clients(): void
    {
        $this->directory->add(9, 'Una User', 'una@example.test');
        $this->directory->add(10, 'Ivy User', 'ivy@example.test');

        $t = $this->service->createTemplate(['name' => 'Rec', 'preparer_ids' => ['9', 10, '9'], 'reviewer_ids' => ['10']]);

        self::assertSame(['9', '10'], $t['preparer_ids']);
        self::assertSame(['10'], $t['reviewer_ids']);
        self::assertSame(['9', '10'], [$t['preparer_id'], $t['reviewer_id']]);
        // An older client sends the first person back: nothing is dropped. Another person replaces the list.
        $same = $this->service->updateTemplate(self::id($t), ['preparer_id' => '9', 'name' => 'Rec 2']);
        self::assertSame(['9', '10'], $same['preparer_ids']);
        $replaced = $this->service->updateTemplate(self::id($t), ['preparer_id' => '10']);
        self::assertSame(['10'], $replaced['preparer_ids']);
        $cleared = $this->service->updateTemplate(self::id($t), ['preparer_ids' => [], 'reviewer_id' => null]);
        self::assertSame([[], null, [], null], [$cleared['preparer_ids'], $cleared['preparer_id'], $cleared['reviewer_ids'], $cleared['reviewer_id']]);
        $this->expectException(TaskInvalid::class);
        $this->service->createTemplate(['name' => 'Bad', 'reviewer_ids' => ['9', '99']]);
    }
}
