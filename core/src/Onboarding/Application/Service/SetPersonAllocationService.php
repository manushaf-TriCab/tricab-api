<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\Service;

use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationPlan;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationSelection;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonDetail;
use TriCab\Core\Onboarding\Contract\OnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Contract\OnboardingPeopleRepository;
use TriCab\Core\Onboarding\Domain\Exception\AllocationNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonNotFound;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Shared\Time\Clock;

/**
 * Saves the ticklist for one person (work order 4.2).
 *
 * The plan is worked out by {@see AllocationPlanner}; this service turns it
 * into audit events and hands both to the repository to write as one unit. A
 * refusal — a leaver, an item no longer on offer — is audited on its own and
 * then rethrown, so the attempt is on record even though nothing changed.
 *
 * A save that changes nothing writes nothing, not even an audit row: pressing
 * Save twice is not two decisions.
 */
final readonly class SetPersonAllocationService
{
    public const PERMISSION = 'ONBOARDING.PEOPLE.MANAGE';

    public function __construct(
        private OnboardingPeopleRepository $people,
        private OnboardingAdminAuditLog $audit,
        private Clock $clock,
        private UuidV4Generator $ids,
    ) {
    }

    /**
     * @param list<AllocationSelection> $selection
     *
     * @return array{detail: OnboardingPersonDetail, plan: AllocationPlan}
     */
    public function set(
        ActorContext $actor,
        string $personPublicId,
        RowVersionToken $expectedRowVersion,
        array $selection,
    ): array {
        $publicId = strtolower(trim($personPublicId));
        $person = $this->people->findPerson($publicId)
            ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);

        try {
            $plan = AllocationPlanner::plan(
                $selection,
                $this->people->listAllocatableItems(),
                $this->people->listCurrentAllocations($person->userId),
                $person->departedAtUtc,
                $this->clock->now(),
            );
        } catch (AllocationNotPermitted $refusal) {
            $this->audit->append($actor, OnboardingAdminEvent::refused(
                AdminEventType::ALLOCATION_REFUSED,
                $person->userId,
                $person->publicId,
                'Allocation refused: ' . $refusal->getMessage(),
                ['reason' => $refusal->reason],
                self::PERMISSION,
            ));

            throw $refusal;
        }

        if (!$plan->changesNothing()) {
            $newIds = [];
            foreach ($plan->allocate as $entry) {
                $newIds[$entry['item']->key()] = $this->ids->generate()->toString();
            }

            $this->people->applyAllocation(
                $person,
                $expectedRowVersion,
                $plan,
                $newIds,
                self::eventsFor($plan, $person->userId, $newIds),
                $actor,
            );
        }

        $detail = $this->people->personDetail($publicId)
            ?? throw OnboardingPersonNotFound::forPublicId($personPublicId);

        return ['detail' => $detail, 'plan' => $plan];
    }

    /**
     * @param array<string, string> $newIds
     *
     * @return list<OnboardingAdminEvent>
     */
    private static function eventsFor(AllocationPlan $plan, int $subjectUserId, array $newIds): array
    {
        $events = [];

        foreach ($plan->allocate as $entry) {
            $item = $entry['item'];
            $events[] = OnboardingAdminEvent::succeeded(
                AdminEventType::ALLOCATION_ALLOCATED,
                $subjectUserId,
                $newIds[$item->key()],
                sprintf('Allocated %s "%s" version %d.', $item->itemType, $item->title, $item->versionNumber),
                [
                    'itemType' => $item->itemType,
                    'itemPublicId' => $item->itemPublicId,
                    'versionPublicId' => $item->versionPublicId,
                    'versionNumber' => $item->versionNumber,
                    'dueDate' => $entry['dueDate'],
                ],
                self::PERMISSION,
            );
        }

        foreach ($plan->withdraw as $allocation) {
            $events[] = OnboardingAdminEvent::succeeded(
                AdminEventType::ALLOCATION_WITHDRAWN,
                $subjectUserId,
                $allocation->assignmentPublicId,
                sprintf('Withdrew untouched %s "%s".', $allocation->itemType, $allocation->title),
                [
                    'itemType' => $allocation->itemType,
                    'itemPublicId' => $allocation->itemPublicId,
                    'status' => $allocation->status->toString(),
                ],
                self::PERMISSION,
            );
        }

        foreach ($plan->dueDateChanges as $change) {
            $allocation = $change['allocation'];
            $events[] = OnboardingAdminEvent::succeeded(
                AdminEventType::ALLOCATION_DUE_DATE_CHANGED,
                $subjectUserId,
                $allocation->assignmentPublicId,
                sprintf('Changed the due date of %s "%s".', $allocation->itemType, $allocation->title),
                [
                    'itemType' => $allocation->itemType,
                    'itemPublicId' => $allocation->itemPublicId,
                    'fromDueDate' => $allocation->dueDate,
                    'toDueDate' => $change['toDueDate'],
                ],
                self::PERMISSION,
            );
        }

        return $events;
    }
}
