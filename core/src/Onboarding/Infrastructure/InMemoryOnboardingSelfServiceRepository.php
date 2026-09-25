<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Onboarding\Application\DTO\OnboardingAssignmentView;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTraineeRecord;
use TriCab\Core\Onboarding\Contract\OnboardingSelfServiceRepository;

/**
 * The default binding, and the one the HTTP tests run against.
 *
 * It is keyed by user id in both maps for the same reason the Azure SQL one
 * filters in SQL: a double that returns everything it holds regardless of who
 * asked would let a cross-person test pass against a repository that is broken.
 */
final class InMemoryOnboardingSelfServiceRepository implements OnboardingSelfServiceRepository
{
    /** @var array<int, OnboardingTraineeRecord> */
    private array $records = [];

    /** @var array<int, list<OnboardingAssignmentView>> */
    private array $assignments = [];

    public function findTraineeRecord(int $userId): ?OnboardingTraineeRecord
    {
        return $this->records[$userId] ?? null;
    }

    public function listAssignments(int $userId): array
    {
        return $this->assignments[$userId] ?? [];
    }

    public function withRecord(int $userId, OnboardingTraineeRecord $record): void
    {
        $this->records[$userId] = $record;
    }

    /**
     * @param list<OnboardingAssignmentView> $assignments
     */
    public function withAssignments(int $userId, array $assignments): void
    {
        $this->assignments[$userId] = $assignments;
    }

    public function clear(): void
    {
        $this->records = [];
        $this->assignments = [];
    }
}
