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
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Service\TaskDueDateRequestNotifier;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** The preparer hears when a due date is requested of them (12 Oct 2026). In-memory twins only: nothing sends mail. */
final class TaskDueDateRequestNotifierTest extends TestCase
{
    private InMemoryTaskStore $store;

    private InMemoryFinanceMailLedger $ledger;

    private RecordingFinanceMailTransport $transport;

    private InMemoryTaskMailLinks $links;

    private InMemoryFinanceTaskMemberDirectory $directory;

    private TaskService $tasks;

    private TaskActor $manager;

    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->directory = new InMemoryFinanceTaskMemberDirectory();
        $this->directory->add(11, 'Una User', 'una@example.test');
        $this->directory->add(12, 'Mo Manager', 'mo@example.test');
        $this->directory->add(13, 'Ivy User', 'ivy@example.test');
        $this->store = new InMemoryTaskStore();
        $this->clock = FixedClock::utc('2026-10-05 21:00:00');
        $this->tasks = new TaskService($this->store, $this->store, $this->directory, new TaskTime($this->clock, 'Australia/Melbourne'));
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->transport = new RecordingFinanceMailTransport($this->ledger);
        $this->links = new InMemoryTaskMailLinks();
        $this->manager = new TaskActor(12, 'Mo Manager', 'mo@example.test', [Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE]);
    }

    private function notifier(?FinanceMailTransport $transport = null, string $from = 'finance@example.test'): TaskDueDateRequestNotifier
    {
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('m1', 'finance@example.test', 'Finance', '', true, ['TASK' => ['read' => false, 'send' => true]])]);

        return new TaskDueDateRequestNotifier(
            $this->directory,
            new FinanceMailService($registry, $this->ledger, $transport ?? $this->transport),
            $this->ledger, $this->links, new TaskTime($this->clock, 'Australia/Melbourne'), $from,
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function task(array $data): array
    {
        return $this->tasks->create($this->manager, ['name' => 'Bank reconciliation'] + $data);
    }

    public function test_the_preparer_is_emailed_once_when_a_due_date_is_requested(): void
    {
        $task = $this->task(['preparer_id' => '11', 'due_date_requested' => true]);

        self::assertSame(TaskDueDateRequestNotifier::SENT, $this->notifier()->notify($this->manager, $task));

        self::assertCount(1, $this->transport->sent);
        $message = $this->transport->sent[0];
        self::assertSame(['una@example.test'], $message->to);
        self::assertSame('finance@example.test', $message->mailbox);
        self::assertSame('Please set a due date: Bank reconciliation', $message->subject);
        self::assertStringContainsString('Mo Manager has asked you to set a due date for <strong>Bank reconciliation</strong>', $message->body);
        self::assertIsString($task['id']);
        self::assertSame([TaskMailLink::KIND_TASK_EMAIL], array_map(static fn (TaskMailLink $l): string => $l->kind, $this->links->forTask($task['id'])));

        // Saving the task again does not send it again.
        self::assertSame(TaskDueDateRequestNotifier::SKIPPED, $this->notifier()->notify($this->manager, $task));
        self::assertCount(1, $this->transport->sent);
    }

    public function test_a_new_preparer_of_a_requested_task_is_told_too(): void
    {
        $task = $this->task(['preparer_id' => '11', 'due_date_requested' => true]);
        $this->notifier()->notify($this->manager, $task);
        self::assertIsString($task['id']);
        $task = $this->tasks->update($this->manager, $task['id'], ['preparer_id' => '13']);

        self::assertSame(TaskDueDateRequestNotifier::SENT, $this->notifier()->notify($this->manager, $task));
        self::assertSame([['una@example.test'], ['ivy@example.test']], array_map(static fn (FinanceMailMessage $m): array => $m->to, $this->transport->sent));
    }

    public function test_nothing_is_sent_when_no_request_stands(): void
    {
        $notifier = $this->notifier();
        $cases = [
            'not requested' => $this->task(['preparer_id' => '11']),
            'no preparer' => $this->task(['due_date_requested' => true]),
            'the preparer asked themselves' => $this->task(['preparer_id' => '12', 'due_date_requested' => true]),
            'completed' => $this->task(['preparer_id' => '11', 'due_date_requested' => true, 'status' => 'completed']),
        ];
        foreach ($cases as $why => $task) {
            self::assertSame(TaskDueDateRequestNotifier::NOT_NEEDED, $notifier->notify($this->manager, $task), $why);
        }
        $dated = $this->task(['preparer_id' => '11', 'due_date_requested' => true]);
        self::assertSame(TaskDueDateRequestNotifier::NOT_NEEDED, $notifier->notify($this->manager, ['due_date' => '2026-10-20'] + $dated), 'already dated');
        self::assertSame(TaskDueDateRequestNotifier::NOT_NEEDED, $this->notifier(null, '')->notify($this->manager, $dated), 'no sender');
        self::assertSame([], $this->transport->sent);
    }

    public function test_a_failed_send_is_reported_not_thrown(): void
    {
        $task = $this->task(['preparer_id' => '11', 'due_date_requested' => true]);
        $failing = new class () implements FinanceMailTransport {
            public function deliver(FinanceMailMessage $message): void
            {
                throw new FinanceMailTransportFailed('Mailbox unavailable');
            }
        };

        self::assertSame(TaskDueDateRequestNotifier::FAILED, $this->notifier($failing)->notify($this->manager, $task));
        // A later save tries again.
        self::assertSame(TaskDueDateRequestNotifier::SENT, $this->notifier()->notify($this->manager, $task));
    }
}
