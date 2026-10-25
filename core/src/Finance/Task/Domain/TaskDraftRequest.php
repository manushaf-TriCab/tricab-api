<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

/**
 * What the drafter is given: the person's text (untrusted) and the lists it may pick from (ours). Ids are the
 * ones the create endpoints take, as text.
 */
final readonly class TaskDraftRequest
{
    /**
     * @param list<array{id: string, name: string, job_title: string}> $members
     * @param list<array{id: string, name: string, code: string}> $entities
     * @param list<array{id: string, name: string}> $projects live projects
     * @param list<array{id: string, name: string}> $projectGroups
     * @param list<string> $priorities
     * @param list<string> $categories task types
     * @param list<string> $statuses
     */
    public function __construct(
        public string $text,
        public string $today,
        public string $weekday,
        public string $timezone,
        public array $members,
        public array $entities,
        public array $projects,
        public array $projectGroups,
        public array $priorities,
        public array $categories,
        public array $statuses,
        public bool $mayCreateProject,
        public string $requesterName,
    ) {
    }
}
