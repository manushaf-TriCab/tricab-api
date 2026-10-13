<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskMailLinks;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingFinanceMailTransport;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Domain\TaskUnavailable;
use TriCab\Core\Finance\Task\Service\TaskReviewRequestNotifier;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** Send for review emails the reviewer (13 Oct 2026). In-memory twins only: nothing sends mail. */
final class TaskReviewRequestNotifierTest extends TestCase
{
    private InMemoryFinanceTaskMemberDirectory $directory;

    private InMemoryTaskStore $store;

    private InMemoryFinanceMailLedger $ledger;

    private RecordingFinanceMailTransport $transport;

    private InMemoryTaskMailLinks $links;

    private TaskService $tasks;

    private TaskTime $time;

    private TaskReviewRequestNotifier $notifier;

    private TaskActor $una;

    private TaskActor $max;

    private TaskActor $rae;

    protected function setUp(): void
    {
        $this->directory = new InMemoryFinanceTaskMemberDirectory();
        $this->directory->add(11, 'Una User', 'una@example.test');
        $this->directory->add(12, 'Max Manager', 'max@example.test');
        $this->directory->add(13, 'Rae Member', 'rae@example.test');
        $this->directory->add(15, 'No Mail', '');
        $this->store = new InMemoryTaskStore();
        $this->time = new TaskTime(FixedClock::utc('2026-10-13 01:00:00'), 'Australia/Melbourne');
        $this->tasks = new TaskService($this->store, $this->store, $this->directory, $this->time);
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->transport = new RecordingFinanceMailTransport($this->ledger);
        $this->links = new InMemoryTaskMailLinks();
        $this->notifier = $this->notifier();
        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->rae = new TaskActor(13, 'Rae Member', 'rae@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE]);
    }

    private function notifier(string $from = 'finance@example.test', bool $sendAsUser = false, ?FinanceMailTransport $transport = null): TaskReviewRequestNotifier
    {
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('m1', 'finance@example.test', 'Finance', '', true, ['TASK' => ['read' => false, 'send' => true]])]);
        $mail = new FinanceMailService($registry, $this->ledger, $transport ?? $this->transport);

        return new TaskReviewRequestNotifier($this->directory, $mail, $this->links, $this->time, $from, $sendAsUser);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function task(array $data = []): array
    {
        return $this->tasks->create($this->max, $data + ['name' => 'Bank rec', 'preparer_id' => '11', 'reviewer_id' => '12', 'due_date' => '2026-10-20']);
    }

    public function test_the_preparer_sends_it_to_the_reviewer_and_every_press_sends_again(): void
    {
        $task = $this->task();
        self::assertIsString($task['id']);

        self::assertSame(['status' => TaskReviewRequestNotifier::SENT], $this->notifier->request($this->una, $task));

        $message = $this->transport->sent[0];
        self::assertSame('finance@example.test', $message->mailbox);
        self::assertFalse($message->ownMailbox);
        self::assertSame(['max@example.test'], $message->to);
        self::assertSame('Ready for your review: Bank rec', $message->subject);
        self::assertStringContainsString('Una User has sent <strong>Bank rec</strong> in General to you for review (due 20 Oct 2026).', $message->body);
        self::assertStringStartsWith('review-request:' . $task['id'] . ':', (string) $message->idempotencyKey);
        self::assertSame([TaskMailLink::KIND_TASK_EMAIL], array_map(static fn (TaskMailLink $l): string => $l->kind, $this->links->forTask($task['id'])));

        // A second press is a new email, not a repeat of the first.
        self::assertSame(['status' => TaskReviewRequestNotifier::SENT], $this->notifier->request($this->una, $task));
        self::assertCount(2, $this->transport->sent);
        self::assertNotSame($this->transport->sent[0]->idempotencyKey, $this->transport->sent[1]->idempotencyKey);

        // Someone who may change any task may send it too.
        $other = $this->task(['reviewer_id' => '13']);
        self::assertSame(['status' => TaskReviewRequestNotifier::SENT], $this->notifier->request($this->max, $other));
        self::assertSame(['rae@example.test'], $this->transport->sent[2]->to);
    }

    public function test_with_send_as_user_it_goes_from_the_persons_own_address(): void
    {
        $result = $this->notifier('finance@example.test', true)->request($this->una, $this->task());

        self::assertSame(['status' => TaskReviewRequestNotifier::SENT], $result);
        self::assertSame('una@example.test', $this->transport->sent[0]->mailbox);
        self::assertTrue($this->transport->sent[0]->ownMailbox);
    }

    public function test_it_is_refused_unless_the_preparer_asks_for_a_live_open_task_with_a_reviewer_who_has_mail(): void
    {
        $binned = $this->task();
        self::assertIsString($binned['id']);
        $this->tasks->delete($this->max, $binned['id']);
        $cases = [
            'not the preparer' => [$this->rae, $this->task(), TaskForbidden::class, 'Only the preparer can send this task for review'],
            'done' => [$this->una, $this->task(['status' => 'completed']), TaskInvalid::class, 'This task is done or in the Bin'],
            'in the Bin' => [$this->una, $this->tasks->list($this->max, true)[0], TaskInvalid::class, 'This task is done or in the Bin'],
            'no reviewer' => [$this->una, $this->task(['reviewer_id' => null]), TaskInvalid::class, 'This task has no reviewer. Choose one first.'],
            'the reviewer asks' => [$this->una, $this->task(['reviewer_id' => '11']), TaskInvalid::class, 'You are this task\'s reviewer: mark it Done when it is ready'],
            'the reviewer has no email' => [$this->una, $this->task(['reviewer_id' => '15']), TaskInvalid::class, 'The reviewer has no email address'],
        ];
        foreach ($cases as $why => [$actor, $task, $class, $message]) {
            try {
                $this->notifier->request($actor, $task);
                self::fail('Expected a refusal: ' . $why);
            } catch (TaskFailure $e) {
                self::assertInstanceOf($class, $e, $why);
                self::assertSame($message, $e->getMessage(), $why);
            }
        }

        // No sender: no mailbox configured, or sending as a person with no address.
        foreach ([[$this->notifier(''), $this->una, 'No sending mailbox is configured'], [$this->notifier('finance@example.test', true), new TaskActor(11, 'Una User', '', $this->una->permissions), 'Your account has no email address to send from']] as [$notifier, $actor, $message]) {
            try {
                $notifier->request($actor, $this->task());
                self::fail('Expected unavailable: ' . $message);
            } catch (TaskUnavailable $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
        self::assertSame([], $this->transport->sent);
        self::assertSame([], $this->ledger->all());
    }

    public function test_with_mail_switched_off_it_is_suppressed_and_still_linked(): void
    {
        $task = $this->task();
        self::assertIsString($task['id']);

        $result = $this->notifier('finance@example.test', false, new DisabledFinanceMailTransport())->request($this->una, $task);

        self::assertSame(['status' => TaskReviewRequestNotifier::SUPPRESSED], $result);
        self::assertSame(['suppressed'], array_map(static fn ($d): string => $d->status, $this->ledger->all()));
        self::assertCount(1, $this->links->forTask($task['id']));
    }
}
