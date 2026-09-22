<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocatableItem;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationPlan;
use TriCab\Core\Onboarding\Application\DTO\Admin\CurrentAllocation;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonDetail;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * HR's view of the people being inducted, and the one write that changes what
 * they hold.
 *
 * Every method is addressed by the induction record's public id, never by a
 * user id: a URL an HR user copies into a ticket should not carry the internal
 * key of somebody's platform account.
 */
interface OnboardingPeopleRepository
{
    /**
     * Ordered by display name, then public id, so a page boundary never moves
     * under somebody paging through it.
     *
     * @return array{items: list<OnboardingPersonSummary>, total: int}
     */
    public function listPeople(int $page, int $pageSize, ?string $search): array;

    public function findPerson(string $personPublicId): ?OnboardingPersonRecord;

    public function personDetail(string $personPublicId): ?OnboardingPersonDetail;

    /**
     * Active items with one published, live version each.
     *
     * @return list<AllocatableItem>
     */
    public function listAllocatableItems(): array;

    /**
     * @return list<CurrentAllocation>
     */
    public function listCurrentAllocations(int $userId): array;

    /**
     * Applies a plan atomically: the record's token is checked and moved, every
     * allocation, withdrawal and due-date change is written, and every event is
     * appended to the audit table — all or none.
     *
     * A withdrawal and a due-date change are re-checked in SQL as they are
     * written. If the trainee touched the work between the plan and the write,
     * the whole save is refused as a conflict rather than half-applied: the
     * events were built from the plan, and a skipped withdrawal would leave an
     * audit row saying it happened. The token covers two HR users; this covers
     * the trainee.
     *
     * @param array<string, string> $newAssignmentPublicIds item key => the public id to give its new assignment
     * @param list<OnboardingAdminEvent> $events
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict on a stale token
     */
    public function applyAllocation(
        OnboardingPersonRecord $person,
        RowVersionToken $expectedRowVersion,
        AllocationPlan $plan,
        array $newAssignmentPublicIds,
        array $events,
        ActorContext $actor,
    ): void;
}
