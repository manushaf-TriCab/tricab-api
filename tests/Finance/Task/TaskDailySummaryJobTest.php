<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Finance\Jobs\InMemoryFinanceJobClaims;
use TriCab\Api\Finance\Mail\DisabledFinanceMailTransport;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailboxRegistry;
use TriCab\Api\Finance\Mail\InMemoryFinanceMailLedger;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskMailLinks;
use TriCab\Api\Finance\Task\InMemoryTaskReportRecipientStore;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Api\Tests\Finance\Mail\RecordingFinanceMailTransport;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobOutcome;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRegistry;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobRunner;
use TriCab\Core\Finance\Shared\Mail\FinanceMailService;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransportFailed;
use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailbox;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskReportRecipient;
use TriCab\Core\Finance\Task\Service\TaskDailySummaryJob;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/**
 * The 08:00 summary (T2.9, decision D23) on the shared mail service's in-memory twins and a recording transport:
 * nothing here sends mail or touches a network.
 */
final class TaskDailySummaryJobTest extends TestCase
{
    /** 08:00 on 6 Oct 2026 in Melbourne (AEDT, UTC+11) is 21:00 UTC on the 5th. */
    private const RUN_AT = '2026-10-05 21:00:00';

    private InMemoryTaskStore $store;

    private InMemoryTaskReportRecipientStore $recipients;

    private InMemoryFinanceMailLedger $ledger;

    private RecordingFinanceMailTransport $transport;

    private InMemoryTaskMailLinks $links;

    private InMemoryFinanceTaskMemberDirectory $directory;

    private TaskService $tasks;

    private TaskActor $admin;

    private FixedClock $clock;

    private TaskDailySummaryJob $job;

    protected function setUp(): void
    {
        $this->directory = new InMemoryFinanceTaskMemberDirectory();
        $this->directory->add(11, 'Una User', 'una@example.test');
        $this->directory->add(12, 'Mo Manager', 'mo@example.test');
        $this->store = new InMemoryTaskStore();
        $this->clock = FixedClock::utc(self::RUN_AT);
        $time = new TaskTime($this->clock, 'Australia/Melbourne');
        $this->tasks = new TaskService($this->store, $this->store, $this->directory, $time);
        $this->recipients = new InMemoryTaskReportRecipientStore();
        $this->ledger = new InMemoryFinanceMailLedger();
        $this->transport = new RecordingFinanceMailTransport($this->ledger);
        $this->links = new InMemoryTaskMailLinks();
        $this->admin = new TaskActor(12, 'Mo Manager', 'mo@example.test', [Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_DELETE]);
        $this->job = $this->jobWith($this->transport, 'Finance@Example.test');
    }

    private function jobWith(FinanceMailTransport $transport, string $from): TaskDailySummaryJob
    {
        $registry = new InMemoryFinanceMailboxRegistry([new FinanceMailbox('m1', 'finance@example.test', 'Finance', '', true, ['TASK' => ['read' => false, 'send' => true]])]);

        return new TaskDailySummaryJob(
            $this->store, $this->directory, $this->recipients,
            new FinanceMailService($registry, $this->ledger, $transport),
            $this->ledger, $this->links, new TaskTime($this->clock, 'Australia/Melbourne'), $from,
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::RUN_AT, new DateTimeZone('UTC'));
    }

    private function recipient(string ...$emails): void
    {
        foreach ($emails as $i => $email) {
            $at = new DateTimeImmutable('2026-10-01 00:00:00', new DateTimeZone('UTC'));
            $this->recipients->insert(new TaskReportRecipient('r' . $i . bin2hex(random_bytes(8)), $email, '', null, $at, $at));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function task(string $name, ?string $due, array $data = []): string
    {
        $t = $this->tasks->create($this->admin, ['name' => $name, 'due_date' => $due] + $data);
        self::assertIsString($t['id']);

        return $t['id'];
    }

    // ==== when nothing is sent =================================================================

    public function test_nothing_due_sends_nothing(): void
    {
        $this->recipient('ada@example.test');
        $this->task('Later', '2026-10-20');
        $this->task('No date', null);
        $this->task('Done', '2026-10-01', ['status' => 'completed']);
        $binned = $this->task('Binned', '2026-10-01');
        $this->tasks->delete($this->admin, $binned);

        $facts = $this->job->run($this->now());

        self::assertSame(['status' => 'nothing_due', 'sent' => 0, 'overdue' => 0, 'dueToday' => 0], $facts);
        self::assertSame([], $this->transport->sent);
        self::assertSame([], $this->ledger->all());
    }

    public function test_no_recipients_sends_nothing_and_says_so(): void
    {
        $this->task('Late', '2026-10-01');

        self::assertSame(['status' => 'no_recipients', 'sent' => 0, 'overdue' => 1, 'dueToday' => 0], $this->job->run($this->now()));
        self::assertSame([], $this->transport->sent);
    }

    public function test_with_no_sender_configured_the_run_fails_and_never_guesses_one(): void
    {
        $this->recipient('ada@example.test');
        $this->task('Late', '2026-10-01');
        $job = $this->jobWith($this->transport, '  ');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No sending mailbox is configured');
        $job->run($this->now());
    }

    // ==== the send =============================================================================

    public function test_one_message_per_recipient_from_the_configured_mailbox_with_no_actor_and_the_sources_subject(): void
    {
        $this->recipient('ada@example.test', 'mo@example.test');
        $this->task('Late', '2026-10-01', ['preparer_id' => 11, 'reviewer_id' => 12]);
        $this->task('Today', '2026-10-06');
        $this->task('Later', '2026-10-07');

        $facts = $this->job->run($this->now());

        self::assertSame(['status' => 'sent', 'sent' => 2, 'failed' => 0, 'suppressed' => 0, 'skipped' => 0, 'overdue' => 1, 'dueToday' => 1], $facts);
        self::assertSame([['ada@example.test'], ['mo@example.test']], array_map(static fn (FinanceMailMessage $m): array => $m->to, $this->transport->sent));
        $message = $this->transport->sent[0];
        self::assertSame('finance@example.test', $message->mailbox);
        self::assertNull($message->actorUserId, 'a scheduled job has no actor');
        self::assertSame('TASK', $message->application);
        self::assertSame('HTML', $message->contentType);
        self::assertSame('daily-summary:2026-10-06:ada@example.test', $message->idempotencyKey);
        self::assertSame('Tri-Task Daily Summary — 1 overdue, 1 due today (2026-10-06)', $message->subject);
        self::assertStringContainsString('Una User', $message->body);
        self::assertStringContainsString('Mo Manager', $message->body);
        self::assertStringNotContainsString('Later', $message->body);
        self::assertCount(2, $this->links->all());
        self::assertSame(['daily_summary', 'daily_summary'], array_map(static fn ($l): string => $l->kind, $this->links->all()));
        self::assertSame([null, null], array_map(static fn ($l): ?string => $l->taskId, $this->links->all()));
    }

    public function test_the_html_is_the_sources_for_a_fixed_fixture(): void
    {
        $this->recipient('ada@example.test');
        $this->task('Late <b>& "quoted" \'it\'</b>', '2026-10-01', ['preparer_id' => 11, 'finance_statement_area' => 'A & B', 'progress' => 40]);
        $this->task('Today', '2026-10-06', ['reviewer_id' => 12]);

        $this->job->run($this->now());

        $td = 'style="padding:8px 10px;border-bottom:1px solid #eef2f7;"';
        $th = 'style="padding:8px 10px;"';
        $table = static fn (string $rows): string => '<table style="width:100%;border-collapse:collapse;font-size:13px;color:#334155;">'
            . '<thead><tr style="background:#f8fafc;text-align:left;color:#64748b;">'
            . "<th {$th}>Task</th><th {$th}>Area</th><th {$th}>Preparer</th><th {$th}>Reviewer</th><th {$th}>Due</th><th {$th}>Progress</th>"
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
        $expected = '<div style="font-family:Inter,Arial,sans-serif;max-width:680px;margin:0 auto;color:#0f172a;">'
            . '<h2 style="font-size:20px;margin:0 0 4px;">Tri-Task Daily Task Summary</h2>'
            . '<p style="margin:0 0 16px;color:#64748b;font-size:13px;">2026-10-06 — tasks needing attention</p>'
            . '<h3 style="margin:24px 0 8px;font-size:15px;color:#0f172a;">Overdue (1)</h3>'
            . $table("<tr><td {$td}>Late &lt;b&gt;&amp; &quot;quoted&quot; &#x27;it&#x27;&lt;/b&gt;</td><td {$td}>A &amp; B</td><td {$td}>Una User</td><td {$td}>—</td><td {$td}>01 Oct 2026</td><td {$td}>40%</td></tr>")
            . '<h3 style="margin:24px 0 8px;font-size:15px;color:#0f172a;">Due Today (1)</h3>'
            . $table("<tr><td {$td}>Today</td><td {$td}>—</td><td {$td}>—</td><td {$td}>Mo Manager</td><td {$td}>06 Oct 2026</td><td {$td}>0%</td></tr>")
            . '<p style="margin-top:24px;color:#94a3b8;font-size:12px;">Sent automatically by Tri-Task.</p></div>';
        self::assertSame($expected, $this->transport->sent[0]->body);
    }

    public function test_an_empty_section_says_so_and_the_dates_and_dashes_follow_the_source(): void
    {
        [, $body] = TaskDailySummaryJob::message([], [], '2026-10-06', []);

        self::assertStringContainsString("<p style='color:#64748b;font-size:13px;'>No overdue tasks.</p>", $body);
        self::assertStringContainsString("<p style='color:#64748b;font-size:13px;margin-top:16px;'>No tasks due today.</p>", $body);
        self::assertSame('06 Oct 2026', TaskDailySummaryJob::formatDate('2026-10-06'));
        self::assertSame('—', TaskDailySummaryJob::formatDate(''));
        self::assertSame('not &lt;a&gt; date', TaskDailySummaryJob::formatDate('not <a> date'), 'text that is not a date is returned escaped');
    }

    public function test_more_than_500_tasks_are_all_counted(): void
    {
        $this->recipient('ada@example.test');
        for ($i = 1; $i <= 620; ++$i) {
            $this->task('T' . $i, '2026-10-01');
        }

        $facts = $this->job->run($this->now());

        self::assertSame(620, $facts['overdue']);
        self::assertSame('Tri-Task Daily Summary — 620 overdue, 0 due today (2026-10-06)', $this->transport->sent[0]->subject);
    }

    // ==== safe to repeat ==========================================================================

    public function test_a_repeat_the_same_day_sends_nothing_more(): void
    {
        $this->recipient('ada@example.test');
        $this->task('Late', '2026-10-01');
        $this->job->run($this->now());

        $second = $this->job->run($this->now());

        self::assertSame(1, count($this->transport->sent));
        self::assertSame(['status' => 'sent', 'sent' => 0, 'failed' => 0, 'suppressed' => 0, 'skipped' => 1, 'overdue' => 1, 'dueToday' => 0], $second);
    }

    public function test_a_failed_send_is_retried_once_under_a_new_key_and_a_sent_one_is_not(): void
    {
        $this->recipient('ada@example.test', 'mo@example.test');
        $this->task('Late', '2026-10-01');
        $flaky = new class () implements FinanceMailTransport {
            /** @var list<string> */
            public array $failFor = ['mo@example.test'];

            /** @var list<FinanceMailMessage> */
            public array $sent = [];

            public function deliver(FinanceMailMessage $message): void
            {
                if (in_array($message->to[0], $this->failFor, true)) {
                    throw new FinanceMailTransportFailed('Graph said no');
                }
                $this->sent[] = $message;
            }
        };
        $job = $this->jobWith($flaky, 'finance@example.test');

        try {
            $job->run($this->now());
            self::fail('A partial failure fails the run, so the audit row says so.');
        } catch (RuntimeException $e) {
            self::assertSame('1 of 2 daily summary emails failed (1 sent, 0 skipped, 0 suppressed)', $e->getMessage());
        }
        self::assertSame(['daily-summary:2026-10-06:ada@example.test'], array_map(static fn (FinanceMailMessage $m): ?string => $m->idempotencyKey, $flaky->sent));

        $flaky->failFor = [];
        $second = $job->run($this->now());

        self::assertSame(1, $second['sent']);
        self::assertSame(1, $second['skipped']);
        self::assertSame(
            ['daily-summary:2026-10-06:ada@example.test', 'daily-summary:2026-10-06:mo@example.test:retry1'],
            array_map(static fn (FinanceMailMessage $m): ?string => $m->idempotencyKey, $flaky->sent),
            'the shared ledger refuses to reuse a failed key, so the retry takes a new one',
        );
        $third = $job->run($this->now());
        self::assertSame([0, 2], [$third['sent'], $third['skipped']]);
        self::assertCount(2, $flaky->sent);
    }

    public function test_one_failing_address_does_not_stop_the_rest(): void
    {
        $this->recipient('a@example.test', 'b@example.test', 'c@example.test');
        $this->task('Late', '2026-10-01');
        $selective = new class () implements FinanceMailTransport {
            /** @var list<string> */
            public array $to = [];

            public function deliver(FinanceMailMessage $message): void
            {
                if ($message->to[0] === 'b@example.test') {
                    throw new FinanceMailTransportFailed('Mailbox unavailable');
                }
                $this->to[] = $message->to[0];
            }
        };

        try {
            $this->jobWith($selective, 'finance@example.test')->run($this->now());
            self::fail('Expected the run to fail.');
        } catch (RuntimeException) {
            self::assertSame(['a@example.test', 'c@example.test'], $selective->to);
        }
        self::assertSame(['sent', 'failed', 'sent'], array_map(static fn ($d): string => $d->status, $this->ledger->all()));
    }

    public function test_suppressed_mail_is_never_counted_as_sent_and_goes_out_later_under_the_same_key(): void
    {
        $this->recipient('ada@example.test');
        $this->task('Late', '2026-10-01');
        $off = $this->jobWith(new DisabledFinanceMailTransport(), 'finance@example.test');

        $facts = $off->run($this->now());

        self::assertSame(['status' => 'suppressed', 'sent' => 0, 'failed' => 0, 'suppressed' => 1, 'skipped' => 0, 'overdue' => 1, 'dueToday' => 0], $facts);
        self::assertSame(['suppressed'], array_map(static fn ($d): string => $d->status, $this->ledger->all()));
        self::assertCount(1, $this->links->all());

        // Mail switched on later the same day: the same key is used again and the row is reopened, not duplicated.
        $on = $this->job->run($this->now());

        self::assertSame(1, $on['sent']);
        self::assertCount(1, $this->ledger->all());
        self::assertSame('sent', $this->ledger->all()[0]->status);
        self::assertSame('daily-summary:2026-10-06:ada@example.test', $this->transport->sent[0]->idempotencyKey);
    }

    // ==== the schedule, and the zone across the clock change ====================================

    public function test_the_job_is_named_and_scheduled_for_8am_in_the_task_zone(): void
    {
        self::assertSame('task.daily-summary', $this->job->name());
        // 07:59 and 08:00 in Melbourne on 6 Oct (AEDT).
        self::assertNull($this->job->schedule()->dueSlot(new DateTimeImmutable('2026-10-05 20:59:00', new DateTimeZone('UTC'))));
        self::assertSame('D2026-10-06@08:00@Australia/Melbourne', $this->job->schedule()->dueSlot($this->now()));
    }

    public function test_across_the_daylight_saving_change_the_summary_still_runs_at_8am_local_and_once(): void
    {
        $this->recipient('ada@example.test');
        $this->task('Late', '2026-10-01');
        $this->task('Today', '2026-10-04');
        $claims = new InMemoryFinanceJobClaims();
        $audit = new InMemoryFinanceAuditLog();
        $at = new DateTimeImmutable('2026-10-03 20:59:00', new DateTimeZone('UTC'));   // 07:59 AEDT on Sunday 4 Oct: the clocks went forward at 02:00
        $runner = new FinanceJobRunner(new FinanceJobRegistry([$this->job]), $claims, $audit, static function () use (&$at): DateTimeImmutable {
            return $at;
        });

        self::assertSame([FinanceJobOutcome::NOT_DUE], array_map(static fn (FinanceJobOutcome $o): string => $o->status, $runner->runDue()));
        $at = new DateTimeImmutable('2026-10-03 21:00:00', new DateTimeZone('UTC'));   // 08:00 AEDT: a fixed +10 would call this 07:00
        $ran = $runner->runDue();
        self::assertSame([FinanceJobOutcome::RAN], array_map(static fn (FinanceJobOutcome $o): string => $o->status, $ran));
        self::assertSame('D2026-10-04@08:00@Australia/Melbourne', $ran[0]->slot);
        self::assertSame('Tri-Task Daily Summary — 1 overdue, 1 due today (2026-10-04)', $this->transport->sent[0]->subject);

        self::assertSame([FinanceJobOutcome::ALREADY_CLAIMED], array_map(static fn (FinanceJobOutcome $o): string => $o->status, $runner->runDue()), 'a second pass the same minute sends nothing more');
        self::assertCount(1, $this->transport->sent);
        self::assertSame(['finance.job.succeeded'], array_map(static fn ($e): string => $e->action, $audit->events));
    }

    public function test_the_audit_row_of_a_run_holds_counts_only(): void
    {
        $this->recipient('ada@example.test');
        $this->task('Secret task name', '2026-10-01');
        $audit = new InMemoryFinanceAuditLog();
        $runner = new FinanceJobRunner(new FinanceJobRegistry([$this->job]), new InMemoryFinanceJobClaims(), $audit, fn (): DateTimeImmutable => $this->now());

        $runner->runDue();

        $json = json_encode($audit->events, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Secret task name', $json);
        self::assertStringNotContainsString('ada@example.test', $json);
        self::assertStringContainsString('"overdue":1', $json);
    }
}
