<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Ai\UndeployedFinanceAiClient;
use TriCab\Api\Finance\Task\InMemoryFinanceTaskMemberDirectory;
use TriCab\Api\Finance\Task\InMemoryTaskStore;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskAiFailed;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Service\TaskAiService;
use TriCab\Core\Finance\Task\Service\TaskService;
use TriCab\Core\Finance\Task\Service\TaskSetupService;
use TriCab\Core\Finance\Task\Service\TaskTime;

/** The five drafting routes over a fake `finance_ai` client: data only, the source's rules, the source's 502 (T2.7). No model is ever called. */
final class TaskAiServiceTest extends TestCase
{
    private InMemoryTaskStore $store;

    private InMemoryFinanceTaskMemberDirectory $directory;

    private RecordingFinanceAiClient $ai;

    private TaskService $tasks;

    private TaskAiService $service;

    private TaskActor $una;

    private TaskActor $max;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->directory = new InMemoryFinanceTaskMemberDirectory();
        $this->directory->add(11, 'Una User', 'una@example.test');
        $this->directory->add(12, 'Max Manager', 'max@example.test');
        $this->directory->add(14, 'Ada Admin', 'ada@example.test');
        $this->store = new InMemoryTaskStore();
        $time = new TaskTime(FixedClock::utc('2026-10-07 01:00:00'), 'Australia/Melbourne');
        $this->tasks = new TaskService($this->store, $this->store, $this->directory, $time);
        $this->ai = new RecordingFinanceAiClient();
        $this->service = $this->serviceWith($this->ai);

        $base = [Module::PERMISSION_TASK_TASKS_READ, Module::PERMISSION_TASK_TASKS_CREATE, Module::PERMISSION_TASK_MAIL_SEND];
        $this->una = new TaskActor(11, 'Una User', 'una@example.test', $base);
        $this->max = new TaskActor(12, 'Max Manager', 'max@example.test', [...$base, Module::PERMISSION_TASK_TASKS_READ_ALL]);
    }

    private function serviceWith(FinanceAiClient $client): TaskAiService
    {
        return new TaskAiService($this->tasks, $this->store, $this->directory, $client, function (string $line): void {
            $this->log[] = $line;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private function task(array $data = []): string
    {
        $t = $this->tasks->create($this->max, $data + ['name' => 'Bank rec', 'preparer_id' => 11, 'reviewer_id' => 12, 'due_date' => '2020-01-01', 'description' => 'Reconcile', 'finance_statement_area' => 'Cash']);
        self::assertIsString($t['id']);

        return $t['id'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);
        $out = [];
        foreach ($value as $row) {
            self::assertIsArray($row);
            $clean = [];
            foreach ($row as $key => $item) {
                $clean[(string) $key] = $item;
            }
            $out[] = $clean;
        }

        return $out;
    }

    // ==== data only ============================================================================

    public function test_what_is_sent_is_data_never_a_prompt_for_every_purpose(): void
    {
        $id = $this->task();
        $this->service->taskDetails(['name' => 'Bank rec']);
        $this->service->statementAreas(['existing' => []]);
        $this->service->taskEmails($this->max, ['taskIds' => [$id]]);
        $this->service->reminders($this->max, ['taskId' => $id]);
        $this->service->emailDraft($this->max, ['taskId' => $id, 'kind' => 'reminder', 'instruction' => 'Nudge']);

        self::assertSame(['task_details', 'statement_areas', 'task_email', 'task_reminder', 'task_email_draft'], array_column($this->ai->calls, 'purpose'));
        foreach ($this->ai->calls as $call) {
            foreach (array_keys($call['data']) as $key) {
                self::assertNotContains($key, ['prompt', 'messages', 'model', 'temperature', 'schema'], $call['purpose']);
            }
            self::assertStringNotContainsString('You are a', json_encode($call['data'], JSON_THROW_ON_ERROR), 'no prompt text');
        }
        $task = $this->ai->calls[2]['data']['task'];
        self::assertIsArray($task);
        self::assertSame(['name', 'category', 'finance_statement_area', 'description', 'requirements', 'status', 'display_status', 'progress', 'due_date', 'due_date_requested'], array_keys($task));
        self::assertSame('overdue', $task['display_status']);
    }

    // ==== route 39 =============================================================================

    public function test_task_details_sends_the_name_and_area_and_answers_both_fields(): void
    {
        $out = $this->service->taskDetails(['name' => '  Bank rec ', 'finance_statement_area' => ' Cash ']);

        self::assertSame(['description' => 'Reconcile the bank.', 'requirements' => '- Sign off'], $out);
        self::assertSame(['name' => 'Bank rec', 'finance_statement_area' => 'Cash'], $this->ai->calls[0]['data']);
    }

    public function test_task_details_needs_a_name_and_makes_no_call_without_one(): void
    {
        foreach ([[], ['name' => ''], ['name' => '   '], ['name' => null]] as $data) {
            try {
                $this->service->taskDetails($data);
                self::fail('Expected a refusal.');
            } catch (TaskInvalid $e) {
                self::assertSame('Task name is required', $e->getMessage());
            }
        }
        self::assertSame([], $this->ai->calls);
    }

    // ==== route 40 =============================================================================

    public function test_statement_areas_remove_what_exists_blank_names_and_are_given_the_long_deadline(): void
    {
        $this->ai->queue[] = ['items' => [
            ['name' => 'cash', 'category' => 'balance_sheet', 'description' => 'd'],
            ['name' => '  EQUITY ', 'category' => 'equity', 'description' => 'd'],
            ['name' => '   ', 'category' => 'other', 'description' => 'd'],
            ['name' => 'Revenue', 'category' => 'income_statement', 'description' => 'd'],
        ]];

        $out = $this->service->statementAreas(['existing' => ['Cash', 'Equity']]);

        self::assertSame(['Revenue'], array_column($out['items'], 'name'));
        self::assertSame(90_000, $this->ai->calls[0]['deadlineMs']);
        self::assertSame(['existing' => ['Cash', 'Equity']], $this->ai->calls[0]['data']);
    }

    public function test_when_existing_is_not_a_list_the_areas_held_now_are_used(): void
    {
        (new TaskSetupService($this->store, $this->directory, new TaskTime(FixedClock::utc('2026-10-07 01:00:00'), 'Australia/Melbourne')))->createArea(['name' => 'Cash']);

        foreach ([[], ['existing' => 'Cash'], ['existing' => ['a' => 'b']]] as $data) {
            $this->service->statementAreas($data);
        }

        self::assertSame([['Cash'], ['Cash'], ['Cash']], array_map(static fn (array $c): mixed => $c['data']['existing'], $this->ai->calls));
    }

    // ==== route 41 =============================================================================

    public function test_task_emails_cover_only_tasks_the_caller_may_see_and_have_a_member_preparer(): void
    {
        $mine = $this->task(['name' => 'Mine', 'preparer_id' => 11, 'reviewer_id' => 12]);
        $theirs = $this->task(['name' => 'Theirs', 'preparer_id' => 14, 'reviewer_id' => 12]);
        $unassigned = $this->task(['name' => 'Nobody', 'preparer_id' => null, 'reviewer_id' => 11]);
        $this->ai->queue[] = ['subject' => 'Hello', 'body' => 'Please do it.'];

        $out = $this->service->taskEmails($this->una, ['taskIds' => [$mine, $theirs, 'ffffffffffffffffffffffffffffffff', $unassigned]]);

        self::assertCount(1, $out['drafts']);
        self::assertSame(
            ['taskId' => $mine, 'taskName' => 'Mine', 'to' => 'una@example.test', 'toName' => 'Una User', 'subject' => 'Hello', 'body' => 'Please do it.'],
            $out['drafts'][0],
        );
        self::assertCount(1, $this->ai->calls);
        self::assertSame('Una User', $this->ai->calls[0]['data']['preparer_name']);
    }

    public function test_a_blank_subject_falls_back_to_the_sources_words(): void
    {
        $id = $this->task(['name' => 'Bank rec']);
        $this->ai->queue[] = ['subject' => '', 'body' => 'b'];

        self::assertSame('Update on task: Bank rec', $this->service->taskEmails($this->max, ['taskIds' => [$id]])['drafts'][0]['subject']);
    }

    public function test_task_emails_limits_and_one_call_per_task(): void
    {
        foreach ([['taskIds' => []], [], ['taskIds' => 'x']] as $data) {
            try {
                $this->service->taskEmails($this->max, $data);
                self::fail('Expected a refusal.');
            } catch (TaskInvalid $e) {
                self::assertSame('Select at least one task', $e->getMessage());
            }
        }
        try {
            $this->service->taskEmails($this->max, ['taskIds' => array_fill(0, 21, 'x')]);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame('Select up to 20 tasks at once', $e->getMessage());
        }
        $a = $this->task(['name' => 'A']);
        $b = $this->task(['name' => 'B']);
        $out = $this->service->taskEmails($this->max, ['taskIds' => [$a, $b]]);
        self::assertSame(['A', 'B'], array_column($out['drafts'], 'taskName'));
        self::assertCount(2, $this->ai->calls);
    }

    public function test_a_failure_stops_the_batch_with_the_sources_message_and_returns_no_drafts(): void
    {
        $a = $this->task(['name' => 'A']);
        $b = $this->task(['name' => 'B']);
        $this->ai->queue = [['subject' => 's', 'body' => 'b'], FinanceAiFailure::of(FinanceAiFailure::PROVIDER_UNAVAILABLE, 'The model provider is unavailable (HTTP 503).', 'corr-aaaaaaaa')];

        try {
            $this->service->taskEmails($this->max, ['taskIds' => [$a, $b]]);
            self::fail('Expected a failure.');
        } catch (TaskAiFailed $e) {
            self::assertSame('The AI draft failed: The model provider is unavailable (HTTP 503).', $e->getMessage());
            self::assertSame(502, $e->httpStatus());
        }
        self::assertSame(['Tri-Task AI draft failed (purpose task_email, code provider_unavailable, correlation corr-aaaaaaaa)'], $this->log);
    }

    // ==== route 42 =============================================================================

    public function test_a_reminder_is_one_call_for_the_preparer_and_reviewer_together(): void
    {
        $id = $this->task();
        $this->ai->queue[] = ['subject' => ' Reminder ', 'body' => "Soon.\n"];

        $out = $this->service->reminders($this->max, ['taskId' => $id]);

        self::assertSame('Bank rec', $out['taskName']);
        self::assertSame([['role' => 'preparer', 'email' => 'una@example.test', 'name' => 'Una User'], ['role' => 'reviewer', 'email' => 'max@example.test', 'name' => 'Max Manager']], $out['recipients']);
        self::assertSame(['Reminder', 'Soon.'], [$out['subject'], $out['body']]);
        self::assertCount(1, $this->ai->calls, 'one draft for both, so there is no second copy to go out unedited');
        self::assertSame([['name' => 'Una User', 'role' => 'preparer'], ['name' => 'Max Manager', 'role' => 'reviewer']], $this->ai->calls[0]['data']['recipients']);
    }

    public function test_someone_who_is_both_preparer_and_reviewer_is_named_once(): void
    {
        $id = $this->task(['preparer_id' => 11, 'reviewer_id' => 11]);

        $out = $this->service->reminders($this->max, ['taskId' => $id]);

        self::assertSame(['una@example.test'], array_column(self::rows($out['recipients']), 'email'));
        self::assertCount(1, self::rows($this->ai->calls[0]['data']['recipients']));
    }

    public function test_a_task_with_nobody_assigned_drafts_nothing_and_makes_no_call(): void
    {
        $id = $this->task(['preparer_id' => null, 'reviewer_id' => null]);

        $out = $this->service->reminders($this->max, ['taskId' => $id]);

        self::assertSame(['taskName' => 'Bank rec', 'recipients' => [], 'subject' => '', 'body' => ''], $out);
        self::assertSame([], $this->ai->calls);
    }

    public function test_a_blank_reminder_subject_falls_back_and_a_hidden_task_is_a_404_without_a_call(): void
    {
        $id = $this->task(['preparer_id' => 14, 'reviewer_id' => 12]);
        $this->ai->queue[] = ['subject' => '  ', 'body' => 'b'];

        self::assertSame('Reminder: Bank rec', $this->service->reminders($this->max, ['taskId' => $id])['subject']);
        $calls = count($this->ai->calls);
        try {
            $this->service->reminders($this->una, ['taskId' => $id]);
            self::fail('Expected a 404.');
        } catch (TaskNotFound) {
            self::assertCount($calls, $this->ai->calls);
        }
        $this->expectException(TaskInvalid::class);
        $this->expectExceptionMessage('taskId is required');
        $this->service->reminders($this->max, ['taskId' => '']);
    }

    // ==== route 43 =============================================================================

    public function test_a_draft_defaults_to_the_preparer_and_answers_the_recipients_too(): void
    {
        $id = $this->task();
        $this->ai->queue[] = ['subject' => ' Bank rec: papers needed ', 'body' => "Please send the statements.\n"];

        $out = $this->service->emailDraft($this->una, ['taskId' => $id, 'kind' => 'task_email', 'instruction' => ' Check in ']);

        self::assertSame([
            'to' => 'una@example.test', 'toName' => 'Una User', 'role' => 'preparer',
            'recipients' => [['role' => 'preparer', 'email' => 'una@example.test', 'name' => 'Una User']],
            'subject' => 'Bank rec: papers needed', 'body' => 'Please send the statements.',
        ], $out);
        $data = $this->ai->calls[0]['data'];
        self::assertSame('Check in', $data['instruction']);
        self::assertSame('', $data['previous_draft']);
        self::assertSame('task_email', $data['kind']);
    }

    public function test_a_draft_may_be_addressed_to_several_members_with_their_roles_and_others_as_other(): void
    {
        $id = $this->task();

        $out = $this->service->emailDraft($this->max, [
            'taskId' => $id, 'kind' => 'reminder', 'instruction' => 'Nudge', 'previous_draft' => 'Subject: Reminder\n\nOld body',
            'to' => ['una@example.test', 'MAX@example.test', 'ada@example.test', 'una@example.test'],
        ]);

        self::assertSame(
            [['una@example.test', 'preparer'], ['max@example.test', 'reviewer'], ['ada@example.test', 'other']],
            array_map(static fn (array $r): array => [$r['email'], $r['role']], self::rows($out['recipients'])),
        );
        self::assertSame('una@example.test', $out['to']);
        self::assertSame('Subject: Reminder\n\nOld body', $this->ai->calls[0]['data']['previous_draft']);
        self::assertSame([['name' => 'Una User', 'role' => 'preparer'], ['name' => 'Max Manager', 'role' => 'reviewer'], ['name' => 'Ada Admin', 'role' => 'other']], $this->ai->calls[0]['data']['recipients']);
        $csv = $this->service->emailDraft($this->max, ['taskId' => $id, 'kind' => 'reminder', 'instruction' => 'x', 'to' => 'ada@example.test; una@example.test']);
        self::assertSame(['ada@example.test', 'una@example.test'], array_column(self::rows($csv['recipients']), 'email'));
    }

    public function test_draft_refusals_never_reach_the_model(): void
    {
        $mine = $this->task();
        $hidden = $this->task(['preparer_id' => 14, 'reviewer_id' => 12]);
        $ok = ['taskId' => $mine, 'kind' => 'reminder', 'instruction' => 'Nudge'];

        foreach ([
            [['instruction' => '  '] + $ok, TaskInvalid::class, 'Tell the AI what the email should say'],
            [['kind' => 'newsletter'] + $ok, TaskInvalid::class, 'kind must be task_email or reminder'],
            [['taskId' => ''] + $ok, TaskInvalid::class, 'taskId is required'],
            [['to' => 'stranger@example.com, other@example.org'] + $ok, TaskInvalid::class, 'These addresses are not Tri-Task members: stranger@example.com, other@example.org'],
            [['taskId' => $hidden] + $ok, TaskNotFound::class, 'Task not found'],
        ] as [$data, $class, $message]) {
            try {
                $this->service->emailDraft($this->una, $data);
                self::fail('Expected ' . $class);
            } catch (TaskInvalid|TaskNotFound $e) {
                self::assertInstanceOf($class, $e);
                self::assertSame($message, $e->getMessage());
            }
        }
        self::assertSame([], $this->ai->calls);
    }

    public function test_a_task_with_nobody_to_write_to_is_refused_unless_a_member_is_named(): void
    {
        $id = $this->task(['preparer_id' => null, 'reviewer_id' => null]);

        try {
            $this->service->emailDraft($this->max, ['taskId' => $id, 'kind' => 'task_email', 'instruction' => 'Hi']);
            self::fail('Expected a refusal.');
        } catch (TaskInvalid $e) {
            self::assertSame('The task has no preparer or reviewer to write to', $e->getMessage());
        }
        self::assertSame([], $this->ai->calls);
        $out = $this->service->emailDraft($this->max, ['taskId' => $id, 'kind' => 'task_email', 'instruction' => 'Hi', 'to' => 'ada@example.test']);
        self::assertSame('other', $out['role']);
    }

    // ==== failures are honest ==================================================================

    public function test_a_service_that_is_not_deployed_answers_the_same_502_with_an_honest_reason(): void
    {
        $service = $this->serviceWith(new UndeployedFinanceAiClient());

        foreach ([
            fn () => $service->taskDetails(['name' => 'x']),
            fn () => $service->statementAreas([]),
            fn () => $service->reminders($this->max, ['taskId' => $this->task()]),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a failure, never an empty draft.');
            } catch (TaskAiFailed $e) {
                self::assertSame('The AI draft failed: The finance AI service is not deployed on this platform yet.', $e->getMessage());
            }
        }
        self::assertCount(3, $this->log);
    }

    public function test_every_failure_code_becomes_the_502_and_the_log_never_holds_the_data(): void
    {
        foreach (FinanceAiFailure::CODES as $code) {
            $this->ai->queue[] = FinanceAiFailure::of($code, 'Safe reason for ' . $code, 'corr-0123456789');
            try {
                $this->service->taskDetails(['name' => 'Secret task name']);
                self::fail('Expected a failure.');
            } catch (TaskAiFailed $e) {
                self::assertSame('The AI draft failed: Safe reason for ' . $code, $e->getMessage());
            }
        }
        self::assertCount(count(FinanceAiFailure::CODES), $this->log);
        foreach ($this->log as $line) {
            self::assertStringNotContainsString('Secret task name', $line);
        }
    }

    public function test_an_answer_missing_a_field_is_a_failure_not_an_empty_draft(): void
    {
        $this->ai->queue[] = ['description' => 'only half'];

        $this->expectException(TaskAiFailed::class);
        $this->expectExceptionMessage('The AI draft failed:');
        $this->service->taskDetails(['name' => 'x']);
    }
}
