<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocatableItem;
use TriCab\Core\Onboarding\Application\DTO\Admin\AllocationSelection;
use TriCab\Core\Onboarding\Application\DTO\Admin\CurrentAllocation;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingAdminEvent;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\OnboardingPersonSummary;
use TriCab\Core\Onboarding\Application\Service\AllocationPlanner;
use TriCab\Core\Onboarding\Application\Service\SetPersonAllocationService;
use TriCab\Core\Onboarding\Domain\Exception\AllocationNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingPersonNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingPeopleRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/**
 * Phase 4.2 — explicit allocation, as the work order's allocation matrix asks:
 *
 *  - explicit selections are authoritative;
 *  - untouched unselected assignments withdraw;
 *  - started/completed evidence survives unticking;
 *  - concurrent administrative edits fail safely;
 *
 * plus the two rules this port adds — a leaver is given nothing new, and a due
 * date on finished work does not move — and the audit trail each save owes.
 */
final class PersonAllocationTest extends TestCase
{
    private const INDUCTION = '11111111-1111-4111-8111-111111111111';
    private const SAFETY = '22222222-2222-4222-8222-222222222222';
    private const DETAILS = '33333333-3333-4333-8333-333333333333';
    private const PERSON = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private InMemoryOnboardingPeopleRepository $people;
    private InMemoryOnboardingAdminAuditLog $audit;
    private SetPersonAllocationService $service;
    private RowVersionToken $token;

    protected function setUp(): void
    {
        $this->people = new InMemoryOnboardingPeopleRepository();
        $this->audit = new InMemoryOnboardingAdminAuditLog();
        $this->token = RowVersionToken::fromBinary("\x00\x00\x00\x00\x00\x00\x07\xD1");
        $this->people->withCatalogue([
            new AllocatableItem('training', self::INDUCTION, 'Site induction', 1, 'v-ind', 2),
            new AllocatableItem('policy', self::SAFETY, 'Site safety', 2, 'v-saf', 4),
            new AllocatableItem('form', self::DETAILS, 'Employee details', 3, 'v-det', 1),
        ]);
        $this->withPerson(null);
        $this->service = new SetPersonAllocationService(
            $this->people,
            $this->audit,
            new FixedClock(new DateTimeImmutable('2026-09-23T00:00:00', new DateTimeZone('UTC'))),
            new UuidV4Generator(),
        );
    }

    // ----------------------------------------------------------- the planner

    public function test_explicit_selections_are_authoritative(): void
    {
        $plan = AllocationPlanner::plan(
            [self::pick('training', self::INDUCTION)],
            $this->people->listAllocatableItems(),
            [],
            null,
            self::now(),
        );

        self::assertCount(1, $plan->allocate);
        self::assertSame(self::INDUCTION, $plan->allocate[0]['item']->itemPublicId);
    }

    public function test_an_untouched_unselected_assignment_is_withdrawn(): void
    {
        $plan = AllocationPlanner::plan([], [], [self::held('policy', self::SAFETY, 'not_started', false)], null, self::now());

        self::assertCount(1, $plan->withdraw);
        self::assertSame([], $plan->kept);
    }

    /**
     * Evidence is what decides, not the status alone: a `not_started` item
     * with a saved draft has been touched.
     */
    public function test_started_or_evidenced_work_survives_unticking(): void
    {
        $plan = AllocationPlanner::plan([], [], [
            self::held('training', self::INDUCTION, 'in_progress', true),
            self::held('policy', self::SAFETY, 'completed', true),
            self::held('form', self::DETAILS, 'not_started', true),
        ], null, self::now());

        self::assertSame([], $plan->withdraw);
        self::assertCount(3, $plan->kept);
    }

    /**
     * Held at an older version still counts as held. Moving people onto new
     * wording is supersession's job, not the ticklist's.
     */
    public function test_an_item_already_held_at_any_version_is_not_allocated_again(): void
    {
        $plan = AllocationPlanner::plan(
            [self::pick('policy', self::SAFETY)],
            $this->people->listAllocatableItems(),
            [self::held('policy', self::SAFETY, 'completed', true)],
            null,
            self::now(),
        );

        self::assertTrue($plan->changesNothing());
    }

    public function test_something_not_on_offer_is_refused_by_name(): void
    {
        $this->expectException(AllocationNotPermitted::class);

        AllocationPlanner::plan([self::pick('training', self::DETAILS)], $this->people->listAllocatableItems(), [], null, self::now());
    }

    public function test_a_leaver_is_given_nothing_new_but_can_have_work_taken_away(): void
    {
        $gone = new DateTimeImmutable('2026-09-01T00:00:00', new DateTimeZone('UTC'));

        $plan = AllocationPlanner::plan([], [], [self::held('policy', self::SAFETY, 'not_started', false)], $gone, self::now());
        self::assertCount(1, $plan->withdraw);

        $this->expectException(AllocationNotPermitted::class);
        AllocationPlanner::plan([self::pick('policy', self::SAFETY)], $this->people->listAllocatableItems(), [], $gone, self::now());
    }

    /**
     * A future last day is somebody serving out notice, and they may still be
     * finishing their induction.
     */
    public function test_somebody_serving_notice_can_still_be_allocated(): void
    {
        $plan = AllocationPlanner::plan(
            [self::pick('policy', self::SAFETY)],
            $this->people->listAllocatableItems(),
            [],
            new DateTimeImmutable('2026-10-30T00:00:00', new DateTimeZone('UTC')),
            self::now(),
        );

        self::assertCount(1, $plan->allocate);
    }

    public function test_a_due_date_moves_on_unfinished_work_only(): void
    {
        $plan = AllocationPlanner::plan(
            [self::pick('training', self::INDUCTION, '2026-10-15'), self::pick('policy', self::SAFETY, '2026-10-15')],
            $this->people->listAllocatableItems(),
            [
                self::held('training', self::INDUCTION, 'in_progress', true),
                self::held('policy', self::SAFETY, 'completed', true),
            ],
            null,
            self::now(),
        );

        self::assertCount(1, $plan->dueDateChanges);
        self::assertSame('training', $plan->dueDateChanges[0]['allocation']->itemType);
        self::assertSame('2026-10-15', $plan->dueDateChanges[0]['toDueDate']);
    }

    public function test_a_selection_rejects_a_malformed_date_or_identifier(): void
    {
        foreach ([['training', 'not-a-uuid', null], ['training', self::INDUCTION, '2026-02-30'], ['cohort', self::INDUCTION, null]] as [$type, $id, $date]) {
            try {
                AllocationSelection::of($type, $id, $date);
                self::fail(sprintf('%s / %s / %s was accepted.', $type, $id, (string) $date));
            } catch (InvalidOnboardingValue) {
                self::addToAssertionCount(1);
            }
        }
    }

    // ----------------------------------------------------------- the service

    public function test_a_save_writes_each_change_and_its_audit_event(): void
    {
        $this->people->withAllocations(7, [self::held('form', self::DETAILS, 'not_started', false)]);

        $result = $this->service->set(self::actor(), self::PERSON, $this->token, [
            self::pick('training', self::INDUCTION, '2026-10-01'),
            self::pick('policy', self::SAFETY),
        ]);

        self::assertCount(2, $result['plan']->allocate);
        self::assertCount(1, $result['plan']->withdraw);

        $types = array_map(static fn (OnboardingAdminEvent $e) => $e->eventType, $this->people->writtenEvents);
        self::assertSame([
            AdminEventType::ALLOCATION_ALLOCATED,
            AdminEventType::ALLOCATION_ALLOCATED,
            AdminEventType::ALLOCATION_WITHDRAWN,
        ], $types);

        // Each allocated event names the assignment it created.
        $held = array_map(static fn (CurrentAllocation $a) => $a->assignmentPublicId, $result['detail']->allocations);
        foreach (array_slice($this->people->writtenEvents, 0, 2) as $event) {
            self::assertContains($event->targetPublicId, $held);
            self::assertSame(7, $event->subjectUserId);
            self::assertSame('ONBOARDING.PEOPLE.MANAGE', $event->permissionCode);
        }
    }

    public function test_the_same_save_twice_changes_nothing_the_second_time(): void
    {
        $selection = [self::pick('training', self::INDUCTION)];
        $first = $this->service->set(self::actor(), self::PERSON, $this->token, $selection);
        $events = count($this->people->writtenEvents);

        $second = $this->service->set(
            self::actor(),
            self::PERSON,
            RowVersionToken::fromBase64($first['detail']->rowVersion),
            $selection,
        );

        self::assertTrue($second['plan']->changesNothing());
        self::assertCount($events, $this->people->writtenEvents);
    }

    public function test_a_second_hr_user_holding_the_old_token_is_refused(): void
    {
        $this->service->set(self::actor(), self::PERSON, $this->token, [self::pick('training', self::INDUCTION)]);

        $this->expectException(OnboardingWriteConflict::class);
        $this->service->set(self::actor(), self::PERSON, $this->token, [self::pick('policy', self::SAFETY)]);
    }

    public function test_a_refusal_is_audited_and_nothing_is_written(): void
    {
        $this->withPerson(new DateTimeImmutable('2026-09-01T00:00:00', new DateTimeZone('UTC')));

        try {
            $this->service->set(self::actor(), self::PERSON, $this->token, [self::pick('policy', self::SAFETY)]);
            self::fail('A leaver was allocated new work.');
        } catch (AllocationNotPermitted) {
        }

        self::assertSame([], $this->people->writtenEvents);
        self::assertCount(1, $this->audit->entries);
        $event = $this->audit->entries[0]['event'];
        self::assertSame(AdminEventType::ALLOCATION_REFUSED, $event->eventType);
        self::assertSame(OnboardingAdminEvent::OUTCOME_REFUSED, $event->outcome);
        self::assertSame(['reason' => AllocationNotPermitted::REASON_DEPARTED], $event->details);
        self::assertSame(99, $this->audit->entries[0]['actorUserId']);
    }

    public function test_an_unknown_person_is_not_found(): void
    {
        $this->expectException(OnboardingPersonNotFound::class);

        $this->service->set(self::actor(), 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $this->token, []);
    }

    /**
     * Invariant 13: "without sensitive payloads". The allow-list is the
     * mechanism, so it is what is tested.
     */
    public function test_an_audit_detail_outside_the_allow_list_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OnboardingAdminEvent::succeeded(
            AdminEventType::ALLOCATION_ALLOCATED,
            7,
            null,
            'x',
            ['bankAccountNumber' => '012-345'],
            'ONBOARDING.PEOPLE.MANAGE',
        );
    }

    // --------------------------------------------------------------- helpers

    private function withPerson(?DateTimeImmutable $departedAtUtc): void
    {
        $this->people->withPerson(
            new OnboardingPersonRecord(7, self::PERSON, $this->token, $departedAtUtc),
            new OnboardingPersonSummary(self::PERSON, 'Sam Example', 'sam@example.test', null, null, null, null, null, null, []),
        );
    }

    private static function pick(string $type, string $id, ?string $due = null): AllocationSelection
    {
        return AllocationSelection::of($type, $id, $due);
    }

    private static function held(string $type, string $id, string $status, bool $evidence): CurrentAllocation
    {
        return new CurrentAllocation(1, 'as-' . $type, $type, $id, ucfirst($type), 1, AssignmentStatus::fromString($status), null, $evidence, '2026-09-01T00:00:00Z');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-23T00:00:00', new DateTimeZone('UTC'));
    }

    private static function actor(): ActorContext
    {
        return new ActorContext(99, 'actor-public-id', 'hr@example.test');
    }
}
