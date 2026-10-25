<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Service\TaskService;

/**
 * Unread, Flag and Pin, each one per person (Nick Han, 23 Oct 2026), run against the in-memory twin and the SQL
 * store alike. The using test supplies the service, three people (`una` sees only her own tasks, `max` and `reader`
 * see all), a way to make a task and a way to set where "unread by default" begins.
 */
trait TaskUserStateScenarios
{
    private TaskService $tasks;

    private TaskActor $una;

    private TaskActor $max;

    private TaskActor $reader;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    abstract private function make(TaskActor $by, string $name, array $data = []): array;

    abstract private function cutoffAt(DateTimeImmutable $cutoff): void;

    abstract private function tick(): void;

    private static function taskId(mixed $row): string
    {
        self::assertIsArray($row);
        self::assertIsString($row['id']);

        return $row['id'];
    }

    /**
     * @return array<string, mixed> the task as one person's list shows it
     */
    private function row(TaskActor $who, string $id): array
    {
        foreach ($this->tasks->list($who) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail('The task is not in ' . $who->name . "'s list");
    }

    public function test_a_new_task_is_unread_for_everyone_who_can_see_it_except_its_creator(): void
    {
        $this->cutoffAt(new DateTimeImmutable('2000-01-01 00:00:00'));
        $id = self::taskId($this->make($this->max, 'Bank rec', ['preparer_id' => 11]));

        self::assertFalse($this->row($this->max, $id)['is_unread'], 'the creator wrote it');
        self::assertTrue($this->row($this->una, $id)['is_unread']);
        self::assertTrue($this->row($this->reader, $id)['is_unread']);
        self::assertFalse($this->row($this->una, $id)['is_flagged']);
        self::assertNull($this->row($this->una, $id)['pinned_at']);
    }

    public function test_tasks_made_before_the_cutoff_count_as_read_so_nobody_wakes_to_a_wall_of_unread(): void
    {
        $id = self::taskId($this->make($this->max, 'Old', ['preparer_id' => 11]));
        $this->cutoffAt(new DateTimeImmutable('2099-01-01 00:00:00'));

        self::assertFalse($this->row($this->una, $id)['is_unread']);
        self::assertFalse($this->row($this->reader, $id)['is_unread']);
    }

    public function test_opening_a_task_marks_it_read_for_that_person_only_and_mark_as_unread_undoes_it(): void
    {
        $this->cutoffAt(new DateTimeImmutable('2000-01-01 00:00:00'));
        $id = self::taskId($this->make($this->max, 'Bank rec', ['preparer_id' => 11]));

        $opened = $this->tasks->setUserState($this->una, $id, ['read' => true]);
        self::assertFalse($opened['is_unread']);
        self::assertFalse($this->row($this->una, $id)['is_unread']);
        self::assertTrue($this->row($this->reader, $id)['is_unread'], "Una's reading is Una's");

        // Opening it again changes nothing.
        self::assertFalse($this->tasks->setUserState($this->una, $id, ['read' => true])['is_unread']);

        self::assertTrue($this->tasks->setUserState($this->una, $id, ['read' => false])['is_unread']);
        self::assertTrue($this->row($this->una, $id)['is_unread']);
        // The creator can mark their own task unread too.
        self::assertTrue($this->tasks->setUserState($this->max, $id, ['read' => false])['is_unread']);
        self::assertFalse($this->tasks->setUserState($this->max, $id, ['read' => true])['is_unread']);
    }

    public function test_an_old_task_can_be_marked_unread_and_a_flag_does_not_make_it_unread(): void
    {
        $id = self::taskId($this->make($this->max, 'Old', ['preparer_id' => 11]));
        $this->cutoffAt(new DateTimeImmutable('2099-01-01 00:00:00'));

        $flagged = $this->tasks->setUserState($this->una, $id, ['flagged' => true]);
        self::assertTrue($flagged['is_flagged']);
        self::assertFalse($flagged['is_unread'], 'a flag leaves the read state as it was');
        self::assertTrue($this->tasks->setUserState($this->una, $id, ['read' => false])['is_unread']);
        self::assertFalse($this->tasks->setUserState($this->una, $id, ['read' => true])['is_unread']);
    }

    public function test_a_flag_is_one_persons_and_toggles_off(): void
    {
        $id = self::taskId($this->make($this->max, 'Bank rec', ['preparer_id' => 11]));

        $this->tasks->setUserState($this->una, $id, ['flagged' => true]);
        self::assertTrue($this->row($this->una, $id)['is_flagged']);
        self::assertFalse($this->row($this->max, $id)['is_flagged']);
        self::assertFalse($this->row($this->reader, $id)['is_flagged']);

        $this->tasks->setUserState($this->una, $id, ['flagged' => false]);
        self::assertFalse($this->row($this->una, $id)['is_flagged']);
    }

    public function test_pins_are_one_persons_and_keep_the_order_they_were_pinned_in(): void
    {
        $a = self::taskId($this->make($this->max, 'A', ['preparer_id' => 11]));
        $b = self::taskId($this->make($this->max, 'B', ['preparer_id' => 11]));

        $this->tasks->setUserState($this->una, $b, ['pinned' => true]);
        $this->tick();
        $this->tasks->setUserState($this->una, $a, ['pinned' => true]);
        $this->tick();
        $first = $this->row($this->una, $b)['pinned_at'];
        $second = $this->row($this->una, $a)['pinned_at'];
        self::assertIsString($first);
        self::assertIsString($second);
        self::assertLessThan($second, $first);
        self::assertNull($this->row($this->max, $a)['pinned_at']);

        // Pinning an already pinned task keeps its place.
        self::assertSame($first, $this->tasks->setUserState($this->una, $b, ['pinned' => true])['pinned_at']);
        self::assertNull($this->tasks->setUserState($this->una, $b, ['pinned' => false])['pinned_at']);
        self::assertSame($second, $this->row($this->una, $a)['pinned_at']);
    }

    public function test_marking_changes_only_the_marks_named_and_never_the_task(): void
    {
        $this->cutoffAt(new DateTimeImmutable('2000-01-01 00:00:00'));
        $task = $this->make($this->max, 'Bank rec', ['preparer_id' => 11]);
        $id = self::taskId($task);
        $this->tick();

        $this->tasks->setUserState($this->una, $id, ['flagged' => true, 'pinned' => true]);
        $row = $this->tasks->setUserState($this->una, $id, ['read' => true]);

        self::assertTrue($row['is_flagged']);
        self::assertNotNull($row['pinned_at']);
        self::assertFalse($row['is_unread']);
        self::assertSame($task['updated_date'], $row['updated_date'], 'a mark is not a change to the task');
        self::assertCount(count($this->tasks->history($this->max, $id)), $this->tasks->history($this->una, $id));
    }

    public function test_the_other_task_calls_carry_the_callers_marks_too(): void
    {
        $this->cutoffAt(new DateTimeImmutable('2000-01-01 00:00:00'));
        $id = self::taskId($this->make($this->max, 'Bank rec', ['preparer_id' => 11]));
        $this->tasks->setUserState($this->una, $id, ['flagged' => true]);

        $got = $this->tasks->get($this->una, $id);
        self::assertTrue($got['is_flagged']);
        self::assertTrue($got['is_unread']);
        $updated = $this->tasks->update($this->una, $id, ['notes' => 'hello']);
        self::assertTrue($updated['is_flagged']);
        self::assertTrue($updated['is_unread']);
        $bulk = $this->tasks->bulkUpdate($this->una, [['id' => $id, 'notes' => 'again']]);
        self::assertTrue($bulk[0]['is_flagged']);
        self::assertFalse($this->tasks->get($this->max, $id)['is_flagged']);
    }

    public function test_bulk_marking_covers_every_task_and_is_all_or_nothing(): void
    {
        $this->cutoffAt(new DateTimeImmutable('2000-01-01 00:00:00'));
        $a = self::taskId($this->make($this->max, 'A', ['preparer_id' => 11]));
        $b = self::taskId($this->make($this->max, 'B', ['preparer_id' => 11]));
        $other = self::taskId($this->make($this->max, 'Not Una', ['preparer_id' => 12]));

        $rows = $this->tasks->bulkSetUserState($this->una, [$a, $b, $a], ['read' => true, 'flagged' => true, 'pinned' => true]);
        self::assertSame(['A', 'B'], array_column($rows, 'name'));
        self::assertSame([false, false], array_column($rows, 'is_unread'));
        self::assertSame([true, true], array_column($rows, 'is_flagged'));
        self::assertNotNull($this->row($this->una, $b)['pinned_at']);

        $rows = $this->tasks->bulkSetUserState($this->una, [$a, $b], ['read' => false, 'flagged' => false, 'pinned' => false]);
        self::assertSame([true, true], array_column($rows, 'is_unread'));
        self::assertSame([false, false], array_column($rows, 'is_flagged'));
        self::assertSame([null, null], array_column($rows, 'pinned_at'));

        try {
            $this->tasks->bulkSetUserState($this->una, [$a, $other], ['flagged' => true]);
            self::fail('Expected a refusal.');
        } catch (TaskNotFound) {
            self::assertFalse($this->row($this->una, $a)['is_flagged'], 'nothing was flagged');
        }
    }

    public function test_a_person_cannot_mark_a_task_they_may_not_see_and_it_is_a_404(): void
    {
        $other = self::taskId($this->make($this->max, 'Not Una', ['preparer_id' => 12]));

        $this->expectException(TaskNotFound::class);
        $this->tasks->setUserState($this->una, $other, ['flagged' => true]);
    }

    public function test_a_person_who_sees_every_task_may_mark_any_of_them(): void
    {
        $other = self::taskId($this->make($this->max, 'Not Una', ['preparer_id' => 12]));

        self::assertTrue($this->tasks->setUserState($this->reader, $other, ['flagged' => true])['is_flagged']);
    }

    public function test_the_marks_must_be_booleans_and_something_must_be_sent(): void
    {
        $id = self::taskId($this->make($this->max, 'A'));
        foreach ([[], ['read' => 'yes'], ['flagged' => 1], ['pinned' => null]] as $bad) {
            try {
                $this->tasks->setUserState($this->max, $id, $bad);
                self::fail('Expected a refusal for ' . json_encode($bad));
            } catch (TaskInvalid) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(TaskInvalid::class);
        $this->tasks->bulkSetUserState($this->max, [], ['read' => true]);
    }

    public function test_a_permanent_delete_takes_the_marks_with_it(): void
    {
        $id = self::taskId($this->make($this->max, 'Bank rec', ['preparer_id' => 11]));
        $this->tasks->setUserState($this->una, $id, ['flagged' => true, 'pinned' => true]);

        $this->tasks->delete($this->max, $id);
        $this->tasks->delete($this->max, $id, true);

        $this->expectException(TaskNotFound::class);
        $this->tasks->get($this->max, $id);
    }

    // ---- @mentions in the notes (Nick Han, 23 Oct 2026) ----

    private const UNA = '@[Una User](user:11)';

    private const RAE = '@[Rae Reader](user:13)';

    /** Nothing is unread by default, so only a mention can light a task up. */
    private function nothingUnreadByDefault(): void
    {
        $this->cutoffAt(new DateTimeImmutable('2099-01-01 00:00:00'));
    }

    public function test_a_mention_on_a_new_task_makes_it_unread_for_the_person_mentioned_only(): void
    {
        $this->nothingUnreadByDefault();
        $id = self::taskId($this->make($this->max, 'Bank rec', ['preparer_id' => 11, 'notes' => 'Chase ' . self::UNA . ' please']));

        self::assertTrue($this->row($this->una, $id)['is_unread']);
        self::assertFalse($this->row($this->reader, $id)['is_unread']);
        self::assertFalse($this->row($this->max, $id)['is_unread']);
        self::assertSame(['11'], $this->row($this->una, $id)['mentioned_ids']);
        self::assertSame(['11'], $this->row($this->max, $id)['mentioned_ids'], 'the mention is on the task, not per person');
    }

    public function test_a_mention_added_later_unreads_it_again_but_a_resave_or_a_removal_does_not(): void
    {
        $this->nothingUnreadByDefault();
        $id = self::taskId($this->make($this->max, 'Bank rec', ['preparer_id' => 11, 'notes' => 'Plain']));
        self::assertSame([], $this->row($this->una, $id)['mentioned_ids']);
        self::assertFalse($this->row($this->una, $id)['is_unread']);

        $this->tasks->update($this->max, $id, ['notes' => 'Plain ' . self::UNA]);
        self::assertTrue($this->row($this->una, $id)['is_unread']);

        $this->tasks->setUserState($this->una, $id, ['read' => true]);
        $this->tasks->update($this->max, $id, ['notes' => 'Plain ' . self::UNA . ' and more words']);
        self::assertFalse($this->row($this->una, $id)['is_unread'], 'the mention was already there');

        $this->tasks->update($this->max, $id, ['notes' => 'No one now']);
        self::assertFalse($this->row($this->una, $id)['is_unread'], 'removing a mention does not change read state');
        self::assertSame([], $this->row($this->una, $id)['mentioned_ids']);

        $this->tasks->setUserState($this->una, $id, ['read' => false]);
        $this->tasks->update($this->max, $id, ['notes' => 'Still no one']);
        self::assertTrue($this->row($this->una, $id)['is_unread'], 'an unread task stays unread');
    }

    public function test_a_mention_added_through_a_bulk_update_unreads_it_too(): void
    {
        $this->nothingUnreadByDefault();
        $id = self::taskId($this->make($this->max, 'Bank rec', ['preparer_id' => 11]));

        $this->tasks->bulkUpdate($this->max, [['id' => $id, 'notes' => self::UNA . ' ' . self::RAE]]);

        self::assertTrue($this->row($this->una, $id)['is_unread']);
        self::assertTrue($this->row($this->reader, $id)['is_unread']);
        self::assertSame(['11', '13'], $this->row($this->max, $id)['mentioned_ids']);
    }

    public function test_mentioning_yourself_does_nothing(): void
    {
        $this->nothingUnreadByDefault();
        $id = self::taskId($this->make($this->max, 'Bank rec', ['preparer_id' => 11]));
        $this->tasks->setUserState($this->una, $id, ['read' => true]);

        $this->tasks->update($this->una, $id, ['notes' => 'Note to self ' . self::UNA]);

        self::assertFalse($this->row($this->una, $id)['is_unread']);
        self::assertSame(['11'], $this->row($this->una, $id)['mentioned_ids'], 'it still reads as a mention for "Mentions me"');
    }

    public function test_someone_who_cannot_see_the_task_is_not_made_to_see_it(): void
    {
        $this->nothingUnreadByDefault();
        $id = self::taskId($this->make($this->max, 'Private', ['notes' => self::UNA]));

        self::assertSame([], array_column($this->tasks->list($this->una), 'id'), 'a mention does not widen who sees the task');
        try {
            $this->tasks->get($this->una, $id);
            self::fail('Una must not see it');
        } catch (TaskNotFound) {
            $this->addToAssertionCount(1);
        }
        // She was not told, so when she is put on the task later it is not unread because of the old mention.
        $this->tasks->update($this->max, $id, ['preparer_id' => 11]);
        self::assertFalse($this->row($this->una, $id)['is_unread']);
    }

    public function test_a_manager_can_be_mentioned_on_a_task_they_are_not_on(): void
    {
        $this->nothingUnreadByDefault();
        $id = self::taskId($this->make($this->una, 'Una task', ['preparer_id' => 11, 'notes' => self::RAE]));

        self::assertTrue($this->row($this->reader, $id)['is_unread']);
    }

    public function test_an_unknown_person_in_a_token_is_ignored_and_a_task_without_mentions_has_none(): void
    {
        $this->nothingUnreadByDefault();
        $id = self::taskId($this->make($this->max, 'Odd', ['notes' => 'Hi @[Ghost](user:999) and @[Una User](user:abc) and @Una']));

        self::assertSame(['999'], $this->row($this->max, $id)['mentioned_ids']);
        $plain = self::taskId($this->make($this->max, 'Plain', ['notes' => 'Just words']));
        self::assertSame([], $this->row($this->max, $plain)['mentioned_ids']);
        self::assertSame('Just words', $this->row($this->max, $plain)['notes']);
    }
}
