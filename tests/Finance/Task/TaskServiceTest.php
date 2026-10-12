<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** Tasks: visibility, derived status, dependencies, history, the Bin and bulk updates, on the in-memory twin (T2.4). */
final class TaskServiceTest extends TestCase
{
    private InMemoryTaskStore $store;

    private FixedClock $clock;

    private TaskService $tasks;

    private TaskSetupService $setup;

    /** A team member: reads, creates, updates and deletes only their own tasks. */
    private TaskActor $una;

    /** A manager: sees every task, may change and delete any. */
    private TaskActor $max;

    /** Sees every task but may only change or delete tasks that are theirs. */
    private TaskActor $reader;

    protected function setUp(): void
    {
        $directory = new InMemoryFinanceTaskMemberDirectory();
        foreach ([11 => 'Una User', 12 => 'Max Manager', 13 => 'Rae Reader', 14 => 'Other Person'] as $id => $name) {
            $directory->add($id, $name, strtolower(explode(' ', $name)[0]) . '@example.test');
        }
        $this->store = new InMemoryTaskStore();
        $this->clock = FixedClock::utc('2026-10-07 01:00:00.123456');
        $time = new TaskTime($this->clock, 'Australia/Melbourne');
        $this->tasks = new TaskService($this->store, $this->store, $directory, $time);
        $this->setup = new TaskSetupService($this->store, $directory, $time);

        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE]);
        $this->reader = new TaskActor(13, 'Rae Reader', 'rae@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL]);
    }

    private function tick(string $modifier = '+1 second'): void
    {
        $this->clock->advance($modifier);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function make(TaskActor $by, string $name, array $data = []): array
    {
        $task = $this->tasks->create($by, ['name' => $name] + $data);
        $this->tick();

        return $task;
    }

    private static function id(mixed $row): string
    {
        self::assertIsArray($row);
        self::assertIsString($row['id']);

        return $row['id'];
    }

    /**
     * @return list<string>
     */
    private static function names(mixed $rows): array
    {
        self::assertIsArray($rows);
        $names = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['name']);
            $names[] = $row['name'];
        }

        return $names;
    }

    // ==== create ===============================================================================

    public function test_a_task_is_created_with_defaults_a_derived_status_and_its_first_history_row(): void
    {
        $t = $this->tasks->create($this->una, ['name' => ' Bank reconciliation ', 'due_date' => '2026-10-31', 'preparer_id' => '11']);

        self::assertSame('Bank reconciliation', $t['name']);
        self::assertSame('routine', $t['category']);
        // A new task starts Not started (the board's statuses, 13 Oct 2026).
        self::assertSame('not_started', $t['status']);
        self::assertSame('not_started', $t['display_status']);
        self::assertSame(0, $t['progress']);
        self::assertSame('11', $t['preparer_id']);
        self::assertNull($t['reviewer_id']);
        self::assertSame('11', $t['created_by_id']);
        self::assertSame([], $t['dependencies']);
        self::assertSame('', $t['project_name']);
        self::assertSame('', $t['project']);
        self::assertFalse($t['is_deleted']);
        self::assertNull($t['completed_at']);
        self::assertSame('2026-10-07T01:00:00.123456+00:00', $t['created_date']);

        $history = $this->tasks->history($this->una, self::id($t));
        self::assertCount(1, $history);
        self::assertNull($history[0]['from_status']);
        self::assertSame('not_started', $history[0]['to_status']);
        self::assertSame('Una User', $history[0]['changed_by']);
        self::assertSame('11', $history[0]['changed_by_user_id']);
    }

    public function test_a_task_created_completed_has_a_completed_time(): void
    {
        $t = $this->tasks->create($this->una, ['name' => 'x', 'status' => 'completed', 'progress' => 100, 'preparer_id' => 11]);

        self::assertSame('completed', $t['display_status']);
        self::assertSame('2026-10-07T01:00:00.123456+00:00', $t['completed_at']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badTasks(): iterable
    {
        yield 'no name' => [[], 'Name is required'];
        yield 'long name' => [['name' => str_repeat('a', 301)], 'Name must be at most 300 characters'];
        yield 'long area' => [['name' => 'x', 'finance_statement_area' => str_repeat('a', 301)], 'Finance statement area must be at most 300 characters'];
        yield 'bad category' => [['name' => 'x', 'category' => 'weekly'], 'Category must be routine or ad_hoc'];
        yield 'overdue status' => [['name' => 'x', 'status' => 'overdue'], 'Overdue is worked out from the due date and cannot be set'];
        yield 'unknown status' => [['name' => 'x', 'status' => 'done'], 'Status must be not_started, in_progress, stuck, ready_for_review or completed'];
        yield 'progress 101' => [['name' => 'x', 'progress' => 101], 'Progress must be a number from 0 to 100'];
        yield 'bad date' => [['name' => 'x', 'due_date' => '31/10/2026'], 'Dates must be yyyy-MM-dd'];
        yield 'not a date' => [['name' => 'x', 'due_date' => '2026-13-01'], 'That is not a real date'];
        yield 'unknown entity' => [['name' => 'x', 'entity_id' => 'ffffffffffffffffffffffffffffffff'], 'That entity does not exist'];
        yield 'unknown project' => [['name' => 'x', 'project_id' => 'ffffffffffffffffffffffffffffffff'], 'That project does not exist'];
        yield 'stranger preparer' => [['name' => 'x', 'preparer_id' => '99'], 'The preparer and reviewer must be members of Tri-Task'];
        yield 'stranger reviewer' => [['name' => 'x', 'reviewer_id' => 99], 'The preparer and reviewer must be members of Tri-Task'];
        yield 'dependencies not a list' => [['name' => 'x', 'dependencies' => ['a' => 'b']], 'Dependencies must be a list of task ids'];
        yield 'dependencies a string' => [['name' => 'x', 'dependencies' => 'abc'], 'Dependencies must be a list of task ids'];
        yield 'missing dependency' => [['name' => 'x', 'dependencies' => ['ffffffffffffffffffffffffffffffff']], 'A dependency does not exist'];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('badTasks')]
    public function test_a_bad_task_is_refused_with_the_sources_message_and_nothing_is_written(array $data, string $message): void
    {
        try {
            $this->tasks->create($this->una, $data);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame($message, $e->getMessage());
        }
        self::assertSame([], $this->tasks->list($this->max));
        self::assertSame([], $this->tasks->historyByStatus(null));
    }

    public function test_creating_needs_the_create_permission_and_a_binned_project_does_not_exist_for_a_task(): void
    {
        try {
            $this->tasks->create(new TaskActor(11, 'Una', 'u@example.test', [Module::PERMISSION_TASK_TASKS_READ]), ['name' => 'x']);
            self::fail('Expected a refusal.');
        } catch (TaskForbidden $e) {
            self::assertSame('Permission denied', $e->getMessage());
            self::assertSame(403, $e->httpStatus());
        }
        $project = $this->setup->createProject($this->max, ['name' => 'Close']);
        $this->setup->deleteProject($this->max, self::id($project));
        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('That project does not exist');
        $this->tasks->create($this->una, ['name' => 'x', 'project_id' => self::id($project)]);
    }

    public function test_a_task_shows_its_project_by_name_both_ways_and_an_entity_can_be_set(): void
    {
        $project = $this->setup->createProject($this->max, ['name' => 'Month end']);
        $entity = $this->setup->createEntity(['name' => 'Alpha Pty']);

        $t = $this->tasks->create($this->una, ['name' => 'x', 'project_id' => self::id($project), 'entity_id' => self::id($entity), 'preparer_id' => 11]);

        self::assertSame('Month end', $t['project_name']);
        self::assertSame('Month end', $t['project']);
        self::assertSame(self::id($entity), $t['entity_id']);
        self::assertSame(1, $this->setup->listProjects($this->max)[0]['task_count']);
        self::assertSame(1, $this->setup->listProjects($this->una)[0]['task_count']);
        $stranger = new TaskActor(14, 'Other', 'o@example.test', [Module::PERMISSION_TASK_TASKS_READ]);
        self::assertSame(0, $this->setup->listProjects($stranger)[0]['task_count'], 'a caller without READ_ALL counts only their own');
    }

    // ==== visibility ===========================================================================

    public function test_without_read_all_a_person_sees_only_tasks_they_prepare_or_review_everywhere(): void
    {
        $mine = $this->make($this->una, 'Mine', ['preparer_id' => 11]);
        $reviewing = $this->make($this->una, 'Reviewing', ['reviewer_id' => 11, 'preparer_id' => 14]);
        $theirs = $this->make($this->una, 'Theirs', ['preparer_id' => 14]);

        self::assertEqualsCanonicalizing(['Mine', 'Reviewing'], self::names($this->tasks->list($this->una)));
        self::assertEqualsCanonicalizing(['Mine', 'Reviewing', 'Theirs'], self::names($this->tasks->list($this->max)));
        self::assertEqualsCanonicalizing(['Mine', 'Reviewing'], self::names($this->tasks->analytics($this->una)['tasks']));

        $hidden = self::id($theirs);
        foreach ([
            'get' => fn () => $this->tasks->get($this->una, $hidden),
            'history' => fn () => $this->tasks->history($this->una, $hidden),
            'update' => fn () => $this->tasks->update($this->una, $hidden, ['name' => 'x']),
            'delete' => fn () => $this->tasks->delete($this->una, $hidden),
            'restore' => fn () => $this->tasks->restore($this->una, $hidden),
            'bulk' => fn () => $this->tasks->bulkUpdate($this->una, [['id' => $hidden, 'progress' => 5]]),
        ] as $what => $call) {
            try {
                $call();
                self::fail($what . ' should be a 404.');
            } catch (TaskNotFound $e) {
                self::assertSame('Task not found', $e->getMessage(), $what);
                self::assertSame(404, $e->httpStatus(), $what);
            }
        }
        self::assertSame('Theirs', $this->tasks->get($this->max, $hidden)['name']);
        self::assertSame('Mine', $this->tasks->get($this->una, self::id($mine))['name']);
        self::assertSame('Reviewing', $this->tasks->get($this->una, self::id($reviewing))['name']);
    }

    public function test_a_task_someone_allocated_to_two_others_is_invisible_to_its_creator(): void
    {
        $t = $this->tasks->create($this->una, ['name' => 'Allocated', 'preparer_id' => 12, 'reviewer_id' => 13]);

        self::assertSame('Allocated', $t['name'], 'the create answer is not filtered');
        self::assertSame([], $this->tasks->list($this->una));
        $this->expectException(TaskNotFound::class);
        $this->tasks->get($this->una, self::id($t));
    }

    public function test_the_register_lists_the_newest_change_first_and_the_bin_separately(): void
    {
        $this->make($this->max, 'First');
        $second = $this->make($this->max, 'Second');
        $this->make($this->max, 'Third');
        $this->tasks->update($this->max, self::id($second), ['progress' => 50]);
        $this->tick();
        $this->tasks->delete($this->max, self::id($this->tasks->list($this->max)[2]));

        self::assertSame(['Second', 'Third'], self::names($this->tasks->list($this->max)));
        self::assertSame(['First'], self::names($this->tasks->list($this->max, true)));
        self::assertTrue($this->tasks->list($this->max, true)[0]['is_deleted']);
    }

    // ==== derived status =======================================================================

    public function test_overdue_is_derived_from_the_due_date_in_the_zone_not_stored(): void
    {
        // 01:00 UTC on 7 Oct is 12:00 in Melbourne (AEDT) on 7 Oct.
        $yesterday = $this->make($this->max, 'Yesterday', ['due_date' => '2026-10-06']);
        $today = $this->make($this->max, 'Today', ['due_date' => '2026-10-07']);
        $none = $this->make($this->max, 'None');
        $done = $this->make($this->max, 'Done', ['due_date' => '2026-01-01', 'status' => 'completed']);

        self::assertSame('overdue', $yesterday['display_status']);
        self::assertSame('not_started', $yesterday['status'], 'stored status stays not_started');
        self::assertSame('not_started', $today['display_status']);
        self::assertSame('not_started', $none['display_status']);
        self::assertSame('completed', $done['display_status']);
    }

    public function test_today_is_decided_in_melbourne_across_the_utc_date_line(): void
    {
        $this->clock->advance('+22 hours 30 minutes');   // 23:30 UTC on 7 Oct is 10:30 on 8 Oct in Melbourne
        $t = $this->tasks->create($this->max, ['name' => 'x', 'due_date' => '2026-10-07']);

        self::assertSame('overdue', $t['display_status']);
        self::assertSame('2026-10-08', $this->tasks->analytics($this->max)['today']);
        self::assertSame('Australia/Melbourne', $this->tasks->analytics($this->max)['timezone']);
    }

    // ==== update: own versus any ===============================================================

    public function test_a_member_changes_their_own_task_and_a_reader_may_see_but_not_change_someone_elses(): void
    {
        $own = $this->make($this->una, 'Own', ['preparer_id' => 11]);
        $other = $this->make($this->una, 'Other', ['preparer_id' => 14]);

        self::assertSame(40, $this->tasks->update($this->una, self::id($own), ['progress' => 40])['progress']);
        try {
            $this->tasks->update($this->reader, self::id($other), ['progress' => 10]);
            self::fail('Expected a refusal.');
        } catch (TaskForbidden $e) {
            self::assertSame('You do not have permission to change this task', $e->getMessage());
        }
        self::assertSame(10, $this->tasks->update($this->max, self::id($other), ['progress' => 10])['progress']);
    }

    public function test_an_update_changes_only_what_was_sent_and_validates_like_a_create(): void
    {
        $t = $this->make($this->max, 'Keep', ['description' => 'd', 'urgent' => true, 'due_date' => '2026-10-31']);
        $id = self::id($t);

        $u = $this->tasks->update($this->max, $id, ['notes' => 'n', 'due_date' => '', 'urgent' => 'no']);

        self::assertSame('d', $u['description']);
        self::assertSame('n', $u['notes']);
        self::assertSame('', $u['due_date']);
        self::assertFalse($u['urgent']);
        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('Progress must be a number from 0 to 100');
        $this->tasks->update($this->max, $id, ['progress' => 'a lot']);
    }

    // ==== status changes, blockers and history =================================================

    public function test_a_status_change_writes_one_history_row_and_other_edits_write_none(): void
    {
        $t = $this->make($this->max, 'x');
        $id = self::id($t);
        $this->tasks->update($this->max, $id, ['progress' => 10, 'name' => 'y']);
        $this->tick();
        $done = $this->tasks->update($this->max, $id, ['status' => 'completed']);
        $this->tick();
        $same = $this->tasks->update($this->max, $id, ['status' => 'completed']);
        $this->tick();
        $reopened = $this->tasks->update($this->max, $id, ['status' => 'in_progress']);

        self::assertNotNull($done['completed_at']);
        self::assertNotNull($same['completed_at']);
        self::assertNull($reopened['completed_at']);
        $rows = $this->tasks->history($this->max, $id);
        self::assertCount(3, $rows, 'created, completed, reopened: five edits, three rows');
        self::assertSame([['completed', 'in_progress'], ['not_started', 'completed'], [null, 'not_started']], array_map(
            static fn (array $row): array => [$row['from_status'], $row['to_status']],
            $rows,
        ));
        self::assertSame('Max Manager', $rows[0]['changed_by']);
    }

    public function test_history_is_newest_first_and_records_the_name_at_the_time(): void
    {
        $t = $this->make($this->max, 'x');
        $id = self::id($t);
        $this->tasks->update($this->max, $id, ['status' => 'completed']);
        $this->tick();
        $this->tasks->update(new TaskActor(12, 'Max Renamed', 'max@example.test', $this->max->permissions), $id, ['status' => 'in_progress']);

        $rows = $this->tasks->history($this->max, $id);

        self::assertSame(['in_progress', 'completed', 'not_started'], array_column($rows, 'to_status'));
        self::assertSame(['Max Renamed', 'Max Manager', 'Max Manager'], array_column($rows, 'changed_by'));
        self::assertCount(3, $this->tasks->historyByStatus(null));
        self::assertCount(1, $this->tasks->historyByStatus('in_progress'));
        self::assertCount(1, $this->tasks->historyByStatus('completed'));
    }

    public function test_an_unfinished_dependency_blocks_completing_and_reopening_with_the_blockers_listed(): void
    {
        $a = $this->make($this->max, 'Gather statements');
        $b = $this->make($this->max, 'Reconcile', ['dependencies' => [self::id($a)]]);   // creating runs no dependency check
        $c = $this->make($this->max, 'Approve', ['dependencies' => [self::id($b)], 'status' => 'completed']);   // so this one may even be completed

        self::assertSame([self::id($a)], $b['dependencies']);
        self::assertSame('completed', $c['status']);
        try {
            $this->tasks->update($this->max, self::id($b), ['status' => 'completed']);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('This task is blocked by unfinished tasks: Gather statements', $e->getMessage());
            self::assertSame(409, $e->httpStatus());
            self::assertSame(['blockers' => [['id' => self::id($a), 'name' => 'Gather statements']]], $e->extra);
        }
        // Reopening is a status change too.
        try {
            $this->tasks->update($this->max, self::id($c), ['status' => 'in_progress']);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertStringContainsString('Reconcile', $e->getMessage());
        }
        self::assertSame('not_started', $this->tasks->get($this->max, self::id($b))['status'], 'nothing changed');

        $this->tasks->update($this->max, self::id($a), ['status' => 'completed']);
        self::assertSame('completed', $this->tasks->update($this->max, self::id($b), ['status' => 'completed'])['status']);
    }

    public function test_blockers_are_listed_by_name_and_a_binned_or_completed_dependency_does_not_block(): void
    {
        $z = $this->make($this->max, 'Zulu');
        $a = $this->make($this->max, 'Alpha');
        $binned = $this->make($this->max, 'Binned');
        $done = $this->make($this->max, 'Done', ['status' => 'completed']);
        $t = $this->make($this->max, 'Target', ['dependencies' => [self::id($z), self::id($a), self::id($binned), self::id($done)]]);
        $this->tasks->delete($this->max, self::id($binned));

        try {
            $this->tasks->update($this->max, self::id($t), ['status' => 'completed']);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('This task is blocked by unfinished tasks: Alpha, Zulu', $e->getMessage());
        }
    }

    public function test_the_check_uses_the_dependencies_the_same_request_sets(): void
    {
        $a = $this->make($this->max, 'Open');
        $done = $this->make($this->max, 'Finished', ['status' => 'completed']);
        $t = $this->make($this->max, 'Target', ['dependencies' => [self::id($a)]]);

        // Swapping the open dependency for a finished one in the same request lets the change through.
        $u = $this->tasks->update($this->max, self::id($t), ['status' => 'completed', 'dependencies' => [self::id($done)]]);
        self::assertSame('completed', $u['status']);
        self::assertSame([self::id($done)], $u['dependencies']);

        // And naming an open one in the same request blocks it even though none was stored.
        $this->tasks->update($this->max, self::id($t), ['dependencies' => []]);
        try {
            $this->tasks->update($this->max, self::id($t), ['status' => 'in_progress', 'dependencies' => [self::id($a)]]);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertStringContainsString('Open', $e->getMessage());
        }
    }

    public function test_self_dependencies_and_cycles_are_refused_and_a_binned_task_still_counts_in_the_cycle_check(): void
    {
        $a = $this->make($this->max, 'A');
        $b = $this->make($this->max, 'B', ['dependencies' => [self::id($a)]]);
        $c = $this->make($this->max, 'C', ['dependencies' => [self::id($b)]]);

        foreach ([
            [self::id($a), [self::id($a)], 'A task cannot depend on itself'],
            [self::id($a), [self::id($b)], 'Those dependencies would make the task wait on itself'],
            [self::id($a), [self::id($c)], 'Those dependencies would make the task wait on itself'],
            [self::id($b), [self::id($c)], 'Those dependencies would make the task wait on itself'],
        ] as [$id, $deps, $message]) {
            try {
                $this->tasks->update($this->max, $id, ['dependencies' => $deps]);
                self::fail('Expected a refusal: ' . $message);
            } catch (TaskInvalid $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        // B goes to the Bin, but its edge A <- B <- C is still read by the cycle check.
        $this->tasks->delete($this->max, self::id($b));
        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('Those dependencies would make the task wait on itself');
        $this->tasks->update($this->max, self::id($a), ['dependencies' => [self::id($c)]]);
    }

    public function test_dependencies_are_deduplicated_and_replaceable_and_clearable(): void
    {
        $a = $this->make($this->max, 'A');
        $b = $this->make($this->max, 'B');
        $t = $this->make($this->max, 'T', ['dependencies' => [self::id($a), self::id($a), self::id($b)]]);

        self::assertSame([self::id($a), self::id($b)], $t['dependencies']);
        self::assertSame([self::id($b)], $this->tasks->update($this->max, self::id($t), ['dependencies' => [self::id($b)]])['dependencies']);
        self::assertSame([], $this->tasks->update($this->max, self::id($t), ['dependencies' => null])['dependencies']);
    }

    // ==== the Bin ==============================================================================

    public function test_delete_goes_to_the_bin_restore_brings_it_back_and_permanent_is_only_from_the_bin(): void
    {
        $t = $this->make($this->max, 'x');
        $id = self::id($t);

        try {
            $this->tasks->delete($this->max, $id, true);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('Move the task to the Bin before deleting it permanently', $e->getMessage());
        }
        self::assertSame(['deleted' => true, 'permanent' => false], $this->tasks->delete($this->max, $id));
        self::assertSame(['deleted' => true, 'permanent' => false], $this->tasks->delete($this->max, $id), 'a second delete is harmless');
        try {
            $this->tasks->update($this->max, $id, ['name' => 'y']);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('Restore this task from the Bin before changing it', $e->getMessage());
        }
        $restored = $this->tasks->restore($this->max, $id);
        self::assertFalse($restored['is_deleted']);
        self::assertNull($restored['deleted_at']);
        $this->tasks->delete($this->max, $id);
        self::assertSame(['deleted' => true, 'permanent' => true], $this->tasks->delete($this->max, $id, true));
        $this->expectException(TaskNotFound::class);
        $this->tasks->get($this->max, $id);
    }

    public function test_a_permanent_delete_removes_the_tasks_history_and_every_dependency_naming_it(): void
    {
        $a = $this->make($this->max, 'A');
        $b = $this->make($this->max, 'B', ['dependencies' => [self::id($a)]]);
        $c = $this->make($this->max, 'C');
        $this->tasks->update($this->max, self::id($c), ['dependencies' => [self::id($b)]]);
        $this->tasks->delete($this->max, self::id($b));
        $this->tasks->delete($this->max, self::id($b), true);

        self::assertSame([], $this->tasks->get($this->max, self::id($c))['dependencies']);
        self::assertCount(2, $this->tasks->historyByStatus(null), 'A and C keep their creation rows; B\'s went with it');
    }

    public function test_delete_and_restore_follow_own_versus_any(): void
    {
        $own = $this->make($this->una, 'Own', ['preparer_id' => 11]);
        $other = $this->make($this->una, 'Other', ['preparer_id' => 14]);

        $this->tasks->delete($this->una, self::id($own));
        $this->tasks->restore($this->una, self::id($own));
        try {
            $this->tasks->delete($this->reader, self::id($other));
            self::fail('Expected a refusal.');
        } catch (TaskForbidden $e) {
            self::assertSame('You do not have permission to delete this task', $e->getMessage());
        }
        try {
            $this->tasks->restore($this->reader, self::id($other));
            self::fail('Expected a refusal.');
        } catch (TaskForbidden $e) {
            self::assertSame('You do not have permission to restore this task', $e->getMessage());
        }
        self::assertSame(['deleted' => true, 'permanent' => false], $this->tasks->delete($this->max, self::id($other)));
    }

    // ==== bulk =================================================================================

    public function test_a_bulk_update_applies_every_row_and_answers_each_task(): void
    {
        $a = $this->make($this->max, 'A');
        $b = $this->make($this->max, 'B');

        $out = $this->tasks->bulkUpdate($this->max, [['id' => self::id($a), 'progress' => 20], ['id' => self::id($b), 'status' => 'completed', 'urgent' => true]]);

        self::assertSame(['A', 'B'], self::names($out));
        self::assertSame(20, $out[0]['progress']);
        self::assertSame('completed', $out[1]['status']);
        self::assertTrue($out[1]['urgent']);
        self::assertCount(1, $this->tasks->historyByStatus('completed'));
    }

    public function test_one_bad_row_rolls_the_whole_bulk_update_back_including_history(): void
    {
        $a = $this->make($this->max, 'A');
        $b = $this->make($this->max, 'B');
        $gate = $this->make($this->max, 'Gate');
        $c = $this->make($this->max, 'C', ['dependencies' => [self::id($gate)]]);

        try {
            $this->tasks->bulkUpdate($this->max, [
                ['id' => self::id($a), 'status' => 'completed', 'progress' => 100],
                ['id' => self::id($b), 'name' => 'Renamed'],
                ['id' => self::id($c), 'status' => 'completed'],
            ]);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertStringContainsString('Gate', $e->getMessage());
        }

        self::assertSame('not_started', $this->tasks->get($this->max, self::id($a))['status']);
        self::assertSame(0, $this->tasks->get($this->max, self::id($a))['progress']);
        self::assertSame('B', $this->tasks->get($this->max, self::id($b))['name']);
        self::assertSame([], $this->tasks->historyByStatus('completed'));
        self::assertCount(4, $this->tasks->historyByStatus(null), 'only the four creation rows');
    }

    public function test_bulk_is_bounded_and_checks_each_row(): void
    {
        $a = $this->make($this->max, 'A');
        $binned = $this->make($this->max, 'Binned');
        $this->tasks->delete($this->max, self::id($binned));
        $theirs = $this->make($this->una, 'Theirs', ['preparer_id' => 14]);

        foreach ([[], 'text', null, ['a' => 1]] as $bad) {
            try {
                $this->tasks->bulkUpdate($this->max, $bad);
                self::fail('Expected a refusal.');
            } catch (TaskInvalid $e) {
                self::assertSame('Send a list of tasks to update', $e->getMessage());
            }
        }
        try {
            $this->tasks->bulkUpdate($this->max, array_fill(0, 201, ['id' => self::id($a), 'progress' => 1]));
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame('Up to 200 tasks at once', $e->getMessage());
        }
        self::assertCount(200, $this->tasks->bulkUpdate($this->max, array_fill(0, 200, ['id' => self::id($a), 'progress' => 1])));

        foreach ([[['progress' => 1]], ['not a row'], [['id' => '']]] as $rows) {
            try {
                $this->tasks->bulkUpdate($this->max, $rows);
                self::fail('Expected a refusal.');
            } catch (TaskInvalid $e) {
                self::assertSame('Every row needs an id', $e->getMessage());
            }
        }
        try {
            $this->tasks->bulkUpdate($this->max, [['id' => self::id($binned), 'progress' => 1]]);
            self::fail('Expected a conflict.');
        } catch (TaskConflict $e) {
            self::assertSame('Binned is in the Bin', $e->getMessage());
        }
        try {
            $this->tasks->bulkUpdate($this->reader, [['id' => self::id($theirs), 'progress' => 1]]);
            self::fail('Expected a refusal.');
        } catch (TaskForbidden $e) {
            self::assertSame('You do not have permission to change this task', $e->getMessage());
        }
    }

    // ==== analytics ============================================================================

    public function test_analytics_hold_the_live_tasks_the_caller_may_see_the_zone_and_today(): void
    {
        $this->make($this->max, 'Live', ['preparer_id' => 11]);
        $gone = $this->make($this->max, 'Gone', ['preparer_id' => 11]);
        $this->make($this->max, 'Not mine', ['preparer_id' => 14]);
        $this->tasks->delete($this->max, self::id($gone));

        $mine = $this->tasks->analytics($this->una);

        self::assertSame(['Live'], self::names($mine['tasks']));
        self::assertSame('2026-10-07', $mine['today']);
        self::assertEqualsCanonicalizing(['Live', 'Not mine'], self::names($this->tasks->analytics($this->max)['tasks']));
    }
}
