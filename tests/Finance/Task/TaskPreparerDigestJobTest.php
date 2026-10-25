<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskMailLinks;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingFinanceMailTransport;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Service\TaskPreparerDigestJob;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** Each preparer's own 08:00 email (12 Oct 2026) on the in-memory twins and a recording transport: nothing sends mail. */
final class TaskPreparerDigestJobTest extends TestCase
{
    /** 08:00 on 6 Oct 2026 in Melbourne (AEDT) is 21:00 UTC on the 5th. */
    private const RUN_AT = '2026-10-05 21:00:00';

    private InMemoryTaskStore $store;

    private InMemoryFinanceMailLedger $ledger;

    private RecordingFinanceMailTransport $transport;

    private InMemoryTaskMailLinks $links;

    private InMemoryFinanceTaskMemberDirectory $directory;

    private TaskService $tasks;

    private TaskActor $manager;

    private FixedClock $clock;

    private TaskPreparerDigestJob $job;

    protected function setUp(): void
    {
        $this->directory = new InMemoryFinanceTaskMemberDirectory();
        $this->directory->add(11, 'Una User', 'una@example.test');
        $this->directory->add(12, 'Mo Manager', 'mo@example.test');
        $this->directory->add(13, 'Ivy User', 'ivy@example.test');
        $this->store = new InMemoryTaskStore();
        $this->clock = FixedClock::utc(self::RUN_AT);
        $this->tasks = new TaskService($this->store, $this->store, $this->directory, new TaskTime($this->clock, 'Australia/Melbourne'));
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->transport = new RecordingFinanceMailTransport($this->ledger);
        $this->links = new InMemoryTaskMailLinks();
        $this->manager = new TaskActor(12, 'Mo Manager', 'mo@example.test', [Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_DELETE]);
        $this->job = $this->jobWith($this->transport, 'Finance@Example.test');
    }

    private function jobWith(FinanceMailTransport $transport, string $from): TaskPreparerDigestJob
    {
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('m1', 'finance@example.test', 'Finance', '', true, ['TASK' => ['read' => false, 'send' => true]])]);

        return new TaskPreparerDigestJob(
            $this->store, $this->directory,
            new FinanceMailService($registry, $this->ledger, $transport),
            $this->ledger, $this->links, new TaskTime($this->clock, 'Australia/Melbourne'), $from,
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::RUN_AT, new DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $data */
    private function task(string $name, ?string $due, array $data = []): string
    {
        $t = $this->tasks->create($this->manager, ['name' => $name, 'due_date' => $due] + $data);
        self::assertIsString($t['id']);

        return $t['id'];
    }

    public function test_each_preparer_gets_only_their_own_due_tasks(): void
    {
        $late = $this->task('Una late', '2026-10-01', ['preparer_id' => '11', 'reviewer_id' => '12']);
        $today = $this->task('Una today', '2026-10-06', ['preparer_id' => '11']);
        $this->task('Una later', '2026-10-20', ['preparer_id' => '11']);
        $this->task('Una done', '2026-10-01', ['preparer_id' => '11', 'status' => 'completed']);
        $this->task('Ivy late', '2026-10-02', ['preparer_id' => '13']);
        $this->task('Nobody late', '2026-10-02');

        $facts = $this->job->run($this->now());

        self::assertSame(['status' => 'sent', 'sent' => 2, 'failed' => 0, 'suppressed' => 0, 'skipped' => 0, 'noAddress' => 0, 'preparers' => 2], $facts);
        $byTo = [];
        foreach ($this->transport->sent as $message) {
            $byTo[$message->to[0]] = $message;
        }
        self::assertSame(['una@example.test', 'ivy@example.test'], array_keys($byTo));
        $una = $byTo['una@example.test'];
        self::assertSame('finance@example.test', $una->mailbox);
        self::assertSame('Your Tri-Task tasks — 1 overdue, 1 due today (2026-10-06)', $una->subject);
        self::assertStringContainsString('<h2 style="font-size:20px;margin:0 0 4px;">Your Tri-Task tasks</h2>', $una->body);
        self::assertStringContainsString('Una late', $una->body);
        self::assertStringContainsString('Una today', $una->body);
        self::assertStringContainsString('Mo Manager', $una->body);
        self::assertStringNotContainsString('Una later', $una->body);
        self::assertStringNotContainsString('Ivy late', $una->body);
        self::assertStringNotContainsString('Nobody late', $una->body);

        $reminders = array_values(array_filter($this->links->all(), static fn (TaskMailLink $l): bool => $l->kind === TaskMailLink::KIND_REMINDER));
        self::assertCount(3, $reminders);   // Una's two tasks and Ivy's one
        self::assertCount(1, $this->links->forTask($late));
        self::assertCount(1, $this->links->forTask($today));
    }

    public function test_nothing_due_sends_nothing(): void
    {
        $this->task('Later', '2026-10-20', ['preparer_id' => '11']);
        $this->task('Unassigned late', '2026-10-01');

        self::assertSame(['status' => 'nothing_due', 'sent' => 0, 'preparers' => 0], $this->job->run($this->now()));
        self::assertSame([], $this->transport->sent);
    }

    public function test_a_second_run_the_same_day_sends_nothing_more(): void
    {
        $this->task('Una late', '2026-10-01', ['preparer_id' => '11']);
        $this->job->run($this->now());

        $facts = $this->job->run($this->now());

        self::assertSame(1, $facts['skipped']);
        self::assertSame(0, $facts['sent']);
        self::assertCount(1, $this->transport->sent);
    }

    public function test_a_preparer_who_is_no_longer_a_member_is_counted_not_mailed(): void
    {
        $this->task('Una late', '2026-10-01', ['preparer_id' => '11']);
        $this->directory->remove(11);

        $facts = $this->job->run($this->now());

        self::assertSame(1, $facts['noAddress']);
        self::assertSame([], $this->transport->sent);
    }

    public function test_one_failing_address_does_not_stop_the_rest_and_fails_the_run(): void
    {
        $this->task('Una late', '2026-10-01', ['preparer_id' => '11']);
        $this->task('Ivy late', '2026-10-01', ['preparer_id' => '13']);
        $selective = new class () implements FinanceMailTransport {
            /** @var list<string> */
            public array $to = [];

            public function deliver(FinanceMailMessage $message): void
            {
                if ($message->to[0] === 'una@example.test') {
                    throw new FinanceMailTransportFailed('Mailbox unavailable');
                }
                $this->to[] = $message->to[0];
            }
        };

        try {
            $this->jobWith($selective, 'finance@example.test')->run($this->now());
            self::fail('A failed send should fail the run.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('1 of 2 preparer emails failed (1 sent', $e->getMessage());
        }
        self::assertSame(['ivy@example.test'], $selective->to);
    }

    public function test_no_sender_fails_the_run(): void
    {
        $this->task('Una late', '2026-10-01', ['preparer_id' => '11']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FINANCE_TASK_MAIL_FROM');
        $this->jobWith($this->transport, '  ')->run($this->now());
    }

    public function test_a_task_with_several_preparers_is_in_each_ones_own_email(): void
    {
        $this->task('Shared late', '2026-10-01', ['preparer_ids' => ['11', '13']]);
        $this->task('Una only', '2026-10-06', ['preparer_ids' => ['11']]);

        $facts = $this->job->run($this->now());

        self::assertSame(2, $facts['sent']);
        self::assertSame(2, $facts['preparers']);
        $byTo = [];
        foreach ($this->transport->sent as $message) {
            $byTo[$message->to[0]] = $message->body;
        }
        self::assertStringContainsString('Shared late', $byTo['una@example.test']);
        self::assertStringContainsString('Una only', $byTo['una@example.test']);
        self::assertStringContainsString('Shared late', $byTo['ivy@example.test']);
        self::assertStringNotContainsString('Una only', $byTo['ivy@example.test']);
        self::assertStringContainsString('Una User, Ivy User', $byTo['ivy@example.test'], 'the Preparer column names everyone');
    }
}
