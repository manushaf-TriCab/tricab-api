<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskMailLinks;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingFinanceMailTransport;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Service\TaskReviewRequestNotifier;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** The reviewer hears when a task is sent for review (13 Oct 2026). In-memory twins only: nothing sends mail. */
final class TaskReviewRequestNotifierTest extends TestCase
{
    private InMemoryTaskStore $store;

    private InMemoryFinanceMailLedger $ledger;

    private RecordingFinanceMailTransport $transport;

    private InMemoryTaskMailLinks $links;

    private TaskService $tasks;

    private TaskReviewRequestNotifier $notifier;

    private FixedClock $clock;

    private TaskActor $una;

    private TaskActor $max;

    protected function setUp(): void
    {
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(11, 'Una User', 'una@example.test');
        $directory->add(12, 'Max Manager', 'max@example.test');
        $this->store = new InMemoryTaskStore();
        $this->clock = FixedClock::utc('2026-10-13 01:00:00');
        $time = new TaskTime($this->clock, 'Australia/Melbourne');
        $this->tasks = new TaskService($this->store, $this->store, $directory, $time);
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->transport = new RecordingFinanceMailTransport($this->ledger);
        $this->links = new InMemoryTaskMailLinks();
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('m1', 'finance@example.test', 'Finance', '', true, ['TASK' => ['read' => false, 'send' => true]])]);
        $this->notifier = new TaskReviewRequestNotifier($this->store, $directory, new FinanceMailService($registry, $this->ledger, $this->transport), $this->ledger, $this->links, $time, 'finance@example.test');
        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE]);
    }

    public function test_the_reviewer_is_emailed_once_per_move_into_ready_for_review(): void
    {
        $task = $this->tasks->create($this->max, ['name' => 'Bank rec', 'preparer_id' => '11', 'reviewer_id' => '12', 'due_date' => '2026-10-20']);
        self::assertIsString($task['id']);
        $id = $task['id'];

        $this->clock->advance('+1 second');
        $sent = $this->tasks->update($this->una, $id, ['status' => 'ready_for_review']);
        self::assertSame(TaskReviewRequestNotifier::SENT, $this->notifier->notify($this->una, $sent));
        self::assertSame(TaskReviewRequestNotifier::SKIPPED, $this->notifier->notify($this->una, $this->tasks->update($this->una, $id, ['notes' => 'see tab 2'])));

        $message = $this->transport->sent[0];
        self::assertSame(['max@example.test'], $message->to);
        self::assertSame('Ready for your review: Bank rec', $message->subject);
        self::assertStringContainsString('Una User has sent <strong>Bank rec</strong> to you for review (due 20 Oct 2026).', $message->body);
        self::assertSame([TaskMailLink::KIND_TASK_EMAIL], array_map(static fn (TaskMailLink $l): string => $l->kind, $this->links->forTask($id)));

        // Sent back, then resubmitted: a new move, a new email.
        $this->clock->advance('+1 second');
        $this->tasks->update($this->max, $id, ['status' => 'in_progress']);
        $this->clock->advance('+1 second');
        $again = $this->tasks->update($this->una, $id, ['status' => 'ready_for_review']);
        self::assertSame(TaskReviewRequestNotifier::SENT, $this->notifier->notify($this->una, $again));
        self::assertCount(2, $this->transport->sent);
    }

    public function test_nothing_is_sent_when_no_review_is_asked_for(): void
    {
        $cases = [
            'not ready' => $this->tasks->create($this->max, ['name' => 'A', 'preparer_id' => '11', 'reviewer_id' => '12']),
            'no reviewer' => $this->tasks->create($this->max, ['name' => 'B', 'preparer_id' => '11', 'status' => 'ready_for_review']),
            'the reviewer sent it' => $this->tasks->create($this->max, ['name' => 'C', 'reviewer_id' => '12', 'status' => 'ready_for_review']),
        ];
        foreach ($cases as $why => $task) {
            self::assertSame(TaskReviewRequestNotifier::NOT_NEEDED, $this->notifier->notify($this->max, $task), $why);
        }
        self::assertSame([], array_map(static fn (FinanceMailMessage $m): array => $m->to, $this->transport->sent));
    }
}
