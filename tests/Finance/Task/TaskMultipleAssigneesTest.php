<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Domain\TaskRecord;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * Several preparers and reviewers on one task (Nick Han, 23 Oct 2026: "Sometimes, multiple people will do one job
 * together"), on the in-memory twin: what the API says, what it still says for clients from before the lists, who sees
 * the task, and who may close it. The SQL store's side is AzureSqlTaskAssigneesTest.
 */
final class TaskMultipleAssigneesTest extends TestCase
{
    private InMemoryTaskStore $store;

    private TaskService $tasks;

    private TaskActor $una;

    private TaskActor $ivy;

    private TaskActor $rae;

    private TaskActor $outsider;

    private TaskActor $max;

    protected function setUp(): void
    {
        $directory = new InMemoryFinanceTaskMemberDirectory();
        foreach ([11 => 'Una User', 12 => 'Max Manager', 13 => 'Rae Reviewer', 14 => 'Out Sider', 16 => 'Ivy User'] as $id => $name) {
            $directory->add($id, $name, strtolower(explode(' ', $name)[0]) . '@example.test');
        }
        $this->store = new InMemoryTaskStore();
        $this->tasks = new TaskService($this->store, $this->store, $directory, new TaskTime(FixedClock::utc('2026-10-07 01:00:00'), 'Australia/Melbourne'));
        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->ivy = new TaskActor(16, 'Ivy User', 'ivy@example.test', $base);
        $this->rae = new TaskActor(13, 'Rae Reviewer', 'rae@example.test', $base);
        $this->outsider = new TaskActor(14, 'Out Sider', 'out@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function make(array $data = []): array
    {
        return $this->tasks->create($this->max, $data + ['name' => 'Bank rec']);
    }

    private static function id(mixed $row): string
    {
        self::assertIsArray($row);
        self::assertIsString($row['id']);

        return $row['id'];
    }

    public function test_a_task_has_several_preparers_and_reviewers_and_the_first_of_each_stays_in_the_old_fields(): void
    {
        $task = $this->make(['preparer_ids' => ['11', 16, '11'], 'reviewer_ids' => ['13', '12']]);

        self::assertSame(['11', '16'], $task['preparer_ids'], 'text ids, in order, each once');
        self::assertSame(['13', '12'], $task['reviewer_ids']);
        self::assertSame(['11', '13'], [$task['preparer_id'], $task['reviewer_id']], 'older clients read the first of each');

        $got = $this->tasks->get($this->max, self::id($task));
        self::assertSame(['11', '16'], $got['preparer_ids']);
    }

    public function test_a_task_may_have_no_reviewer_and_the_lists_are_empty_not_missing(): void
    {
        $task = $this->make(['preparer_ids' => ['11']]);

        self::assertSame([[], null], [$task['reviewer_ids'], $task['reviewer_id']]);
        $none = $this->make(['name' => 'Nobody']);
        self::assertSame([[], [], null, null], [$none['preparer_ids'], $none['reviewer_ids'], $none['preparer_id'], $none['reviewer_id']]);
    }

    public function test_the_single_keys_of_a_client_from_before_the_lists_still_write(): void
    {
        $task = $this->make(['preparer_id' => '11', 'reviewer_id' => 13]);

        self::assertSame([['11'], ['13']], [$task['preparer_ids'], $task['reviewer_ids']]);
        $cleared = $this->tasks->update($this->max, self::id($task), ['reviewer_id' => null]);
        self::assertSame([[], null], [$cleared['reviewer_ids'], $cleared['reviewer_id']]);
        $moved = $this->tasks->update($this->max, self::id($task), ['preparer_id' => '16']);
        self::assertSame(['16'], $moved['preparer_ids']);
        // The list wins when both are sent.
        $both = $this->tasks->update($this->max, self::id($task), ['preparer_id' => '11', 'preparer_ids' => ['16', '11']]);
        self::assertSame(['16', '11'], $both['preparer_ids']);
    }

    public function test_an_older_client_saving_the_first_person_back_does_not_drop_the_others(): void
    {
        $task = $this->make(['preparer_ids' => ['11', '16'], 'reviewer_ids' => ['13', '12']]);

        // The old form sends every field it knows, with the first preparer and reviewer as they were.
        $saved = $this->tasks->update($this->max, self::id($task), ['name' => 'Renamed', 'preparer_id' => '11', 'reviewer_id' => '13']);

        self::assertSame([['11', '16'], ['13', '12']], [$saved['preparer_ids'], $saved['reviewer_ids']]);
        // A different person (or none) is a real change, and replaces the list, as it always did; so is the second one.
        $second = $this->tasks->update($this->max, self::id($task), ['preparer_id' => '16']);
        self::assertSame(['16'], $second['preparer_ids']);
        $this->tasks->update($this->max, self::id($task), ['preparer_ids' => ['11', '16']]);
        $changed = $this->tasks->update($this->max, self::id($task), ['preparer_id' => '12', 'reviewer_id' => '']);
        self::assertSame([['12'], []], [$changed['preparer_ids'], $changed['reviewer_ids']]);
    }

    public function test_anyone_on_the_task_sees_it_and_nobody_else_does(): void
    {
        $task = self::id($this->make(['preparer_ids' => ['11', '16'], 'reviewer_ids' => ['13', '12']]));

        foreach ([$this->una, $this->ivy, $this->rae] as $actor) {
            self::assertSame(['Bank rec'], array_column($this->tasks->list($actor), 'name'), $actor->name);
            self::assertSame($task, $this->tasks->get($actor, $task)['id']);
        }
        self::assertSame([], $this->tasks->list($this->outsider));
        $this->expectException(TaskNotFound::class);
        $this->tasks->get($this->outsider, $task);
    }

    public function test_a_second_preparer_may_change_the_task_and_an_outsider_may_not(): void
    {
        $task = self::id($this->make(['preparer_ids' => ['11', '16']]));

        self::assertSame(40, $this->tasks->update($this->ivy, $task, ['progress' => 40])['progress']);
        $this->expectException(TaskNotFound::class);   // not visible to them, so not theirs to change
        $this->tasks->update($this->outsider, $task, ['progress' => 50]);
    }

    public function test_any_one_reviewer_marks_it_done_and_the_other_reviewers_need_not_sign(): void
    {
        $task = self::id($this->make(['preparer_ids' => ['11'], 'reviewer_ids' => ['13', '16']]));

        $done = $this->tasks->update($this->ivy, $task, ['status' => 'completed']);   // the second reviewer, alone

        self::assertSame(['completed', 100], [$done['status'], $done['progress']]);
    }

    public function test_a_preparer_who_is_not_a_reviewer_cannot_close_a_reviewed_task(): void
    {
        $task = self::id($this->make(['preparer_ids' => ['11', '16'], 'reviewer_ids' => ['13', '12']]));

        foreach ([$this->una, $this->ivy] as $preparer) {
            try {
                $this->tasks->update($preparer, $task, ['status' => 'completed']);
                self::fail('Only a reviewer closes it.');
            } catch (TaskForbidden $e) {
                self::assertSame('Only the reviewer can mark this task done. Use Send for review instead.', $e->getMessage());
            }
        }
        // 100% from a preparer asks the reviewers instead of closing it.
        $asked = $this->tasks->update($this->una, $task, ['progress' => 100]);
        self::assertSame(['active', true], [$asked['status'], $asked['review_due'] ?? false]);
    }

    public function test_one_save_cannot_swap_the_reviewers_and_close_the_task(): void
    {
        $task = self::id($this->make(['preparer_ids' => ['11'], 'reviewer_ids' => ['13', '12']]));

        $this->expectException(TaskForbidden::class);
        $this->tasks->update($this->una, $task, ['reviewer_ids' => ['11'], 'status' => 'completed']);
    }

    public function test_a_reviewer_cannot_remove_themselves_and_close_the_task_in_one_save(): void
    {
        $task = self::id($this->make(['preparer_ids' => ['11'], 'reviewer_ids' => ['13', '16']]));

        $this->expectException(TaskForbidden::class);
        $this->tasks->update($this->rae, $task, ['reviewer_ids' => ['16'], 'status' => 'completed']);
    }

    public function test_a_task_with_no_reviewer_is_closed_by_any_editor(): void
    {
        $task = self::id($this->make(['preparer_ids' => ['11', '16']]));

        self::assertSame('completed', $this->tasks->update($this->ivy, $task, ['status' => 'completed'])['status']);
    }

    public function test_the_history_logs_the_names_on_the_task_before_and_after(): void
    {
        $task = self::id($this->make(['preparer_ids' => ['11'], 'reviewer_ids' => ['13']]));

        $this->tasks->update($this->max, $task, ['preparer_ids' => ['11', '16'], 'reviewer_ids' => []]);

        $rows = [];
        foreach ($this->tasks->history($this->max, $task) as $row) {
            if (in_array($row['field'], ['preparer', 'reviewer'], true)) {
                $rows[(string) $row['field']] = [$row['from_value'], $row['to_value']];
            }
        }
        ksort($rows);
        self::assertSame(['preparer' => ['Una User', 'Una User, Ivy User'], 'reviewer' => ['Rae Reviewer', null]], $rows);
        // The same people again is not a change.
        $before = count($this->tasks->history($this->max, $task));
        $this->tasks->update($this->max, $task, ['preparer_ids' => ['11', '16']]);
        self::assertCount($before, $this->tasks->history($this->max, $task));
    }

    public function test_the_lists_are_checked_as_the_single_ids_were(): void
    {
        $cases = [
            'not a member' => ['preparer_ids' => ['11', '99']],
            'not a list' => ['reviewer_ids' => '11'],
            'not an id' => ['preparer_ids' => ['11', 'una']],
            'too many' => ['preparer_ids' => array_map(static fn (int $i): string => (string) (100 + $i), range(1, 21))],
        ];
        foreach ($cases as $why => $data) {
            try {
                $this->make($data);
                self::fail($why . ' should be refused.');
            } catch (TaskInvalid) {
                self::assertCount(0, $this->tasks->list($this->max), $why);
            }
        }
    }

    public function test_the_record_keeps_the_single_ids_as_the_first_of_each_list(): void
    {
        $now = new \DateTimeImmutable('2026-10-07 01:00:00', new \DateTimeZone('UTC'));
        $task = new TaskRecord('a', 'T', '', '', '', 'routine', '', null, null, 11, 12, 'active', 0, null, false, false, null, null, null, null, $now, $now);

        self::assertSame([[11], [12]], [$task->preparerUserIds, $task->reviewerUserIds], 'a record built the old way');
        $task->setPreparers([16, 11, 16]);
        self::assertSame([[16, 11], 16], [$task->preparerUserIds, $task->preparerUserId]);
        self::assertTrue($task->isOwnedBy(11));
        self::assertTrue($task->isOwnedBy(12));
        self::assertFalse($task->isOwnedBy(99));
        $task->setReviewers([]);
        self::assertNull($task->reviewerUserId);
        self::assertSame([16, 11], $task->everyone());
    }

    public function test_a_new_task_is_unread_for_every_assignee_but_its_creator_and_marks_follow_the_same_visibility(): void
    {
        $this->store->setUnreadCutoff(new \DateTimeImmutable('2000-01-01 00:00:00'));
        $id = self::id($this->tasks->create($this->una, ['name' => 'Shared', 'preparer_ids' => ['11', '16'], 'reviewer_ids' => ['13']]));

        $unread = static fn (array $rows): array => array_column($rows, 'is_unread', 'name');
        self::assertSame(['Shared' => false], $unread($this->tasks->list($this->una)), 'the creator wrote it');
        self::assertSame(['Shared' => true], $unread($this->tasks->list($this->ivy)), 'a second preparer');
        self::assertSame(['Shared' => true], $unread($this->tasks->list($this->rae)), 'a reviewer');

        self::assertFalse($this->tasks->setUserState($this->ivy, $id, ['read' => true])['is_unread']);
        self::assertTrue($this->tasks->setUserState($this->ivy, $id, ['pinned' => true])['pinned_at'] !== null);
        self::assertSame(['Shared' => true], $unread($this->tasks->list($this->rae)), 'one person\'s mark is theirs alone');
        $this->expectException(TaskNotFound::class);
        $this->tasks->setUserState($this->outsider, $id, ['read' => true]);
    }
}
