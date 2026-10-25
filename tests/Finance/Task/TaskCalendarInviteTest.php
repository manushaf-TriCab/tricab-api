<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Access\InMemoryFinanceAccessScopeReader;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskCalendarInvites;
use TriCab\Api\Finance\Task\InMemoryTaskMailLinks;
use TriCab\Api\Finance\Task\InMemoryTaskReportRecipientStore;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingFinanceMailTransport;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;
use TriCab\Core\Finance\Task\Service\TaskCalendarInviteService;
use TriCab\Core\Finance\Task\Service\TaskMailService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** Calendar invites on the in-memory twins and a recording transport; {@see AzureSqlTaskCalendarInviteTest} runs the same on SQL. */
final class TaskCalendarInviteTest extends TestCase
{
    use TaskCalendarInviteScenarios;

    private InMemoryTaskMailLinks $links;

    private InMemoryFinanceMailLedger $ledger;

    protected function setUp(): void
    {
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(11, 'Una User', 'una@example.test');
        $directory->add(12, 'Max Manager', 'max@example.test');
        $directory->add(13, 'Rae Reviewer', 'rae@example.test');
        $directory->add(14, 'Out Of Mail', '');
        $directory->add(16, 'Ivy User', 'ivy@example.test');
        $store = new InMemoryTaskStore();
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:00:00.5'), 'Australia/Melbourne');
        $this->store = new InMemoryTaskCalendarInvites();
        $this->tasks = new TaskService($store, $store, $directory, $time, null, $this->store);
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->transport = new RecordingFinanceMailTransport($this->ledger);
        $this->links = new InMemoryTaskMailLinks();
        $registry = new InMemoryFinanceMailboxRegistry([]);
        $sender = new FinanceMailService($registry, $this->ledger, $this->transport, null, null);
        $mail = new TaskMailService($this->tasks, $directory, new InMemoryTaskReportRecipientStore(), $sender, $this->ledger, $registry, new InMemoryFinanceAccessScopeReader(), $this->links, $time, 'finance@example.test', true);
        $this->invites = new TaskCalendarInviteService($this->tasks, $mail, $store, $directory, $sender, $this->links, $this->store, $time);
        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_UPDATE_OWN, Module::PERMISSION_TASK_TASKS_DELETE_OWN, Module::PERMISSION_TASK_MAIL_SEND];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE]);
    }

    public function test_each_send_leaves_a_calendar_invite_link_to_the_ledger_in_the_tasks_mail_history(): void
    {
        $id = $this->make();
        $this->invites->send($this->una, [$id]);
        $this->invites->send($this->una, [$id]);

        $links = $this->links->forTask($id);
        self::assertSame([TaskMailLink::KIND_CALENDAR_INVITE, TaskMailLink::KIND_CALENDAR_INVITE], array_map(static fn (TaskMailLink $l): string => $l->kind, $links));
        $delivery = $this->ledger->findById($links[0]->deliveryId);
        self::assertSame(['sent', 'una@example.test'], [$delivery?->status, $delivery?->mailbox]);
    }

    public function test_with_mail_switched_off_nothing_counts_as_sent_and_no_invite_is_recorded(): void
    {
        $directory = new InMemoryFinanceTaskMemberDirectory();
        $directory->add(11, 'Una User', 'una@example.test');
        $store = new InMemoryTaskStore();
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:00:00.5'), 'Australia/Melbourne');
        $invites = new InMemoryTaskCalendarInvites();
        $tasks = new TaskService($store, $store, $directory, $time, null, $invites);
        $ledger = new InMemoryFinanceMailLedger();
        $registry = new InMemoryFinanceMailboxRegistry([]);
        $sender = new FinanceMailService($registry, $ledger, new \TriCab\Api\Finance\Mail\DisabledFinanceMailTransport(), null, null);
        $mail = new TaskMailService($tasks, $directory, new InMemoryTaskReportRecipientStore(), $sender, $ledger, $registry, new InMemoryFinanceAccessScopeReader(), new InMemoryTaskMailLinks(), $time, '', true);
        $service = new TaskCalendarInviteService($tasks, $mail, $store, $directory, $sender, new InMemoryTaskMailLinks(), $invites, $time);
        $created = $tasks->create($this->max, ['name' => 'Bank rec', 'due_date' => '2026-10-30', 'preparer_ids' => ['11']]);

        $out = $service->send($this->max, [$created['id']]);

        self::assertSame([0, 0, 1], [$out['sent'], $out['skipped'], $out['failed']]);
        self::assertSame(TaskMailService::MAIL_OFF, $out['results'][0]['error']);
        self::assertSame([], $invites->all());
    }
}
