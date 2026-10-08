<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskSetupStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskEntity;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Domain\TaskProject;
use TriCab\Core\Finance\Task\Domain\TaskStatementArea;
use TriCab\Core\Finance\Task\Domain\TaskTemplate;

/**
 * The four reference lists: projects, templates, statement areas and entities (routes 20 to
 * 38), from `store.py`'s `_clean_*`, `create_*`, `update_*`, `delete_*`, `restore_project` and
 * `bulk_create_areas`, with the source's validations and messages.
 *
 * Differences, all from decision Q4: the source checked a statement area's and an entity's
 * name only in `bulk_create_areas` (or not at all), so two areas or two entities could share
 * a name; now each name is unique, and creating or renaming into a clash is a 409.
 *
 * Gates (the route's one permission) are the actions'; this class holds the rules that need
 * the record. Template-create-task (route 29) is not ported.
 */
final class TaskSetupService
{
    public function __construct(
        private readonly TaskSetupStore $store,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly TaskTime $time,
    ) {
    }

    // ==================================================================================
    // Projects
    // ==================================================================================

    /**
     * @return list<array<string, mixed>> the live projects, or the Bin, with task counts over
     *         the tasks this caller may see
     */
    public function listProjects(TaskActor $actor, bool $deleted = false): array
    {
        $counts = $this->store->projectTaskCounts($this->visibleTo($actor));

        return array_map(
            static fn (TaskProject $p): array => TaskPresenter::project($p, $counts[$p->id] ?? [0, 0]),
            $this->store->projects($deleted),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createProject(TaskActor $actor, array $data): array
    {
        $fields = $this->cleanProject($data, false, null);
        $now = $this->time->now();
        $project = new TaskProject(
            TaskRules::newId(),
            (string) $fields['name'],
            (string) ($fields['description'] ?? ''),
            (string) ($fields['color'] ?? ''),
            (string) ($fields['status'] ?? 'planning'),
            (string) ($fields['target_date'] ?? ''),
            null,
            null,
            $now,
            $now,
        );
        $this->store->insertProject($project);

        return $this->projectDict($actor, $project);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateProject(TaskActor $actor, string $id, array $data): array
    {
        $project = $this->store->findProject($id);
        if ($project === null || $project->deletedAt !== null) {
            throw new TaskNotFound('Project not found');
        }
        $fields = $this->cleanProject($data, true, $project->id);
        foreach ($fields as $key => $value) {
            match ($key) {
                'name' => $project->name = (string) $value,
                'description' => $project->description = (string) $value,
                'color' => $project->color = (string) $value,
                'status' => $project->status = (string) $value,
                'target_date' => $project->targetDate = (string) $value,
                default => null,
            };
        }
        $project->updatedDate = $this->time->now();
        $this->store->updateProject($project);

        return $this->projectDict($actor, $project);
    }

    /**
     * Soft delete into the Bin; `$permanent` only from the Bin and only when no task (live or
     * binned) belongs to it.
     *
     * @return array<string, bool>
     */
    public function deleteProject(TaskActor $actor, string $id, bool $permanent = false): array
    {
        $project = $this->store->findProject($id);
        if ($project === null) {
            throw new TaskNotFound('Project not found');
        }
        if ($permanent) {
            if ($project->deletedAt === null) {
                throw new TaskConflict('Move the project to the Bin before deleting it permanently');
            }
            $used = $this->store->projectTaskCount($project->id);
            if ($used > 0) {
                throw new TaskConflict($used . ' task(s) still belong to this project', ['count' => $used]);
            }
            $this->store->deleteProject($project->id);

            return ['deleted' => true, 'permanent' => true];
        }
        if ($project->deletedAt === null) {
            $project->deletedAt = $this->time->now();
            $project->deletedBy = $actor->userId;
            $this->store->updateProject($project);
        }

        return ['deleted' => true, 'permanent' => false];
    }

    /** @return array<string, mixed> */
    public function restoreProject(TaskActor $actor, string $id): array
    {
        $project = $this->store->findProject($id);
        if ($project === null) {
            throw new TaskNotFound('Project not found');
        }
        if ($this->store->liveProjectNameTaken($project->name, $project->id)) {
            throw new TaskConflict('A live project already has that name; rename one first');
        }
        $project->deletedAt = null;
        $project->deletedBy = null;
        $project->updatedDate = $this->time->now();
        $this->store->updateProject($project);

        return $this->projectDict($actor, $project);
    }

    /**
     * `_clean_project`: the validated fields that were sent (all of the required ones when not partial).
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function cleanProject(array $data, bool $partial, ?string $currentId): array
    {
        $out = [];
        if (array_key_exists('name', $data) || !$partial) {
            $name = (string) TaskRules::text($data, 'name', 200, true);
            if ($this->store->liveProjectNameTaken($name, $currentId)) {
                throw new TaskConflict('A project with that name already exists');
            }
            $out['name'] = $name;
        }
        if (array_key_exists('description', $data)) {
            $out['description'] = TaskRules::text($data, 'description') ?? '';
        }
        if (array_key_exists('color', $data)) {
            $out['color'] = TaskRules::text($data, 'color', 20) ?? '';
        }
        if (array_key_exists('status', $data)) {
            $status = $data['status'];
            if (!is_string($status) || !in_array($status, TaskRules::PROJECT_STATUSES, true)) {
                throw new TaskInvalid('Status must be planning, active, completed or on_hold');
            }
            $out['status'] = $status;
        }
        if (array_key_exists('target_date', $data)) {
            $out['target_date'] = TaskRules::date($data['target_date']) ?? '';
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function projectDict(TaskActor $actor, TaskProject $project): array
    {
        $counts = $this->store->projectTaskCounts($this->visibleTo($actor));

        return TaskPresenter::project($project, $counts[$project->id] ?? [0, 0]);
    }

    private function visibleTo(TaskActor $actor): ?int
    {
        return $actor->seesAll() ? null : $actor->userId;
    }

    // ==================================================================================
    // Templates
    // ==================================================================================

    /** @return list<array<string, mixed>> */
    public function listTemplates(): array
    {
        return array_map(TaskPresenter::template(...), $this->store->templates());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createTemplate(array $data): array
    {
        $fields = $this->cleanTemplate($data, false);
        $now = $this->time->now();
        $template = new TaskTemplate(
            TaskRules::newId(),
            (string) $fields['name'],
            self::str($fields, 'description'),
            self::str($fields, 'requirements'),
            self::str($fields, 'category', 'routine'),
            self::str($fields, 'finance_statement_area'),
            self::person($fields, 'preparer_user_id'),
            self::person($fields, 'reviewer_user_id'),
            self::integer($fields, 'default_progress'),
            self::nullableStr($fields, 'due_date'),
            ($fields['due_date_requested'] ?? false) === true,
            $now,
            $now,
        );
        $this->store->insertTemplate($template);

        return TaskPresenter::template($template);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateTemplate(string $id, array $data): array
    {
        $template = $this->store->findTemplate($id);
        if ($template === null) {
            throw new TaskNotFound('Template not found');
        }
        foreach ($this->cleanTemplate($data, true) as $key => $value) {
            match ($key) {
                'name' => $template->name = (string) $value,
                'description' => $template->description = (string) $value,
                'requirements' => $template->requirements = (string) $value,
                'category' => $template->category = (string) $value,
                'finance_statement_area' => $template->financeStatementArea = (string) $value,
                'default_progress' => $template->defaultProgress = is_int($value) ? $value : 0,
                'due_date' => $template->dueDate = is_string($value) ? $value : null,
                'due_date_requested' => $template->dueDateRequested = $value === true,
                'preparer_user_id' => $template->preparerUserId = is_int($value) ? $value : null,
                'reviewer_user_id' => $template->reviewerUserId = is_int($value) ? $value : null,
                default => null,
            };
        }
        $template->updatedDate = $this->time->now();
        $this->store->updateTemplate($template);

        return TaskPresenter::template($template);
    }

    /** @return array<string, bool> */
    public function deleteTemplate(string $id): array
    {
        if ($this->store->findTemplate($id) === null) {
            throw new TaskNotFound('Template not found');
        }
        $this->store->deleteTemplate($id);

        return ['deleted' => true];
    }

    /**
     * `_clean_template`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function cleanTemplate(array $data, bool $partial): array
    {
        $out = [];
        if (array_key_exists('name', $data) || !$partial) {
            $out['name'] = TaskRules::text($data, 'name', 300, true);
        }
        foreach (['description', 'requirements'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = TaskRules::text($data, $key) ?? '';
            }
        }
        if (array_key_exists('finance_statement_area', $data)) {
            $out['finance_statement_area'] = TaskRules::text($data, 'finance_statement_area', 300) ?? '';
        }
        if (array_key_exists('category', $data)) {
            $category = $data['category'];
            if (!is_string($category) || !in_array($category, TaskRules::CATEGORIES, true)) {
                throw new TaskInvalid('Category must be routine or ad_hoc');
            }
            $out['category'] = $category;
        }
        foreach (['progress', 'default_progress'] as $source) {
            if (array_key_exists($source, $data)) {
                $out['default_progress'] = TaskRules::progress($data[$source]);
            }
        }
        if (array_key_exists('due_date', $data)) {
            $out['due_date'] = TaskRules::date($data['due_date']);
        }
        if (array_key_exists('due_date_requested', $data)) {
            $out['due_date_requested'] = TaskRules::bool($data['due_date_requested']);
        }
        foreach (['preparer_id' => 'preparer_user_id', 'reviewer_id' => 'reviewer_user_id'] as $apiKey => $column) {
            if (array_key_exists($apiKey, $data)) {
                $userId = TaskRules::userId($data[$apiKey]);
                $this->requireMember($userId);
                $out[$column] = $userId;
            }
        }

        return $out;
    }

    /** `_check_members`: a named preparer or reviewer must be a Tri-Task member. */
    private function requireMember(?int $userId): void
    {
        if ($userId !== null && $this->members->find($userId) === null) {
            throw new TaskInvalid('The preparer and reviewer must be members of Tri-Task');
        }
    }

    // ==================================================================================
    // Statement areas
    // ==================================================================================

    /** @return list<array<string, mixed>> */
    public function listAreas(): array
    {
        return array_map(TaskPresenter::area(...), $this->store->areas());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createArea(array $data): array
    {
        $area = $this->newArea($this->cleanArea($data, false), $this->time->now());
        if ($this->store->areaNameTaken($area->name, null)) {
            throw new TaskConflict('A statement area with that name already exists');
        }
        $this->store->insertArea($area);

        return TaskPresenter::area($area);
    }

    /**
     * Up to 100 at once, skipping names that already exist (case-insensitive) or that repeat
     * earlier in the list. Everything is validated before anything is written, and the writes are
     * one transaction: one bad item creates none.
     *
     * @return list<array<string, mixed>> the areas created
     */
    public function bulkCreateAreas(mixed $items): array
    {
        if (!is_array($items) || $items === [] || !array_is_list($items)) {
            throw new TaskInvalid('Send a list of statement areas');
        }
        if (count($items) > TaskRules::MAX_BULK_AREAS) {
            throw new TaskInvalid('Up to ' . TaskRules::MAX_BULK_AREAS . ' areas at once');
        }
        $now = $this->time->now();
        $taken = [];
        foreach ($this->store->areas() as $existing) {
            $taken[mb_strtolower($existing->name)] = true;
        }
        $create = [];
        foreach ($items as $item) {
            $fields = $this->cleanArea(is_array($item) ? self::stringKeys($item) : [], false);
            $key = mb_strtolower((string) $fields['name']);
            if (isset($taken[$key])) {
                continue;
            }
            $taken[$key] = true;
            $create[] = $this->newArea($fields, $now);
        }

        return $this->store->transactional(function () use ($create): array {
            $out = [];
            foreach ($create as $area) {
                $this->store->insertArea($area);
                $out[] = TaskPresenter::area($area);
            }

            return $out;
        });
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateArea(string $id, array $data): array
    {
        $area = $this->store->findArea($id);
        if ($area === null) {
            throw new TaskNotFound('Statement area not found');
        }
        foreach ($this->cleanArea($data, true) as $key => $value) {
            match ($key) {
                'name' => $area->name = (string) $value,
                'category' => $area->category = (string) $value,
                'description' => $area->description = (string) $value,
                'sort_order' => $area->sortOrder = is_int($value) ? $value : 0,
                default => null,
            };
        }
        if ($this->store->areaNameTaken($area->name, $area->id)) {
            throw new TaskConflict('A statement area with that name already exists');
        }
        $area->updatedDate = $this->time->now();
        $this->store->updateArea($area);

        return TaskPresenter::area($area);
    }

    /** @return array<string, bool> */
    public function deleteArea(string $id): array
    {
        $area = $this->store->findArea($id);
        if ($area === null) {
            throw new TaskNotFound('Statement area not found');
        }
        $used = $this->store->areaLiveTaskCount($area->name);
        if ($used > 0) {
            throw new TaskConflict($used . ' task(s) still use this area', ['count' => $used]);
        }
        $this->store->deleteArea($area->id);

        return ['deleted' => true];
    }

    /**
     * `_clean_area`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function cleanArea(array $data, bool $partial): array
    {
        $out = [];
        if (array_key_exists('name', $data) || !$partial) {
            $out['name'] = TaskRules::text($data, 'name', 300, true);
        }
        if (array_key_exists('category', $data)) {
            $category = $data['category'];
            if (!is_string($category) || !in_array($category, TaskRules::STATEMENT_CATEGORIES, true)) {
                throw new TaskInvalid('Unknown statement category');
            }
            $out['category'] = $category;
        }
        if (array_key_exists('description', $data)) {
            $out['description'] = TaskRules::text($data, 'description') ?? '';
        }
        if (array_key_exists('sort_order', $data)) {
            $out['sort_order'] = self::wholeNumber($data['sort_order']);
        }

        return $out;
    }

    /** @param array<string, mixed> $fields */
    private function newArea(array $fields, DateTimeImmutable $now): TaskStatementArea
    {
        return new TaskStatementArea(
            TaskRules::newId(),
            (string) $fields['name'],
            self::str($fields, 'category', 'other'),
            self::str($fields, 'description'),
            self::integer($fields, 'sort_order'),
            $now,
            $now,
        );
    }

    /** Python's `int(value)`: a whole number, a float cut towards zero, or an integer string. */
    private static function wholeNumber(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return (int) $value;
        }
        if (is_float($value) && is_finite($value)) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1) {
            return (int) trim($value);
        }

        throw new TaskInvalid('Sort order must be a whole number');
    }

    // ==================================================================================
    // Entities
    // ==================================================================================

    /** @return list<array<string, mixed>> */
    public function listEntities(): array
    {
        return array_map(TaskPresenter::entity(...), $this->store->entities());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createEntity(array $data): array
    {
        $fields = $this->cleanEntity($data, false);
        $now = $this->time->now();
        $entity = new TaskEntity(
            TaskRules::newId(),
            (string) $fields['name'],
            self::str($fields, 'code'),
            self::str($fields, 'description'),
            self::str($fields, 'color'),
            ($fields['is_active'] ?? true) === true,
            $now,
            $now,
        );
        if ($this->store->entityNameTaken($entity->name, null)) {
            throw new TaskConflict('An entity with that name already exists');
        }
        $this->store->insertEntity($entity);

        return TaskPresenter::entity($entity);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateEntity(string $id, array $data): array
    {
        $entity = $this->store->findEntity($id);
        if ($entity === null) {
            throw new TaskNotFound('Entity not found');
        }
        foreach ($this->cleanEntity($data, true) as $key => $value) {
            match ($key) {
                'name' => $entity->name = (string) $value,
                'code' => $entity->code = (string) $value,
                'description' => $entity->description = (string) $value,
                'color' => $entity->color = (string) $value,
                'is_active' => $entity->isActive = $value === true,
                default => null,
            };
        }
        if ($this->store->entityNameTaken($entity->name, $entity->id)) {
            throw new TaskConflict('An entity with that name already exists');
        }
        $entity->updatedDate = $this->time->now();
        $this->store->updateEntity($entity);

        return TaskPresenter::entity($entity);
    }

    /** @return array<string, bool> */
    public function deleteEntity(string $id): array
    {
        $entity = $this->store->findEntity($id);
        if ($entity === null) {
            throw new TaskNotFound('Entity not found');
        }
        $used = $this->store->entityTaskCount($entity->id);
        if ($used > 0) {
            throw new TaskConflict($used . ' task(s) still use this entity', ['count' => $used]);
        }
        $this->store->deleteEntity($entity->id);

        return ['deleted' => true];
    }

    /**
     * `_clean_entity`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function cleanEntity(array $data, bool $partial): array
    {
        $out = [];
        if (array_key_exists('name', $data) || !$partial) {
            $out['name'] = TaskRules::text($data, 'name', 200, true);
        }
        if (array_key_exists('code', $data)) {
            $out['code'] = TaskRules::text($data, 'code', 40) ?? '';
        }
        if (array_key_exists('description', $data)) {
            $out['description'] = TaskRules::text($data, 'description') ?? '';
        }
        if (array_key_exists('color', $data)) {
            $out['color'] = TaskRules::text($data, 'color', 20) ?? '';
        }
        if (array_key_exists('is_active', $data)) {
            $out['is_active'] = TaskRules::bool($data['is_active']);
        }

        return $out;
    }

    // ==================================================================================
    // Small helpers for the validated field arrays
    // ==================================================================================

    /** @param array<string, mixed> $fields */
    private static function str(array $fields, string $key, string $default = ''): string
    {
        $value = $fields[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $fields */
    private static function nullableStr(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $fields */
    private static function integer(array $fields, string $key): int
    {
        $value = $fields[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /** @param array<string, mixed> $fields */
    private static function person(array $fields, string $key): ?int
    {
        $value = $fields[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $row
     * @return array<string, mixed>
     */
    private static function stringKeys(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
