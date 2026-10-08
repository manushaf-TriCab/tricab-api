<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Contract;

use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskEntity;
use TriCab\Core\Finance\Task\Domain\TaskProject;
use TriCab\Core\Finance\Task\Domain\TaskStatementArea;
use TriCab\Core\Finance\Task\Domain\TaskTemplate;

/**
 * Storage for the four reference lists (T2.3): projects, templates, statement areas and
 * entities. The rules (validation, who may do what, what blocks a delete) are the
 * service's, so the SQL and in-memory adapters behave the same; the store only keeps rows
 * and answers counts.
 *
 * A unique name is also the database's: an insert or update that loses a race, or breaks
 * a filtered unique index (decision Q4), throws `TaskConflict` with the message the service
 * would have given. Names compare case-insensitively, as the database collation does.
 */
interface TaskSetupStore extends TaskTransactions
{
    // ---- projects ----------------------------------------------------------------------

    /**
     * Every project's name by id, binned ones too: a task keeps showing the name of the project it
     * belongs to.
     *
     * @return array<string, string>
     */
    public function projectNames(): array;

    /**
     * @return list<TaskProject> the live projects, or the Bin, by name
     */
    public function projects(bool $deleted): array;

    public function findProject(string $id): ?TaskProject;

    /** A **live** project other than `$exceptId` already has this name. */
    public function liveProjectNameTaken(string $name, ?string $exceptId): bool;

    /** @throws TaskConflict */
    public function insertProject(TaskProject $project): void;

    /** @throws TaskConflict */
    public function updateProject(TaskProject $project): void;

    public function deleteProject(string $id): void;

    /** Every task, live or binned, that belongs to the project. */
    public function projectTaskCount(string $id): int;

    /**
     * Live tasks per project: `[total, completed]`, over the tasks the caller may see
     * (`$visibleToUserId` null means all of them: a caller holding READ_ALL).
     *
     * @return array<string, array{0: int, 1: int}>
     */
    public function projectTaskCounts(?int $visibleToUserId): array;

    // ---- templates ---------------------------------------------------------------------

    /** @return list<TaskTemplate> by name */
    public function templates(): array;

    public function findTemplate(string $id): ?TaskTemplate;

    public function insertTemplate(TaskTemplate $template): void;

    public function updateTemplate(TaskTemplate $template): void;

    public function deleteTemplate(string $id): void;

    // ---- statement areas ---------------------------------------------------------------

    /** @return list<TaskStatementArea> by sort order, then name */
    public function areas(): array;

    public function findArea(string $id): ?TaskStatementArea;

    public function areaNameTaken(string $name, ?string $exceptId): bool;

    /** @throws TaskConflict */
    public function insertArea(TaskStatementArea $area): void;

    /** @throws TaskConflict */
    public function updateArea(TaskStatementArea $area): void;

    public function deleteArea(string $id): void;

    /** Live tasks whose statement area is this name. */
    public function areaLiveTaskCount(string $name): int;

    // ---- entities ----------------------------------------------------------------------

    /** @return list<TaskEntity> by name */
    public function entities(): array;

    public function findEntity(string $id): ?TaskEntity;

    public function entityNameTaken(string $name, ?string $exceptId): bool;

    /** @throws TaskConflict */
    public function insertEntity(TaskEntity $entity): void;

    /** @throws TaskConflict */
    public function updateEntity(TaskEntity $entity): void;

    public function deleteEntity(string $id): void;

    /** Every task, live or binned, filed under the entity. */
    public function entityTaskCount(string $id): int;
}
