<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskBoardStore;
use TriCab\Core\Finance\Task\Contract\TaskSetupStore;
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskGroup;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskMember;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Domain\TaskProject;
use TriCab\Core\Finance\Task\Domain\TaskProjectTemplate;
use TriCab\Core\Finance\Task\Domain\TaskRecord;

/**
 * The project board (13 Oct 2026, item 9 of TRI-TASK-UX-PLAN.md): a project's tasks arranged in groups, as in Nick's
 * reference board, and project templates that create a whole project, its groups and its tasks in one step.
 *
 * Gates are the routes' (as TaskSetupService): reading needs TASKS.READ, every write SETUP.MANAGE. Creating tasks
 * from a template goes through TaskService, so a template's tasks are checked as any other task.
 *
 * A template task's due date is `due_offset_days` after the start date chosen when the template is used, in calendar
 * days; with none it has no due date. Its reviewer, when the template names none, is the default reviewer (item 5):
 * a Manager other than the preparer, else an Administrator.
 */
final class TaskBoardService
{
    public const MAX_GROUPS = 50;
    public const MAX_TEMPLATE_TASKS = 500;
    public const MAX_OFFSET_DAYS = 3650;

    /** The group a project's ungrouped tasks go in when it is saved as a template. */
    public const UNGROUPED = 'Other tasks';

    public function __construct(
        private readonly TaskBoardStore $board,
        private readonly TaskStore $tasks,
        private readonly TaskSetupStore $setup,
        private readonly TaskService $taskService,
        private readonly TaskSetupService $setupService,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly TaskTime $time,
    ) {
    }

    // ==================================================================================
    // Groups
    // ==================================================================================

    /** @return list<array<string, mixed>> */
    public function groups(string $projectId): array
    {
        $this->liveProject($projectId);

        return array_map(TaskPresenter::group(...), $this->board->groupsFor($projectId));
    }

    /**
     * @param array<string, mixed> $data `name`, optional `color`
     * @return array<string, mixed>
     */
    public function createGroup(string $projectId, array $data): array
    {
        $this->liveProject($projectId);
        $existing = $this->board->groupsFor($projectId);
        if (count($existing) >= self::MAX_GROUPS) {
            throw new TaskInvalid('A project can have at most ' . self::MAX_GROUPS . ' groups');
        }
        $now = $this->time->now();
        $last = $existing === [] ? -1 : max(array_map(static fn (TaskGroup $g): int => $g->sortOrder, $existing));
        $group = new TaskGroup(
            TaskRules::newId(),
            $projectId,
            (string) TaskRules::text($data, 'name', 200, true),
            self::color($data),
            $last + 1,
            $now,
            $now,
        );
        $this->board->insertGroup($group);

        return TaskPresenter::group($group);
    }

    /**
     * @param array<string, mixed> $data any of `name`, `color`, `sort_order`
     * @return array<string, mixed>
     */
    public function updateGroup(string $id, array $data): array
    {
        $group = $this->board->findGroup($id) ?? throw new TaskNotFound('Group not found');
        if (array_key_exists('name', $data)) {
            $group->name = (string) TaskRules::text($data, 'name', 200, true);
        }
        if (array_key_exists('color', $data)) {
            $group->color = self::color($data);
        }
        if (array_key_exists('sort_order', $data)) {
            $order = $data['sort_order'];
            if (!is_int($order) && !(is_string($order) && preg_match('/^-?\d{1,6}$/', $order) === 1)) {
                throw new TaskInvalid('Sort order must be a whole number');
            }
            $group->sortOrder = (int) $order;
        }
        $group->updatedDate = $this->time->now();
        $this->board->updateGroup($group);

        return TaskPresenter::group($group);
    }

    /**
     * The group goes; its tasks stay in the project, ungrouped (binned ones too, so the row can be removed).
     *
     * @return array<string, bool>
     */
    public function deleteGroup(string $id): array
    {
        $group = $this->board->findGroup($id) ?? throw new TaskNotFound('Group not found');

        return $this->board->transactional(function () use ($group): array {
            $now = $this->time->now();
            foreach ([false, true] as $binned) {
                foreach ($this->tasks->tasks($binned, null) as $task) {
                    if ($task->groupId === $group->id) {
                        $task->groupId = null;
                        $task->updatedDate = $now;
                        $this->tasks->updateTask($task);
                    }
                }
            }
            $this->board->deleteGroup($group->id);

            return ['deleted' => true];
        });
    }

    /**
     * The board's tasks in the order dragged (Nick Han, 20 Oct 2026: "can we move the task around by dragging"). Each
     * entry is one group (`group_id`, or null for the project's ungrouped tasks) and its tasks top to bottom; a task
     * named under another group moves to it. Only the groups sent change. A new place is the board's layout, not a
     * change to the task, so `updated_date` stays; a move into another group is a change of group, and the task's
     * history logs it, as it would a change made in the form (Nick Han, 21 Oct 2026), when the caller is known.
     *
     * @param array<string, mixed> $data `groups`: list of {group_id, task_ids}
     * @return array{updated: int}
     */
    public function orderTasks(string $projectId, array $data, ?TaskActor $actor = null): array
    {
        $this->liveProject($projectId);
        $raw = $data['groups'] ?? null;
        if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > self::MAX_GROUPS + 1) {
            throw new TaskInvalid('Send the groups as a list, each with its task_ids');
        }
        $known = [];
        foreach ($this->board->groupsFor($projectId) as $group) {
            $known[$group->id] = true;
        }
        $plan = [];
        $seenGroups = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !is_array($entry['task_ids'] ?? null) || !array_is_list($entry['task_ids'])) {
                throw new TaskInvalid('Each group needs its task_ids as a list');
            }
            $groupId = $entry['group_id'] ?? null;
            if ($groupId === '') {
                $groupId = null;
            }
            if ($groupId !== null && (!is_string($groupId) || !isset($known[$groupId]))) {
                throw new TaskInvalid('That group belongs to another project');
            }
            $key = $groupId ?? '';
            if (isset($seenGroups[$key])) {
                throw new TaskInvalid('A group is sent twice');
            }
            $seenGroups[$key] = true;
            foreach ($entry['task_ids'] as $position => $taskId) {
                if (!is_string($taskId) || isset($plan[$taskId])) {
                    throw new TaskInvalid('A task is sent twice, or its id is not text');
                }
                $plan[$taskId] = [$groupId, $position];
            }
        }
        if (count($plan) > self::MAX_TEMPLATE_TASKS) {
            throw new TaskInvalid('At most ' . self::MAX_TEMPLATE_TASKS . ' tasks can be ordered at once');
        }
        $tasks = $this->tasks->tasksByIds(array_map('strval', array_keys($plan)));
        $live = array_filter($tasks, static fn (TaskRecord $t): bool => $t->deletedAt === null && $t->projectId === $projectId);
        if (count($live) !== count($plan)) {
            throw new TaskInvalid('Some of these tasks are not on this board. Refresh the page and try again.');
        }

        return $this->board->transactional(function () use ($live, $plan, $actor): array {
            $updated = 0;
            $now = $this->time->now();
            foreach ($live as $task) {
                [$groupId, $position] = $plan[$task->id];
                if ($task->groupId === $groupId && $task->sortOrder === $position) {
                    continue;
                }
                if ($actor !== null) {
                    $this->taskService->recordGroupMove($actor, $task->id, $task->groupId, $groupId, $now);
                }
                $task->groupId = $groupId;
                $task->sortOrder = $position;
                $this->tasks->updateTask($task);
                ++$updated;
            }

            return ['updated' => $updated];
        });
    }

    // ==================================================================================
    // Project templates
    // ==================================================================================

    /** @return list<array<string, mixed>> */
    public function projectTemplates(): array
    {
        return array_map(TaskPresenter::projectTemplate(...), $this->board->projectTemplates());
    }

    /** @return array<string, mixed> */
    public function projectTemplate(string $id): array
    {
        return TaskPresenter::projectTemplate($this->template($id));
    }

    /**
     * @param array<string, mixed> $data `name`, optional `description`, `color`, `groups`
     * @return array<string, mixed>
     */
    public function createProjectTemplate(TaskActor $actor, array $data): array
    {
        $now = $this->time->now();
        $template = new TaskProjectTemplate(
            TaskRules::newId(),
            (string) TaskRules::text($data, 'name', 200, true),
            TaskRules::text($data, 'description') ?? '',
            self::color($data),
            $this->cleanGroups($data['groups'] ?? []),
            $actor->userId,
            $now,
            $now,
        );
        $this->board->insertProjectTemplate($template);

        return TaskPresenter::projectTemplate($template);
    }

    /**
     * @param array<string, mixed> $data any of `name`, `description`, `color`, `groups` (a whole new breakdown)
     * @return array<string, mixed>
     */
    public function updateProjectTemplate(string $id, array $data): array
    {
        $template = $this->template($id);
        if (array_key_exists('name', $data)) {
            $template->name = (string) TaskRules::text($data, 'name', 200, true);
        }
        if (array_key_exists('description', $data)) {
            $template->description = TaskRules::text($data, 'description') ?? '';
        }
        if (array_key_exists('color', $data)) {
            $template->color = self::color($data);
        }
        if (array_key_exists('groups', $data)) {
            $template->groups = $this->cleanGroups($data['groups']);
        }
        $template->updatedDate = $this->time->now();
        $this->board->updateProjectTemplate($template);

        return TaskPresenter::projectTemplate($template);
    }

    /** @return array<string, bool> */
    public function deleteProjectTemplate(string $id): array
    {
        $this->template($id);
        $this->board->deleteProjectTemplate($id);

        return ['deleted' => true];
    }

    /**
     * A new project from a template: the project, its groups in order, and every task, in one transaction.
     *
     * @param array<string, mixed> $data `name`, `start_date` (default today), optional `description`, `color`,
     *                                   `status`, `target_date`, `entity_id` (every task's entity)
     * @return array{project: array<string, mixed>, groups: list<array<string, mixed>>, tasks_created: int}
     */
    public function applyProjectTemplate(TaskActor $actor, string $id, array $data): array
    {
        $template = $this->template($id);
        $start = TaskRules::date($data['start_date'] ?? null) ?? $this->time->today();
        $entityId = isset($data['entity_id']) && is_string($data['entity_id']) && $data['entity_id'] !== '' ? $data['entity_id'] : null;
        $members = $this->memberMap();

        return $this->board->transactional(function () use ($actor, $template, $data, $start, $entityId, $members): array {
            $project = $this->setupService->createProject($actor, [
                'name' => $data['name'] ?? $template->name,
                'description' => $data['description'] ?? $template->description,
                'color' => $data['color'] ?? ($template->color ?? ''),
                'status' => $data['status'] ?? 'active',
                'target_date' => $data['target_date'] ?? null,
            ]);
            $projectId = (string) $project['id'];
            $now = $this->time->now();
            $groups = [];
            $created = 0;
            foreach ($template->groups as $order => $spec) {
                $group = new TaskGroup(TaskRules::newId(), $projectId, (string) ($spec['name'] ?? ''), self::nullableString($spec['color'] ?? null), $order, $now, $now);
                $this->board->insertGroup($group);
                $groups[] = TaskPresenter::group($group);
                foreach (is_array($spec['tasks'] ?? null) ? $spec['tasks'] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $preparer = self::memberOrNull($item['preparer_id'] ?? null, $members);
                    $reviewer = self::memberOrNull($item['reviewer_id'] ?? null, $members) ?? $this->defaultReviewer($preparer, $members);
                    $offset = $item['due_offset_days'] ?? null;
                    $this->taskService->create($actor, [
                        'name' => $item['name'] ?? '',
                        'description' => $item['description'] ?? '',
                        'requirements' => $item['requirements'] ?? '',
                        'category' => $item['category'] ?? 'routine',
                        'finance_statement_area' => $item['finance_statement_area'] ?? '',
                        'priority' => $item['priority'] ?? null,
                        'preparer_id' => $preparer,
                        'reviewer_id' => $reviewer,
                        'due_date' => is_int($offset) ? self::addDays($start, $offset) : null,
                        'due_date_requested' => ($item['due_date_requested'] ?? false) === true,
                        'project_id' => $projectId,
                        'group_id' => $group->id,
                        'entity_id' => $entityId,
                        'status' => TaskRules::DEFAULT_STATUS,
                    ]);
                    ++$created;
                }
            }

            // Read again so the counts include the tasks just made.
            foreach ($this->setupService->listProjects($actor) as $fresh) {
                if ($fresh['id'] === $projectId) {
                    $project = $fresh;
                }
            }

            return ['project' => $project, 'groups' => $groups, 'tasks_created' => $created];
        });
    }

    /**
     * A template from a project as it stands: its groups in order with their live tasks, and the project's ungrouped
     * tasks in a last group, "Other tasks". Due dates become offsets from `start_date` (default: the earliest due date
     * among those tasks).
     *
     * @param array<string, mixed> $data `name`, optional `description`, `start_date`
     * @return array<string, mixed>
     */
    public function saveProjectAsTemplate(TaskActor $actor, string $projectId, array $data): array
    {
        $project = $this->liveProject($projectId);
        $tasks = array_values(array_filter($this->tasks->tasks(false, null), static fn (TaskRecord $t): bool => $t->projectId === $projectId));
        usort($tasks, static fn (TaskRecord $a, TaskRecord $b): int => [$a->createdDate, $a->id] <=> [$b->createdDate, $b->id]);
        $dues = array_values(array_filter(array_map(static fn (TaskRecord $t): ?string => $t->dueDate, $tasks)));
        $start = TaskRules::date($data['start_date'] ?? null) ?? ($dues === [] ? null : min($dues));

        $byGroup = [];
        foreach ($tasks as $task) {
            $byGroup[$task->groupId ?? ''][] = $task;
        }
        $groups = [];
        foreach ($this->board->groupsFor($projectId) as $group) {
            $groups[] = ['name' => $group->name, 'color' => $group->color, 'tasks' => array_map(fn (TaskRecord $t): array => self::templateTask($t, $start), $byGroup[$group->id] ?? [])];
            unset($byGroup[$group->id]);
        }
        $rest = array_merge(...array_values($byGroup));
        if ($rest !== []) {
            $groups[] = ['name' => self::UNGROUPED, 'color' => null, 'tasks' => array_map(fn (TaskRecord $t): array => self::templateTask($t, $start), $rest)];
        }

        return $this->createProjectTemplate($actor, [
            'name' => $data['name'] ?? $project->name,
            'description' => $data['description'] ?? $project->description,
            'color' => $project->color,
            'groups' => $groups,
        ]);
    }

    /**
     * The default reviewer (item 5, decided 13 Oct 2026): the first Manager by name who is not the preparer, else the
     * first such Administrator, else none.
     *
     * @param array<int, TaskMember> $members
     */
    public function defaultReviewer(?int $preparer, ?array $members = null): ?int
    {
        $members ??= $this->memberMap();
        foreach ([TaskMember::ROLE_MANAGER, TaskMember::ROLE_ADMIN] as $role) {
            $candidates = array_values(array_filter($members, static fn (TaskMember $m): bool => $m->accessRole === $role && $m->userId !== $preparer));
            usort($candidates, static fn (TaskMember $a, TaskMember $b): int => [mb_strtolower($a->name), $a->userId] <=> [mb_strtolower($b->name), $b->userId]);
            if ($candidates !== []) {
                return $candidates[0]->userId;
            }
        }

        return null;
    }

    // ==================================================================================
    // Rules
    // ==================================================================================

    private function liveProject(string $id): TaskProject
    {
        $project = $this->setup->findProject($id);
        if ($project === null || $project->deletedAt !== null) {
            throw new TaskNotFound('Project not found');
        }

        return $project;
    }

    private function template(string $id): TaskProjectTemplate
    {
        return $this->board->findProjectTemplate($id) ?? throw new TaskNotFound('Project template not found');
    }

    /** @return array<int, TaskMember> */
    private function memberMap(): array
    {
        $out = [];
        foreach ($this->members->members() as $member) {
            $out[$member->userId] = $member;
        }

        return $out;
    }

    /**
     * A template's breakdown, checked and normalised to the stored shape.
     *
     * @return list<array<string, mixed>>
     */
    private function cleanGroups(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new TaskInvalid('Groups must be a list');
        }
        if (count($raw) > self::MAX_GROUPS) {
            throw new TaskInvalid('A template can have at most ' . self::MAX_GROUPS . ' groups');
        }
        $members = $this->memberMap();
        $total = 0;
        $out = [];
        foreach ($raw as $g => $group) {
            if (!is_array($group)) {
                throw new TaskInvalid('Group ' . ($g + 1) . ' must be an object');
            }
            $name = TaskRules::text($group, 'name', 200, true);
            $items = $group['tasks'] ?? [];
            if (!is_array($items) || !array_is_list($items)) {
                throw new TaskInvalid('The tasks of "' . $name . '" must be a list');
            }
            $tasks = [];
            foreach ($items as $i => $item) {
                if (!is_array($item)) {
                    throw new TaskInvalid('Task ' . ($i + 1) . ' of "' . $name . '" must be an object');
                }
                $tasks[] = $this->cleanTemplateTask($item, $members, (string) $name);
            }
            $total += count($tasks);
            if ($total > self::MAX_TEMPLATE_TASKS) {
                throw new TaskInvalid('A template can have at most ' . self::MAX_TEMPLATE_TASKS . ' tasks');
            }
            $out[] = ['name' => $name, 'color' => self::color($group), 'tasks' => $tasks];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, TaskMember> $members
     * @return array<string, mixed>
     */
    private function cleanTemplateTask(array $item, array $members, string $groupName): array
    {
        $name = (string) TaskRules::text($item, 'name', 300, true);
        $where = ' ("' . $name . '" in "' . $groupName . '")';
        $category = $item['category'] ?? 'routine';
        if (!is_string($category) || !in_array($category, TaskRules::CATEGORIES, true)) {
            throw new TaskInvalid('Category must be routine or ad_hoc' . $where);
        }
        $priority = $item['priority'] ?? null;
        if ($priority === '') {
            $priority = null;
        }
        if ($priority !== null && (!is_string($priority) || !in_array($priority, TaskRules::PRIORITIES, true))) {
            throw new TaskInvalid('Priority must be critical, high, medium or low' . $where);
        }
        $people = [];
        foreach (['preparer_id', 'reviewer_id'] as $key) {
            $id = TaskRules::userId($item[$key] ?? null);
            if ($id !== null && !isset($members[$id])) {
                throw new TaskInvalid('The preparer and reviewer must be members of Tri-Task' . $where);
            }
            $people[$key] = $id === null ? null : (string) $id;
        }
        $offset = $item['due_offset_days'] ?? null;
        if ($offset === '' || $offset === null) {
            $offset = null;
        } elseif (is_int($offset) || (is_string($offset) && preg_match('/^-?\d{1,4}$/', trim($offset)) === 1)) {
            $offset = (int) $offset;
            if (abs($offset) > self::MAX_OFFSET_DAYS) {
                throw new TaskInvalid('Due days must be within ' . self::MAX_OFFSET_DAYS . ' of the start' . $where);
            }
        } else {
            throw new TaskInvalid('Due days must be a whole number of days after the start' . $where);
        }

        return [
            'name' => $name,
            'description' => TaskRules::text($item, 'description') ?? '',
            'requirements' => TaskRules::text($item, 'requirements') ?? '',
            'category' => $category,
            'finance_statement_area' => TaskRules::text($item, 'finance_statement_area', 300) ?? '',
            'priority' => $priority,
            'preparer_id' => $people['preparer_id'],
            'reviewer_id' => $people['reviewer_id'],
            'due_offset_days' => $offset,
            'due_date_requested' => TaskRules::bool($item['due_date_requested'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private static function templateTask(TaskRecord $t, ?string $start): array
    {
        $offset = null;
        if ($start !== null && $t->dueDate !== null && $t->dueDate !== '') {
            $offset = (int) (new DateTimeImmutable($start, new DateTimeZone('UTC')))->diff(new DateTimeImmutable($t->dueDate, new DateTimeZone('UTC')))->format('%r%a');
        }

        return [
            'name' => $t->name,
            'description' => $t->description,
            'requirements' => $t->requirements,
            'category' => $t->category,
            'finance_statement_area' => $t->financeStatementArea,
            'priority' => $t->priority,
            'preparer_id' => TaskPresenter::person($t->preparerUserId),
            'reviewer_id' => TaskPresenter::person($t->reviewerUserId),
            'due_offset_days' => $offset,
            'due_date_requested' => $t->dueDateRequested,
        ];
    }

    /** @param array<int, TaskMember> $members */
    private static function memberOrNull(mixed $id, array $members): ?int
    {
        $userId = is_string($id) && ctype_digit($id) ? (int) $id : (is_int($id) ? $id : null);

        return $userId !== null && isset($members[$userId]) ? $userId : null;
    }

    private static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /** @param array<string, mixed> $data */
    private static function color(array $data): ?string
    {
        $color = TaskRules::text($data, 'color', 20);

        return $color === null || $color === '' ? null : $color;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
