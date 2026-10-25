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

    // ==== progress and status move together (14 Oct 2026) =========================================

    public function test_done_sets_100_and_reopening_a_done_task_without_a_progress_drops_it_to_75(): void
    {
        $id = self::id($this->tasks->create($this->max, ['name' => 'A', 'preparer_id' => 11, 'progress' => 20]));

        self::assertSame(100, $this->tasks->update($this->max, $id, ['status' => 'completed'])['progress']);
        $reopened = $this->tasks->update($this->max, $id, ['status' => 'active']);
        self::assertSame(['active', 75], [$reopened['status'], $reopened['progress']]);
        $this->tasks->update($this->max, $id, ['status' => 'completed']);
        $held = $this->tasks->update($this->max, $id, ['status' => 'on_hold', 'progress' => 40]);
        self::assertSame(['on_hold', 40], [$held['status'], $held['progress']], 'a progress sent with the status is kept');
    }

    public function test_100_percent_makes_the_task_done_for_someone_who_may_close_it(): void
    {
        $noReviewer = self::id($this->tasks->create($this->una, ['name' => 'Mine', 'preparer_id' => 11]));
        $done = $this->tasks->update($this->una, $noReviewer, ['progress' => 100]);
        self::assertSame('completed', $done['status']);
        self::assertNotNull($done['completed_at']);
        self::assertArrayNotHasKey('review_due', $done);

        $reviewed = self::id($this->tasks->create($this->max, ['name' => 'Reviewed', 'preparer_id' => 11, 'reviewer_id' => 13]));
        self::assertSame('completed', $this->tasks->update($this->max, $reviewed, ['progress' => 100])['status'], 'a Manager closes any task');
        self::assertSame('completed', $this->tasks->create($this->max, ['name' => 'Born done', 'progress' => 100])['status']);
    }

    public function test_a_preparer_at_100_percent_with_a_reviewer_stays_active_and_the_review_is_due_once(): void
    {
        $id = self::id($this->tasks->create($this->max, ['name' => 'B', 'preparer_id' => 11, 'reviewer_id' => 12, 'progress' => 50]));

        $first = $this->tasks->update($this->una, $id, ['progress' => 100]);
        self::assertSame(['active', 100, true], [$first['status'], $first['progress'], $first['review_due']]);
        self::assertArrayNotHasKey('review_due', $this->tasks->update($this->una, $id, ['progress' => 100]), 'already at 100: not asked again');
        $bulk = $this->tasks->bulkUpdate($this->una, [['id' => $id, 'progress' => 75], ['id' => $id, 'progress' => 100]]);
        self::assertTrue($bulk[1]['review_due']);
    }

    public function test_a_form_sending_the_unchanged_status_with_100_percent_still_closes_the_task(): void
    {
        $id = self::id($this->tasks->create($this->una, ['name' => 'E', 'preparer_id' => 11, 'progress' => 25]));
        self::assertSame('completed', $this->tasks->update($this->una, $id, ['status' => 'active', 'progress' => 100])['status']);
        self::assertSame('completed', $this->tasks->create($this->una, ['name' => 'F', 'status' => 'active', 'progress' => 100])['status']);
    }

    public function test_below_100_reopens_a_done_task_but_leaves_on_hold_alone(): void
    {
        $id = self::id($this->tasks->create($this->max, ['name' => 'C', 'preparer_id' => 11, 'status' => 'completed']));
        $reopened = $this->tasks->update($this->max, $id, ['progress' => 50]);
        self::assertSame(['active', 50, null], [$reopened['status'], $reopened['progress'], $reopened['completed_at']]);

        $held = self::id($this->tasks->create($this->max, ['name' => 'D', 'preparer_id' => 11, 'status' => 'on_hold']));
        self::assertSame('on_hold', $this->tasks->update($this->max, $held, ['progress' => 25])['status']);
    }

    // ==== create ===============================================================================

    public function test_a_task_with_no_project_goes_in_general_made_once_and_a_chosen_project_is_kept(): void
    {
        $first = $this->tasks->create($this->una, ['name' => 'A']);
        $second = $this->tasks->create($this->una, ['name' => 'B', 'project_id' => null]);

        self::assertIsString($first['project_id']);
        self::assertSame($first['project_id'], $second['project_id']);
        $general = array_values(array_filter($this->store->projects(false), static fn ($p): bool => $p->name === 'General'));
        self::assertCount(1, $general);
        self::assertSame('active', $general[0]->status);

        $close = $this->setup->createProject($this->max, ['name' => 'Close']);
        self::assertSame($close['id'], $this->tasks->create($this->una, ['name' => 'C', 'project_id' => $close['id']])['project_id']);
    }

    public function test_a_task_is_created_with_defaults_a_derived_status_and_its_first_history_row(): void
    {
        $t = $this->tasks->create($this->una, ['name' => ' Bank reconciliation ', 'due_date' => '2026-10-31', 'preparer_id' => '11']);

        self::assertSame('Bank reconciliation', $t['name']);
        self::assertSame('routine', $t['category']);
        // A new task starts Active (Active, On Hold, Done: 13 Oct 2026).
        self::assertSame('active', $t['status']);
        self::assertSame('active', $t['display_status']);
        self::assertSame(0, $t['progress']);
        self::assertSame('11', $t['preparer_id']);
        self::assertNull($t['reviewer_id']);
        self::assertSame('11', $t['created_by_id']);
        self::assertSame([], $t['dependencies']);
        // No project given: it goes in General (item 3, 13 Oct 2026).
        self::assertSame('General', $t['project_name']);
        self::assertFalse($t['is_deleted']);
        self::assertNull($t['completed_at']);
        self::assertSame('2026-10-07T01:00:00.123456+00:00', $t['created_date']);

        $history = $this->tasks->history($this->una, self::id($t));
        self::assertCount(1, $history);
        self::assertNull($history[0]['from_status']);
        self::assertSame('active', $history[0]['to_status']);
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
        yield 'unknown status' => [['name' => 'x', 'status' => 'done'], 'Status must be active, on_hold or completed'];
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
        self::assertSame('active', $yesterday['status'], 'stored status stays active');
        self::assertSame('active', $today['display_status']);
        self::assertSame('active', $none['display_status']);
        self::assertSame('completed', $done['display_status']);
    }

    public function test_an_on_hold_task_past_its_due_date_shows_on_hold_not_overdue(): void
    {
        // Overdue is for Active tasks only (13 Oct 2026).
        $held = $this->make($this->max, 'Held', ['due_date' => '2026-10-01', 'status' => 'on_hold']);

        self::assertSame('on_hold', $held['status']);
        self::assertSame('on_hold', $held['display_status']);
        self::assertSame('overdue', $this->tasks->update($this->max, self::id($held), ['status' => 'active'])['display_status']);
    }

    public function test_a_legacy_status_reads_and_is_sent_as_active(): void
    {
        // A row not yet moved by alter-finance-task-simple-status.sql, or a page loaded before 13 Oct 2026.
        $t = $this->make($this->max, 'Old', ['due_date' => '2026-10-31']);
        $record = $this->store->findTask(self::id($t));
        self::assertNotNull($record);
        $record->status = 'ready_for_review';
        $this->store->updateTask($record);

        $read = $this->tasks->get($this->max, self::id($t));
        self::assertSame('active', $read['status']);
        self::assertSame('active', $read['display_status']);

        $this->tick();
        self::assertSame('active', $this->tasks->update($this->max, self::id($t), ['status' => 'stuck'])['status']);
        self::assertSame('active', $this->tasks->create($this->max, ['name' => 'New', 'status' => 'in_progress'])['status']);
        self::assertCount(1, $this->tasks->history($this->max, self::id($t)), 'legacy to active is no change');
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

    public function test_a_status_change_writes_one_status_row_and_every_other_change_a_row_of_its_own(): void
    {
        $t = $this->make($this->max, 'x');
        $id = self::id($t);
        $this->tasks->update($this->max, $id, ['progress' => 10, 'name' => 'y']);
        $this->tick();
        $done = $this->tasks->update($this->max, $id, ['status' => 'completed']);
        $this->tick();
        $same = $this->tasks->update($this->max, $id, ['status' => 'completed']);
        $this->tick();
        $reopened = $this->tasks->update($this->max, $id, ['status' => 'active']);

        self::assertNotNull($done['completed_at']);
        self::assertNotNull($same['completed_at']);
        self::assertNull($reopened['completed_at']);
        $rows = $this->tasks->history($this->max, $id);
        self::assertSame([
            'name: x → y',
            'progress: 0 → 10',
            'progress: 10 → 100',
            'progress: 100 → 75',
            'status: - → active',
            'status: active → completed',
            'status: completed → active',
        ], self::changes($rows), 'the second Done changes nothing, so it logs nothing');
        $statuses = array_values(array_filter($rows, static fn (array $row): bool => $row['field'] === 'status'));
        self::assertSame([['completed', 'active'], ['active', 'completed'], [null, 'active']], array_map(
            static fn (array $row): array => [$row['from_status'], $row['to_status']],
            $statuses,
        ));
        self::assertSame('Max Manager', $rows[0]['changed_by']);
    }

    public function test_every_field_a_save_changes_is_logged_people_and_projects_by_name_and_long_text_without_its_words(): void
    {
        $close = self::id($this->setup->createProject($this->max, ['name' => 'Close']));
        $id = self::id($this->make($this->max, 'Reconcile', ['preparer_id' => 11, 'reviewer_id' => 12, 'notes' => 'old']));
        // A form sends every field, changed or not.
        $this->tasks->update($this->max, $id, ['name' => 'Reconcile', 'preparer_id' => 11, 'reviewer_id' => 12, 'notes' => 'old', 'status' => 'active', 'progress' => 0]);
        self::assertCount(1, $this->tasks->history($this->max, $id), 'a save that changes nothing logs nothing');
        $this->tick();

        $this->tasks->update($this->max, $id, [
            'preparer_id' => 13, 'reviewer_id' => null, 'progress' => 25, 'notes' => 'new words', 'project_id' => $close,
            'priority' => 'high', 'due_date' => '2026-10-31', 'urgent' => true, 'category' => 'ad_hoc',
        ]);

        $rows = $this->tasks->history($this->max, $id);
        $latest = array_values(array_filter($rows, static fn (array $row): bool => $row['changed_at'] === $rows[0]['changed_at']));
        self::assertSame([
            'category: routine → ad_hoc',
            'due_date: - → 2026-10-31',
            'notes: - → -',
            'preparer: Una User → Rae Reader',
            'priority: - → high',
            'progress: 0 → 25',
            'project: General → Close',
            'reviewer: Max Manager → -',
            'urgent: no → yes',
        ], self::changes($latest));
        self::assertSame(array_fill(0, 9, 'Max Manager'), array_column($latest, 'changed_by'));
        self::assertCount(1, $this->tasks->historyByStatus(null), 'the status feed has only status changes');
    }

    /**
     * Each row as "field: from → to", sorted: the rows of one save share a time, in no set order.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function changes(array $rows): array
    {
        $text = static fn (mixed $value): string => is_string($value) ? $value : '-';
        $out = [];
        foreach ($rows as $row) {
            $out[] = $row['field'] === 'status'
                ? 'status: ' . $text($row['from_status']) . ' → ' . $text($row['to_status'])
                : $text($row['field']) . ': ' . $text($row['from_value']) . ' → ' . $text($row['to_value']);
        }
        sort($out);

        return $out;
    }

    public function test_history_is_newest_first_and_records_the_name_at_the_time(): void
    {
        $t = $this->make($this->max, 'x');
        $id = self::id($t);
        $this->tasks->update($this->max, $id, ['status' => 'completed']);
        $this->tick();
        $this->tasks->update(new TaskActor(12, 'Max Renamed', 'max@example.test', $this->max->permissions), $id, ['status' => 'on_hold']);

        $rows = array_values(array_filter($this->tasks->history($this->max, $id), static fn (array $row): bool => $row['field'] === 'status'));

        self::assertSame(['on_hold', 'completed', 'active'], array_column($rows, 'to_status'));
        self::assertSame(['Max Renamed', 'Max Manager', 'Max Manager'], array_column($rows, 'changed_by'));
        self::assertCount(3, $this->tasks->historyByStatus(null));
        self::assertCount(1, $this->tasks->historyByStatus('on_hold'));
        self::assertCount(1, $this->tasks->historyByStatus('completed'));
    }

    public function test_an_unfinished_dependency_blocks_completing_with_the_blockers_listed_but_not_holding_or_reopening(): void
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
        self::assertSame('active', $this->tasks->get($this->max, self::id($b))['status'], 'nothing changed');
        // Only moving into Done is blocked (13 Oct 2026): holding, and reopening a done task, go through.
        self::assertSame('on_hold', $this->tasks->update($this->max, self::id($b), ['status' => 'on_hold'])['status']);
        self::assertSame('active', $this->tasks->update($this->max, self::id($c), ['status' => 'active'])['status']);

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
        $this->tasks->update($this->max, self::id($t), ['status' => 'active', 'dependencies' => []]);
        try {
            $this->tasks->update($this->max, self::id($t), ['status' => 'completed', 'dependencies' => [self::id($a)]]);
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

    public function test_a_task_in_a_binned_project_comes_back_with_the_project_not_on_its_own(): void
    {
        $manager = new TaskActor(12, 'Max Manager', 'max@example.test', $this->max->permissions);
        $project = self::id($this->setup->createProject($manager, ['name' => 'Close']));
        $a = self::id($this->make($this->max, 'A', ['project_id' => $project]));
        $b = self::id($this->make($this->max, 'B', ['project_id' => $project]));
        $this->setup->deleteProject($manager, $project);

        foreach ([fn () => $this->tasks->restore($this->max, $a), fn () => $this->tasks->bulkRestore($this->max, [$a, $b])] as $attempt) {
            try {
                $attempt();
                self::fail('Expected a conflict.');
            } catch (TaskConflict $e) {
                self::assertSame('Its project "Close" is in the Bin. Restore the project from the Bin first.', $e->getMessage());
            }
        }
        self::assertCount(2, $this->tasks->list($this->max, true));

        $this->setup->restoreProject($manager, $project);

        self::assertCount(0, $this->tasks->list($this->max, true));
        self::assertCount(2, $this->tasks->list($this->max));
    }

    public function test_deleting_a_project_for_good_takes_its_binned_tasks_their_history_and_dependencies_with_it(): void
    {
        $manager = new TaskActor(12, 'Max Manager', 'max@example.test', $this->max->permissions);
        $project = self::id($this->setup->createProject($manager, ['name' => 'Close']));
        $outside = $this->make($this->max, 'Outside');
        $a = $this->make($this->max, 'A', ['project_id' => $project]);
        $this->make($this->max, 'B', ['project_id' => $project, 'dependencies' => [self::id($a), self::id($outside)]]);
        $this->tasks->update($this->max, self::id($outside), ['dependencies' => [self::id($a)]]);

        $this->setup->deleteProject($manager, $project);
        $this->setup->deleteProject($manager, $project, true);

        self::assertSame(['Outside'], array_column($this->tasks->list($this->max, true) + $this->tasks->list($this->max), 'name'));
        self::assertSame([], $this->tasks->get($this->max, self::id($outside))['dependencies']);
        self::assertCount(1, $this->tasks->historyByStatus(null));
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

        self::assertSame('active', $this->tasks->get($this->max, self::id($a))['status']);
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
