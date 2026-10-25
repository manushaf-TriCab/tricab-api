<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskDrafter;
use TriCab\Core\Finance\Task\Contract\TaskSetupStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskDraftRequest;
use TriCab\Core\Finance\Task\Domain\TaskFailure;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;

/**
 * "Create with AI" (Nick Han, 23 Oct 2026): a pasted or dictated summary becomes a **draft** of one new project
 * and/or tasks. Nothing is written here. The page shows the draft for the person to edit and then creates it
 * through the ordinary project and task endpoints, so every rule, permission and notification of those applies.
 *
 * The model is given the lists it must pick from (members, entities, live projects, project groups, priorities,
 * types, statuses) and today's date in the task time zone. What comes back is untrusted: this class keeps only
 * ids that are in those lists, only values from the fixed vocabularies, and only real dates; resolves "Friday" and
 * "end of month" itself ({@see TaskDueDates}); offers a new project only to someone who may create one
 * (`FINANCE.TASK.SETUP.MANAGE`); and caps the number of tasks. Anything it drops that the person would want to
 * know about becomes a warning on the draft.
 */
final class TaskDraftService
{
    public const MAX_TEXT_CHARS = 12000;
    public const MAX_TASKS = 30;

    private const MAX_NAME = 300;
    private const MAX_PROJECT_NAME = 200;
    private const MAX_LONG_TEXT = 2000;

    /** The statuses a drafted task may start in: Done is the reviewer's to give, never a draft's. */
    private const DRAFT_STATUSES = ['active', 'on_hold'];

    public function __construct(
        private readonly TaskDrafter $drafter,
        private readonly TaskSetupStore $setup,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly TaskTime $time,
    ) {
    }

    /**
     * @param array<string, mixed> $data `{text}`
     * @return array{project: ?array<string, mixed>, tasks: list<array<string, mixed>>, warnings: list<string>, injection_suspected: bool, model: string}
     * @throws TaskFailure
     */
    public function draft(TaskActor $actor, array $data): array
    {
        $text = TaskRules::strip(is_string($data['text'] ?? null) ? $data['text'] : '');
        if ($text === '') {
            throw new TaskInvalid('Write or dictate a summary first');
        }
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            throw new TaskInvalid('That is too long: keep it to ' . self::MAX_TEXT_CHARS . ' characters, or split it');
        }

        $request = $this->request($actor, $text);
        $raw = $this->drafter->draft($request);

        return $this->checked($request, $raw);
    }

    private function request(TaskActor $actor, string $text): TaskDraftRequest
    {
        $today = $this->time->today();
        $members = [];
        foreach ($this->members->members() as $member) {
            $members[] = ['id' => (string) $member->userId, 'name' => $member->name !== '' ? $member->name : $member->email, 'job_title' => $member->jobTitle];
        }
        $entities = [];
        foreach ($this->setup->entities() as $entity) {
            if ($entity->isActive) {
                $entities[] = ['id' => $entity->id, 'name' => $entity->name, 'code' => $entity->code];
            }
        }
        $projects = [];
        foreach ($this->setup->projects(false) as $project) {
            $projects[] = ['id' => $project->id, 'name' => $project->name];
        }
        $groups = [];
        foreach ($this->setup->projectGroups() as $group) {
            $groups[] = ['id' => $group->id, 'name' => $group->name];
        }

        return new TaskDraftRequest(
            $text,
            $today,
            (new DateTimeImmutable($today, new DateTimeZone('UTC')))->format('l'),
            $this->time->zoneName(),
            $members,
            $entities,
            $projects,
            $groups,
            TaskRules::PRIORITIES,
            TaskRules::CATEGORIES,
            self::DRAFT_STATUSES,
            $actor->can(Module::PERMISSION_TASK_SETUP_MANAGE),
            $actor->historyName(),
        );
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{project: ?array<string, mixed>, tasks: list<array<string, mixed>>, warnings: list<string>, injection_suspected: bool, model: string}
     */
    private function checked(TaskDraftRequest $request, array $raw): array
    {
        $dates = new TaskDueDates($request->today);
        $warnings = [];
        $memberIds = array_column($request->members, 'id');
        $entityIds = array_column($request->entities, 'id');
        $projectIds = array_column($request->projects, 'id');
        $groupIds = array_column($request->projectGroups, 'id');

        // The new project: only for someone who may create one, and not a name a live project already has.
        $project = null;
        $existingForNew = null;
        $proposed = is_array($raw['project'] ?? null) ? $raw['project'] : null;
        if ($proposed !== null) {
            $name = self::short($proposed['name'] ?? null, self::MAX_PROJECT_NAME);
            if ($name !== '') {
                if (!$request->mayCreateProject) {
                    $warnings[] = 'You cannot create projects, so no new project was drafted. Ask a Manager to create "' . $name . '", or pick an existing project for the tasks.';
                } else {
                    foreach ($request->projects as $existing) {
                        if (mb_strtolower($existing['name']) === mb_strtolower($name)) {
                            $existingForNew = $existing['id'];
                            $warnings[] = 'A project called "' . $existing['name'] . '" already exists, so the tasks go in it instead of a new one.';
                            break;
                        }
                    }
                    if ($existingForNew === null) {
                        $groupId = self::id($proposed['group_id'] ?? null);
                        $project = [
                            'name' => $name,
                            'description' => self::short($proposed['description'] ?? null, self::MAX_LONG_TEXT),
                            'target_date' => $this->date($dates, $proposed['target_phrase'] ?? null, $proposed['target_date'] ?? null),
                            'group_id' => in_array($groupId, $groupIds, true) ? $groupId : null,
                        ];
                    }
                }
            }
        }

        $rows = is_array($raw['tasks'] ?? null) && array_is_list($raw['tasks']) ? $raw['tasks'] : [];
        $tasks = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = self::short($row['name'] ?? $row['title'] ?? null, self::MAX_NAME);
            if ($name === '') {
                continue;
            }
            if (count($tasks) >= self::MAX_TASKS) {
                $warnings[] = 'Only the first ' . self::MAX_TASKS . ' tasks were kept. Draft the rest separately.';
                break;
            }
            $notes = [];
            $projectId = self::id($row['project_id'] ?? null);
            $inNew = ($row['in_new_project'] ?? false) === true;
            if ($inNew && $project !== null) {
                $projectId = '';
            } elseif ($inNew && $existingForNew !== null) {
                $projectId = $existingForNew;
                $inNew = false;
            } else {
                $inNew = false;
            }
            if ($projectId !== '' && !in_array($projectId, $projectIds, true)) {
                $projectId = '';
            }
            $preparer = self::id($row['preparer_id'] ?? null);
            $reviewer = self::id($row['reviewer_id'] ?? null);
            $entity = self::id($row['entity_id'] ?? null);
            $priority = is_string($row['priority'] ?? null) ? $row['priority'] : '';
            $category = is_string($row['category'] ?? null) ? $row['category'] : '';
            $status = is_string($row['status'] ?? null) ? $row['status'] : '';
            $unmatched = [];
            foreach (is_array($row['unmatched_people'] ?? null) ? $row['unmatched_people'] : [] as $person) {
                $label = self::short($person, 80);
                if ($label !== '') {
                    $unmatched[] = $label;
                }
            }
            if (!in_array($preparer, $memberIds, true)) {
                $preparer = '';
            }
            if (!in_array($reviewer, $memberIds, true)) {
                $reviewer = '';
            }
            foreach (array_slice($unmatched, 0, 5) as $label) {
                $notes[] = 'Could not match "' . $label . '" to a Tri-Task member.';
            }

            $tasks[] = [
                'name' => $name,
                'description' => self::short($row['description'] ?? null, self::MAX_LONG_TEXT),
                'notes' => self::short($row['notes'] ?? null, self::MAX_LONG_TEXT),
                'project_id' => $projectId !== '' ? $projectId : null,
                'in_new_project' => $inNew,
                'entity_id' => in_array($entity, $entityIds, true) ? $entity : null,
                'preparer_id' => $preparer !== '' ? $preparer : null,
                'reviewer_id' => $reviewer !== '' ? $reviewer : null,
                'priority' => in_array($priority, $request->priorities, true) ? $priority : null,
                'category' => in_array($category, $request->categories, true) ? $category : 'routine',
                'status' => in_array($status, $request->statuses, true) ? $status : 'active',
                'due_date' => $this->date($dates, $row['due_phrase'] ?? null, $row['due_date'] ?? null),
                'warnings' => $notes,
            ];
        }

        if ($project === null && $tasks === []) {
            $warnings[] = 'No tasks or project could be found in that text. Add more detail, such as who does what and by when.';
        }

        return [
            'project' => $project,
            'tasks' => $tasks,
            'warnings' => $warnings,
            'injection_suspected' => ($raw['injection_suspected'] ?? false) === true,
            'model' => $this->drafter->modelName(),
        ];
    }

    /**
     * The phrase the person wrote wins when it can be read (the model's own date arithmetic is not trusted); else
     * the model's date, if it is real and not in the past; else none.
     */
    private function date(TaskDueDates $dates, mixed $phrase, mixed $iso): ?string
    {
        if (is_string($phrase) && trim($phrase) !== '') {
            $resolved = $dates->resolve($phrase);
            if ($resolved !== null) {
                return $resolved;
            }
        }
        if (is_string($iso) && $iso !== '') {
            $resolved = $dates->resolve($iso);
            if ($resolved !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($iso)) === 1 && $resolved >= $dates->today()) {
                return $resolved;
            }
        }

        return null;
    }

    private static function short(mixed $value, int $limit): string
    {
        if (!is_string($value)) {
            return '';
        }
        $text = TaskRules::strip($value);
        if (mb_strlen($text) > $limit) {
            $text = TaskRules::strip(mb_substr($text, 0, $limit));
        }

        return $text;
    }

    private static function id(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? trim($value) : '';
    }
}
