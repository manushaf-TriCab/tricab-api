<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocatableItem;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationPlan;
use TriCab\Core\Onboarding\Application\DTO\Admin\CurrentAllocation;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingProfileFields;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonAlreadyExists;
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

    /** @var array<int, array{displayName: string, email: string}> */
    private array $accounts = [];

    /** @var array<string, ?string> */
    public array $invitedAt = [];

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

    // ------------------------------------------------------------ 4.1 writes

    /** The identity side a created profile would join to. */
    public function withAccount(int $userId, string $displayName, string $email): void
    {
        $this->accounts[$userId] = ['displayName' => $displayName, 'email' => $email];
    }

    public function findPersonPublicIdByUserId(int $userId): ?string
    {
        foreach ($this->records as $record) {
            if ($record->userId === $userId) {
                return $record->publicId;
            }
        }

        return null;
    }

    public function createProfile(int $userId, string $publicId, OnboardingProfileFields $fields, OnboardingAdminEvent $event, ActorContext $actor): void
    {
        if ($this->findPersonPublicIdByUserId($userId) !== null) {
            throw OnboardingPersonAlreadyExists::forPerson($this->findPersonPublicIdByUserId($userId));
        }
        $account = $this->accounts[$userId] ?? ['displayName' => '', 'email' => ''];
        $this->withPerson(
            new OnboardingPersonRecord($userId, $publicId, RowVersionToken::fromBinary(random_bytes(8)), null, $account['email'], $account['displayName']),
            new OnboardingPersonSummary($publicId, $account['displayName'], $account['email'], $fields->position, $fields->department, $fields->branch, $fields->startDate, null, null, []),
        );
        $this->writtenEvents[] = $event;
    }

    public function updateProfile(
        OnboardingPersonRecord $person,
        RowVersionToken $expectedRowVersion,
        OnboardingProfileFields $fields,
        ?DateTimeImmutable $departedAtUtc,
        bool $departureChanged,
        array $events,
        ActorContext $actor,
    ): void {
        $stored = $this->records[$person->publicId] ?? null;
        if ($stored === null || !$stored->rowVersion->equals($expectedRowVersion)) {
            throw OnboardingWriteConflict::staleToken();
        }
        $summary = $this->summaries[$person->publicId];
        $departed = $departureChanged ? $departedAtUtc : $stored->departedAtUtc;

        $this->records[$person->publicId] = new OnboardingPersonRecord($stored->userId, $stored->publicId, RowVersionToken::fromBinary(random_bytes(8)), $departed, $stored->emailAddress, $stored->displayName);
        $this->summaries[$person->publicId] = new OnboardingPersonSummary(
            $summary->publicId, $summary->displayName, $summary->emailAddress,
            $fields->position, $fields->department, $fields->branch, $fields->startDate,
            $departed?->format('Y-m-d\TH:i:s'), $summary->invitedAtUtc, $summary->statusCounts,
        );
        $this->writtenEvents = [...$this->writtenEvents, ...$events];
    }

    public function claimInvitation(OnboardingPersonRecord $person, int $cooldownSeconds, OnboardingAdminEvent $event, ActorContext $actor): ?array
    {
        $previous = $this->invitedAt[$person->publicId] ?? null;
        if ($previous !== null && (time() - (int) strtotime($previous)) < $cooldownSeconds) {
            return null;
        }
        $this->invitedAt[$person->publicId] = gmdate('Y-m-d\TH:i:s');
        $this->refreshInvited($person->publicId);
        $this->writtenEvents[] = $event;

        return ['previousInvitedAtUtc' => $previous, 'previousInvitedByUserId' => null];
    }

    public function releaseInvitation(OnboardingPersonRecord $person, array $previous, OnboardingAdminEvent $event, ActorContext $actor): void
    {
        $this->invitedAt[$person->publicId] = $previous['previousInvitedAtUtc'];
        $this->refreshInvited($person->publicId);
        $this->writtenEvents[] = $event;
    }

    private function refreshInvited(string $publicId): void
    {
        $s = $this->summaries[$publicId];
        $this->summaries[$publicId] = new OnboardingPersonSummary(
            $s->publicId, $s->displayName, $s->emailAddress, $s->position, $s->department, $s->branch,
            $s->startDate, $s->departedAtUtc, $this->invitedAt[$publicId] ?? null, $s->statusCounts,
        );
    }
}
