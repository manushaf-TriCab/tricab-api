<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewQueueEntry;
use TriCab\Core\Onboarding\Application\DTO\Contribution\FormContributionContext;
use TriCab\Core\Onboarding\Application\Service\ContributeToFormService;
use TriCab\Core\Onboarding\Application\Service\DecideReviewService;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Exception\ReviewDecisionNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\SelfReviewNotPermitted;
use TriCab\Core\Onboarding\Domain\Service\FormSchemaRules;
use TriCab\Core\Onboarding\Domain\Service\FormSubmissionRules;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingFormContributionRepository;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReviewRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Phase 5.4 — parts of a form filled by HR or the starter's named manager.
 *
 * Owner decision, 24 Sep 2026: HR fills any part; the named manager fills the
 * manager parts for their own starters. Held here: each role writes only its
 * own fields; a stranger manager gets "not found"; nobody fills their own
 * form's other parts; a completed form is closed; every contribution and
 * refusal is audited with field names, never values; and a reviewer cannot
 * accept a form whose required HR or manager parts are empty.
 */
final class FormContributionTest extends TestCase
{
    private const STARTER = 7;
    private const MANAGER = 20;
    private const OTHER_MANAGER = 21;
    private const HR = 40;
    private const FORM = 'aaaaaaaa-0000-4000-8000-000000000009';

    private const SCHEMA = ['fields' => [
        ['path' => 'uniformSize', 'label' => 'Uniform size', 'type' => 'text', 'required' => true],
        ['path' => 'siteTour', 'label' => 'Tour of site done', 'type' => 'checkbox', 'required' => true, 'filledBy' => 'hr'],
        ['path' => 'hrNotes', 'label' => 'HR notes', 'type' => 'textarea', 'required' => false, 'filledBy' => 'hr'],
        ['path' => 'managerSignOff', 'label' => 'Manager sign-off', 'type' => 'checkbox', 'required' => true, 'filledBy' => 'manager'],
    ]];

    private InMemoryOnboardingFormContributionRepository $repository;
    private InMemoryOnboardingAdminAuditLog $audit;
    private ContributeToFormService $service;

    protected function setUp(): void
    {
        $this->audit = new InMemoryOnboardingAdminAuditLog();
        $this->repository = new InMemoryOnboardingFormContributionRepository($this->audit);
        $this->service = new ContributeToFormService($this->repository, $this->audit);
        $this->withForm(AssignmentStatus::inProgress());
    }

    public function test_only_an_hr_form_can_have_parts_other_people_fill(): void
    {
        FormSchemaRules::normalise(self::SCHEMA['fields'], 'hr');

        $this->expectException(InvalidOnboardingValue::class);
        FormSchemaRules::normalise([['path' => 'x', 'label' => 'X', 'type' => 'text', 'filledBy' => 'hr', 'dataClass' => 3]], 'payroll');
    }

    public function test_the_starter_cannot_write_hr_or_manager_fields_and_is_not_asked_for_them(): void
    {
        FormSubmissionRules::check(self::SCHEMA, ['uniformSize' => 'M']);

        $this->expectException(InvalidOnboardingValue::class);
        FormSubmissionRules::check(self::SCHEMA, ['uniformSize' => 'M', 'managerSignOff' => true]);
    }

    public function test_hr_fills_the_hr_part_and_it_is_audited_by_field_name(): void
    {
        $this->service->contributeAsHr($this->actor(self::HR), self::FORM, ['siteTour' => true, 'hrNotes' => 'Tour with Pat, fire exits shown']);

        self::assertSame(['hr' => ['siteTour' => true, 'hrNotes' => 'Tour with Pat, fire exits shown']], $this->repository->latest(9));
        $event = $this->audit->entries[0]['event'];
        self::assertSame(AdminEventType::FORM_CONTRIBUTED, $event->eventType);
        self::assertStringNotContainsString('fire exits', (string) json_encode($event->details));
    }

    public function test_hr_cannot_write_the_managers_or_the_starters_fields(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        $this->service->contributeAsHr($this->actor(self::HR), self::FORM, ['managerSignOff' => true]);
    }

    public function test_the_named_manager_fills_the_manager_part(): void
    {
        $this->service->contributeAsManager($this->actor(self::MANAGER), self::FORM, ['managerSignOff' => true]);

        self::assertTrue($this->repository->latest(9)['manager']['managerSignOff']);
        self::assertCount(1, $this->repository->formsForManager(self::MANAGER));
        self::assertSame([], $this->repository->formsForManager(self::OTHER_MANAGER));
    }

    public function test_any_other_manager_is_told_it_does_not_exist(): void
    {
        $this->expectException(OnboardingAssignmentNotFound::class);
        $this->service->contributeAsManager($this->actor(self::OTHER_MANAGER), self::FORM, ['managerSignOff' => true]);
    }

    public function test_nobody_fills_the_other_parts_of_their_own_form(): void
    {
        try {
            $this->service->contributeAsHr($this->actor(self::STARTER), self::FORM, ['siteTour' => true]);
            self::fail('an HR user filled their own form');
        } catch (SelfReviewNotPermitted) {
        }

        self::assertSame([], $this->repository->rows);
        self::assertSame(AdminEventType::FORM_CONTRIBUTION_REFUSED, $this->audit->entries[0]['event']->eventType);
    }

    public function test_a_completed_form_is_closed(): void
    {
        $this->withForm(AssignmentStatus::completed());

        $this->expectException(OnboardingWriteConflict::class);
        $this->service->contributeAsHr($this->actor(self::HR), self::FORM, ['siteTour' => true]);
    }

    public function test_a_reviewer_cannot_accept_until_the_required_hr_and_manager_parts_are_filled(): void
    {
        $reviews = new InMemoryOnboardingReviewRepository();
        $token = RowVersionToken::fromBinary("\x00\x00\x00\x00\x00\x00\x0B\xB8");
        $reviews->withItem(
            new ReviewItemRecord(9, self::FORM, self::STARTER, 'form', 'item-form', 'Induction checklist', AssignmentStatus::submitted(), $token, 1),
            new ReviewQueueEntry(self::FORM, self::STARTER, 'person-7', 'Person 7', 'form', 'item-form', 'Induction checklist', 1, AssignmentStatus::submitted(), '2026-09-10T00:00:00Z', null, 1),
        );
        $decide = new DecideReviewService($reviews, $this->audit, $this->repository);
        $this->withForm(AssignmentStatus::submitted());

        try {
            $decide->decide($this->actor(self::HR), self::FORM, $token, AssignmentStatus::completed(), null);
            self::fail('completed with the HR and manager parts empty');
        } catch (ReviewDecisionNotPermitted $refused) {
            self::assertSame(ReviewDecisionNotPermitted::REASON_CONTRIBUTIONS_MISSING, $refused->reason);
            self::assertStringContainsString('Tour of site done', $refused->getMessage());
            self::assertStringContainsString('Manager sign-off', $refused->getMessage());
        }

        $this->service->contributeAsHr($this->actor(self::HR), self::FORM, ['siteTour' => true]);
        $this->service->contributeAsManager($this->actor(self::MANAGER), self::FORM, ['managerSignOff' => true]);

        self::assertSame('completed', $decide->decide($this->actor(self::HR), self::FORM, $token, AssignmentStatus::completed(), null)->status()->toString());
    }

    private function withForm(AssignmentStatus $status): void
    {
        $this->repository->withForm(new FormContributionContext(9, self::FORM, self::STARTER, self::MANAGER, 'Induction checklist', 'hr', $status, self::SCHEMA));
    }

    private function actor(int $userId): ActorContext
    {
        return new ActorContext($userId, 'public-' . $userId, $userId . '@example.test');
    }
}
