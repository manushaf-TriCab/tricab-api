<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

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

    // ------------------------------------------------------------ 4.1 writes

    /**
     * What is recorded against this person, by kind, with only the non-zero
     * kinds present: `progress`, `attempts`, `acknowledgements`, `form_drafts`,
     * `form_submissions`, `documents`. Empty means the record can be deleted.
     * HR-held details are not counted: they come only from a submission, and
     * this repository never reads `[onboarding_hr]`.
     *
     * @return array<string, int>
     */
    public function recordedWork(int $userId): array;

    /**
     * Deletes the induction record — its allocations, its completion figures
     * and the profile — and appends `$event`, in one unit, but only while
     * {@see recordedWork()} is empty; that is checked again under lock inside
     * the unit. The account is not touched here.
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\PersonDeletionNotPermitted when work landed first
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict on a stale token
     */
    public function deletePerson(OnboardingPersonRecord $person, RowVersionToken $expectedRowVersion, OnboardingAdminEvent $event, ActorContext $actor): void;

    /** The induction record's public id for this account, if it has one. */
    public function findPersonPublicIdByUserId(int $userId): ?string;

    /**
     * Creates the induction record and appends its audit event in one unit.
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonAlreadyExists when the account already has one
     */
    public function createProfile(
        int $userId,
        string $publicId,
        OnboardingProfileFields $fields,
        OnboardingAdminEvent $event,
        ActorContext $actor,
    ): void;

    /**
     * @param list<OnboardingAdminEvent> $events
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict on a stale token
     */
    public function updateProfile(
        OnboardingPersonRecord $person,
        RowVersionToken $expectedRowVersion,
        OnboardingProfileFields $fields,
        ?DateTimeImmutable $departedAtUtc,
        bool $departureChanged,
        array $events,
        ActorContext $actor,
    ): void;

    /**
     * Stamps the invitation, unless one was sent within the cooldown — in one
     * statement, so two clicks cannot both win — and appends `$event` in the
     * same transaction. A lost click writes neither.
     *
     * @return ?array{previousInvitedAtUtc: ?string, previousInvitedByUserId: ?int} what to put back if the send fails; null when too soon
     */
    public function claimInvitation(OnboardingPersonRecord $person, int $cooldownSeconds, OnboardingAdminEvent $event, ActorContext $actor): ?array;

    /**
     * Puts the invitation stamp back after a send the provider refused, so the
     * record never says somebody was invited when they were not, and appends
     * `$event` (the refusal) in the same transaction.
     *
     * @param array{previousInvitedAtUtc: ?string, previousInvitedByUserId: ?int} $previous
     */
    public function releaseInvitation(OnboardingPersonRecord $person, array $previous, OnboardingAdminEvent $event, ActorContext $actor): void;
}
