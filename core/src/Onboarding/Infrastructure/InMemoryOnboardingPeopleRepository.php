<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocatableItem;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationPlan;
use TriCab\Core\Onboarding\Application\DTO\Admin\CurrentAllocation;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Test double and the container default, as the other Onboarding repositories
 * are. Holds the plan's writes so a test can read back exactly what was done.
 */
final class InMemoryOnboardingPeopleRepository implements OnboardingPeopleRepository
{
    /** @var array<string, OnboardingPersonRecord> */
    private array $records = [];

    /** @var array<string, OnboardingPersonSummary> */
    private array $summaries = [];

    /** @var array<int, list<CurrentAllocation>> */
    private array $allocations = [];

    /** @var list<AllocatableItem> */
    private array $catalogue = [];

    /** @var list<OnboardingAdminEvent> */
    public array $writtenEvents = [];

    private int $nextAssignmentId = 1000;

    public function withPerson(OnboardingPersonRecord $record, OnboardingPersonSummary $summary): void
    {
        $this->records[$record->publicId] = $record;
        $this->summaries[$record->publicId] = $summary;
    }

    /**
     * @param list<CurrentAllocation> $allocations
     */
    public function withAllocations(int $userId, array $allocations): void
    {
        $this->allocations[$userId] = $allocations;
    }

    /**
     * @param list<AllocatableItem> $catalogue
     */
    public function withCatalogue(array $catalogue): void
    {
        $this->catalogue = $catalogue;
    }

    public function listPeople(int $page, int $pageSize, ?string $search): array
    {
        $matching = array_values(array_filter(
            $this->summaries,
            static fn (OnboardingPersonSummary $s): bool => $search === null
                || str_contains(mb_strtolower($s->displayName . ' ' . $s->emailAddress), mb_strtolower($search)),
        ));
        usort($matching, static fn (OnboardingPersonSummary $a, OnboardingPersonSummary $b): int
            => [$a->displayName, $a->publicId] <=> [$b->displayName, $b->publicId]);

        return [
            'items' => array_slice($matching, ($page - 1) * $pageSize, $pageSize),
            'total' => count($matching),
        ];
    }

    public function findPerson(string $personPublicId): ?OnboardingPersonRecord
    {
        return $this->records[$personPublicId] ?? null;
    }

    public function personDetail(string $personPublicId): ?OnboardingPersonDetail
    {
        $record = $this->records[$personPublicId] ?? null;
        $summary = $this->summaries[$personPublicId] ?? null;
        if ($record === null || $summary === null) {
            return null;
        }

        return new OnboardingPersonDetail(
            $summary,
            $record->rowVersion->toBase64(),
            $this->allocations[$record->userId] ?? [],
            $this->catalogue,
        );
    }

    public function listAllocatableItems(): array
    {
        return $this->catalogue;
    }

    public function listCurrentAllocations(int $userId): array
    {
        return $this->allocations[$userId] ?? [];
    }

    public function applyAllocation(
        OnboardingPersonRecord $person,
        RowVersionToken $expectedRowVersion,
        AllocationPlan $plan,
        array $newAssignmentPublicIds,
        array $events,
        ActorContext $actor,
    ): void {
        $stored = $this->records[$person->publicId] ?? null;
        if ($stored === null || !$stored->rowVersion->equals($expectedRowVersion)) {
            throw OnboardingWriteConflict::staleToken();
        }

        $withdrawn = array_map(static fn (CurrentAllocation $a) => $a->assignmentPublicId, $plan->withdraw);
        $changed = [];
        foreach ($plan->dueDateChanges as $change) {
            $changed[$change['allocation']->assignmentPublicId] = $change['toDueDate'];
        }

        $next = [];
        foreach ($this->allocations[$person->userId] ?? [] as $allocation) {
            if (in_array($allocation->assignmentPublicId, $withdrawn, true)) {
                continue;
            }
            if (array_key_exists($allocation->assignmentPublicId, $changed)) {
                $allocation = new CurrentAllocation(
                    $allocation->assignmentId,
                    $allocation->assignmentPublicId,
                    $allocation->itemType,
                    $allocation->itemPublicId,
                    $allocation->title,
                    $allocation->versionNumber,
                    $allocation->status,
                    $changed[$allocation->assignmentPublicId],
                    $allocation->hasEvidence,
                    $allocation->allocatedAtUtc,
                );
            }
            $next[] = $allocation;
        }

        foreach ($plan->allocate as $entry) {
            $item = $entry['item'];
            $next[] = new CurrentAllocation(
                $this->nextAssignmentId++,
                $newAssignmentPublicIds[$item->key()],
                $item->itemType,
                $item->itemPublicId,
                $item->title,
                $item->versionNumber,
                AssignmentStatus::notStarted(),
                $entry['dueDate'],
                false,
                '2026-09-23T00:00:00Z',
            );
        }

        $this->allocations[$person->userId] = $next;
        $this->writtenEvents = [...$this->writtenEvents, ...$events];

        // A new token, as ROWVERSION would give: anything holding the old one
        // is now stale.
        $this->records[$person->publicId] = new OnboardingPersonRecord(
            $stored->userId,
            $stored->publicId,
            RowVersionToken::fromBinary(random_bytes(8)),
            $stored->departedAtUtc,
        );
    }
}
