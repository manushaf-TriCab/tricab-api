<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use Closure;
use TriCab\Core\Finance\Shared\Ai\FinanceAiClient;
use TriCab\Core\Finance\Shared\Ai\FinanceAiFailure;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskSetupStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskAiFailed;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskMember;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;

/**
 * The five drafting routes (39 to 43) over `finance_ai` (decision D21), from `app.py`.
 *
 * The prompts, the response schemas, the temperature and the timeouts live in the service's
 * purposes (`finance_ai/purposes/task.py`); this class sends **data only** (a task's fields, the
 * recipients' names and roles, the sender's instruction), never a prompt (decision D10), and keeps
 * the source's rules about who may be written to. Every failure is the source's 502, "The AI draft
 * failed: <reason>", and is logged with its purpose and correlation id (never the data); a service
 * that is off or a key that is not set answers the same, never an empty draft.
 */
final class TaskAiService
{
    public const PURPOSE_TASK_DETAILS = 'task_details';
    public const PURPOSE_STATEMENT_AREAS = 'statement_areas';
    public const PURPOSE_TASK_EMAIL = 'task_email';
    public const PURPOSE_TASK_REMINDER = 'task_reminder';
    public const PURPOSE_TASK_EMAIL_DRAFT = 'task_email_draft';

    /** `statement_areas` asks for 18 to 24 items and gets longer than the others (the source's 90 s). */
    public const LONG_DEADLINE_MS = 90_000;

    public const MAX_TASKS = 20;

    private const TASK_FIELDS = [
        'name', 'category', 'finance_statement_area', 'description', 'requirements', 'status', 'display_status',
        'progress', 'due_date', 'due_date_requested',
    ];

    /** @var Closure(string): void */
    private readonly Closure $log;

    /**
     * @param (callable(string): void)|null $log written to `error_log` unless a test passes its own
     */
    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskSetupStore $setup,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly FinanceAiClient $ai,
        ?callable $log = null,
    ) {
        $this->log = Closure::fromCallable($log ?? static function (string $line): void {
            error_log($line);
        });
    }

    // ==================================================================================
    // Route 39: description and requirements for a task name
    // ==================================================================================

    /**
     * @param array<string, mixed> $data `{name, finance_statement_area}`
     * @return array{description: string, requirements: string}
     */
    public function taskDetails(array $data): array
    {
        $name = self::text($data['name'] ?? null);
        if ($name === '') {
            throw new TaskInvalid('Task name is required');
        }
        $result = $this->ask(self::PURPOSE_TASK_DETAILS, [
            'name' => $name,
            'finance_statement_area' => self::text($data['finance_statement_area'] ?? null),
        ]);

        return ['description' => self::answer($result, 'description'), 'requirements' => self::answer($result, 'requirements')];
    }

    // ==================================================================================
    // Route 40: suggested statement areas, existing names removed
    // ==================================================================================

    /**
     * @param array<string, mixed> $data `{existing: [names]}`; when it is not a list, the areas now held
     * @return array{items: list<array<string, mixed>>}
     */
    public function statementAreas(array $data): array
    {
        $existing = $data['existing'] ?? null;
        if (!is_array($existing) || !array_is_list($existing)) {
            $existing = array_map(static fn ($area): string => $area->name, $this->setup->areas());
        }
        $names = [];
        foreach ($existing as $name) {
            $names[] = is_scalar($name) ? (string) $name : '';
        }
        $result = $this->ask(self::PURPOSE_STATEMENT_AREAS, ['existing' => $names], self::LONG_DEADLINE_MS);

        $known = [];
        foreach ($names as $name) {
            $known[mb_strtolower($name)] = true;
        }
        $items = [];
        foreach (is_array($result['items'] ?? null) ? $result['items'] : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = TaskRules::strip(is_string($item['name'] ?? null) ? $item['name'] : '');
            if ($name === '' || isset($known[mb_strtolower($name)])) {
                continue;
            }
            $row = [];
            foreach ($item as $key => $value) {
                $row[(string) $key] = $value;
            }
            $items[] = $row;
        }

        return ['items' => $items];
    }

    // ==================================================================================
    // Route 41: one draft per task, to its preparer
    // ==================================================================================

    /**
     * @param array<string, mixed> $data `{taskIds: [...]}`: one to twenty tasks; ones the caller may not see, or with no preparer who is a member, are skipped
     * @return array{drafts: list<array<string, mixed>>}
     */
    public function taskEmails(TaskActor $actor, array $data): array
    {
        $ids = $data['taskIds'] ?? null;
        $ids = is_array($ids) && array_is_list($ids) ? $ids : [];
        if ($ids === []) {
            throw new TaskInvalid('Select at least one task');
        }
        if (count($ids) > self::MAX_TASKS) {
            throw new TaskInvalid('Select up to ' . self::MAX_TASKS . ' tasks at once');
        }
        $drafts = [];
        foreach ($ids as $id) {
            try {
                $task = $this->tasks->get($actor, is_scalar($id) ? (string) $id : '');   // visible tasks only
            } catch (TaskNotFound) {
                continue;
            }
            $preparers = $this->membersOf($task, 'preparer');
            if ($preparers === []) {
                continue;
            }
            $preparerNames = implode(' and ', array_map(self::displayName(...), $preparers));
            // A failure stops the batch with the source's message, and the drafts so far are not returned.
            $result = $this->ask(self::PURPOSE_TASK_EMAIL, ['task' => self::taskData($task), 'preparer_name' => $preparerNames]);
            $subject = self::answer($result, 'subject');
            // One draft per task, to every preparer (23 Oct 2026). `to` stays the first preparer's address for clients from before.
            $drafts[] = [
                'taskId' => (string) $task['id'],
                'taskName' => (string) $task['name'],
                'to' => $preparers[0]->email,
                'toName' => implode(', ', array_map(self::displayName(...), $preparers)),
                'recipients' => array_map(static fn (TaskMember $m): array => ['email' => $m->email, 'name' => self::displayName($m)], $preparers),
                'subject' => $subject !== '' ? $subject : 'Update on task: ' . (string) $task['name'],
                'body' => self::answer($result, 'body'),
            ];
        }

        return ['drafts' => $drafts];
    }

    // ==================================================================================
    // Route 42: one reminder for a task, to its preparer and reviewer together
    // ==================================================================================

    /**
     * One call for the task, one draft addressed to the preparer and the reviewer together, each person
     * once; with nobody assigned, an empty answer and no call.
     *
     * @param array<string, mixed> $data `{taskId}`
     * @return array<string, mixed>
     */
    public function reminders(TaskActor $actor, array $data): array
    {
        $taskId = self::text($data['taskId'] ?? null);
        if ($taskId === '') {
            throw new TaskInvalid('taskId is required');
        }
        $task = $this->tasks->get($actor, $taskId);
        $people = $this->people($task);
        $recipients = array_map(static fn (array $p): array => ['role' => $p[1], 'email' => $p[0]->email, 'name' => self::displayName($p[0])], $people);
        if ($people === []) {
            return ['taskName' => (string) $task['name'], 'recipients' => [], 'subject' => '', 'body' => ''];
        }
        $result = $this->ask(self::PURPOSE_TASK_REMINDER, [
            'task' => self::taskData($task),
            'recipients' => array_map(static fn (array $p): array => ['name' => self::displayName($p[0]), 'role' => $p[1]], $people),
        ]);
        $subject = TaskRules::strip(self::answer($result, 'subject'));

        return [
            'taskName' => (string) $task['name'],
            'recipients' => $recipients,
            'subject' => $subject !== '' ? $subject : 'Reminder: ' . (string) $task['name'],
            'body' => TaskRules::strip(self::answer($result, 'body')),
        ];
    }

    // ==================================================================================
    // Route 43: draft or revise one email from the sender's instruction
    // ==================================================================================

    /**
     * `to` is one address or several, all Tri-Task members (else 422 naming the strays); none given means
     * the task's preparers.
     *
     * @param array<string, mixed> $data `{taskId, kind, instruction, to, previous_draft}`
     * @return array<string, mixed>
     */
    public function emailDraft(TaskActor $actor, array $data): array
    {
        $taskId = self::text($data['taskId'] ?? null);
        if ($taskId === '') {
            throw new TaskInvalid('taskId is required');
        }
        $kind = $data['kind'] ?? null;
        if ($kind !== 'task_email' && $kind !== 'reminder') {
            throw new TaskInvalid('kind must be task_email or reminder');
        }
        $instruction = TaskRules::strip(self::text($data['instruction'] ?? null));
        if ($instruction === '') {
            throw new TaskInvalid('Tell the AI what the email should say');
        }
        $task = $this->tasks->get($actor, $taskId);   // visible tasks only
        $members = $this->members->members();
        $people = $this->people($task);
        $roles = [];
        foreach ($people as [$member, $role]) {
            $roles[$member->userId] = $role;
        }

        $wanted = TaskMailText::addressList($data['to'] ?? null);
        if ($wanted !== []) {
            $byEmail = [];
            foreach ($members as $member) {
                $byEmail[strtolower($member->email)] = $member;
            }
            $stray = array_values(array_filter($wanted, static fn (string $a): bool => !isset($byEmail[$a])));
            if ($stray !== []) {
                throw new TaskInvalid('These addresses are not Tri-Task members: ' . implode(', ', $stray));
            }
            $recipients = [];
            foreach (array_values(array_unique($wanted)) as $address) {
                $member = $byEmail[$address];
                $recipients[] = [$member, $roles[$member->userId] ?? 'other'];
            }
        } else {
            $recipients = array_values(array_filter($people, static fn (array $p): bool => $p[1] === 'preparer'));
            if ($recipients === []) {
                $recipients = array_slice($people, 0, 1);
            }
        }
        if ($recipients === []) {
            throw new TaskInvalid('The task has no preparer or reviewer to write to');
        }

        $result = $this->ask(self::PURPOSE_TASK_EMAIL_DRAFT, [
            'task' => self::taskData($task),
            'recipients' => array_map(static fn (array $p): array => ['name' => self::displayName($p[0]), 'role' => $p[1]], $recipients),
            'kind' => $kind,
            'instruction' => $instruction,
            'previous_draft' => self::text($data['previous_draft'] ?? null),
        ]);
        [$first, $firstRole] = $recipients[0];

        return [
            'to' => $first->email,
            'toName' => self::displayName($first),
            'role' => $firstRole,
            'recipients' => array_map(static fn (array $p): array => ['role' => $p[1], 'email' => $p[0]->email, 'name' => self::displayName($p[0])], $recipients),
            'subject' => TaskRules::strip(self::answer($result, 'subject')),
            'body' => TaskRules::strip(self::answer($result, 'body')),
        ];
    }

    // ==================================================================================
    // Helpers
    // ==================================================================================

    /**
     * One call to the service. A failure is logged (purpose, code, correlation id) and becomes the source's 502.
     *
     * @param array<string, mixed> $data
     * @return array<array-key, mixed>
     */
    private function ask(string $purpose, array $data, ?int $deadlineMs = null): array
    {
        try {
            return $this->ai->complete($purpose, $data, $deadlineMs)->result;
        } catch (FinanceAiFailure $failure) {
            ($this->log)(sprintf(
                'Tri-Task AI draft failed (purpose %s, code %s, correlation %s)',
                $purpose,
                $failure->failureCode,
                $failure->correlationId ?? '-',
            ));

            throw new TaskAiFailed('The AI draft failed: ' . $failure->getMessage(), [], $failure);
        }
    }

    /**
     * A text the service must have answered.
     *
     * @param array<array-key, mixed> $result
     */
    private static function answer(array $result, string $key): string
    {
        $value = $result[$key] ?? null;
        if (!is_string($value)) {
            throw new TaskAiFailed('The AI draft failed: The AI service returned an answer this page cannot read.');
        }

        return $value;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) && $value !== false ? TaskRules::strip((string) $value) : '';
    }

    /**
     * The task's own fields only: nothing else about it goes to the service.
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private static function taskData(array $task): array
    {
        $out = [];
        foreach (self::TASK_FIELDS as $key) {
            $out[$key] = $task[$key] ?? null;
        }

        return $out;
    }

    /**
     * The members of one role on a presented task, in order.
     *
     * @param array<string, mixed> $task
     * @return list<TaskMember>
     */
    private function membersOf(array $task, string $role): array
    {
        $out = [];
        foreach (TaskPresenter::peopleOf($task, $role) as $userId) {
            $member = $this->members->find($userId);
            if ($member !== null) {
                $out[] = $member;
            }
        }

        return $out;
    }

    /**
     * The task's preparers and reviewers as `[member, role]`, each person once (`_task_people`).
     *
     * @param array<string, mixed> $task
     * @return list<array{0: TaskMember, 1: string}>
     */
    private function people(array $task): array
    {
        $people = [];
        $seen = [];
        foreach (['preparer', 'reviewer'] as $role) {
            foreach ($this->membersOf($task, $role) as $member) {
                if (!isset($seen[$member->userId])) {
                    $seen[$member->userId] = true;
                    $people[] = [$member, $role];
                }
            }
        }

        return $people;
    }

    private static function displayName(TaskMember $member): string
    {
        return $member->name !== '' ? $member->name : $member->email;
    }
}
