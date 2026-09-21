<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Onboarding\Domain\Exception\SelfReviewNotPermitted;
use TriCab\Core\Onboarding\Domain\Service\AssignmentStatusPolicy;
use TriCab\Core\Onboarding\Domain\Service\SelfReviewPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusActor;
use TriCab\Core\Onboarding\Module;

/**
 * Nobody reviews their own onboarding.
 *
 * The situation this guards: one person on both sides of one record. Holding
 * `ONBOARDING.REVIEW` does not by itself mean "not the person who did the work",
 * and without this check somebody could hand in their own medical questionnaire
 * and accept it in the next request, leaving a record that says a reviewer
 * approved it.
 *
 * Since 21 Sep 2026 `ONBOARDING_ADMIN` carries no self-service permission —
 * TriCab's admins were inducted years ago — so nothing in today's deployment
 * reaches the check. It stays because the condition is about one person holding
 * two hats on one record, not about how a role happens to be configured, and an
 * HR hire who is themselves a new starter produces it without anyone editing a
 * role.
 */
final class SelfReviewPolicyTest extends TestCase
{
    private const HR = 7;
    private const SOMEBODY_ELSE = 42;

    public function test_an_admin_cannot_review_their_own_onboarding(): void
    {
        $this->expectException(SelfReviewNotPermitted::class);

        SelfReviewPolicy::assertNotSelfReview(
            StatusActor::reviewer(),
            UserId::fromInt(self::HR),
            UserId::fromInt(self::HR),
        );
    }

    public function test_the_refusal_says_what_to_do_about_it(): void
    {
        try {
            SelfReviewPolicy::assertNotSelfReview(
                StatusActor::reviewer(),
                UserId::fromInt(self::HR),
                UserId::fromInt(self::HR),
            );
            self::fail('Expected SelfReviewNotPermitted.');
        } catch (SelfReviewNotPermitted $exception) {
            self::assertStringContainsString('another administrator', $exception->getMessage());
            self::assertSame(self::HR, $exception->userId->toInt());
        }
    }

    public function test_an_admin_may_review_somebody_elses_onboarding(): void
    {
        SelfReviewPolicy::assertNotSelfReview(
            StatusActor::reviewer(),
            UserId::fromInt(self::HR),
            UserId::fromInt(self::SOMEBODY_ELSE),
        );

        self::assertFalse(SelfReviewPolicy::isSelfReview(
            StatusActor::reviewer(),
            UserId::fromInt(self::HR),
            UserId::fromInt(self::SOMEBODY_ELSE),
        ));
    }

    /**
     * The ordinary case, and the one it would be easy to break: doing your own
     * induction is not reviewing it.
     */
    public function test_a_trainee_working_on_their_own_record_is_not_self_review(): void
    {
        SelfReviewPolicy::assertNotSelfReview(
            StatusActor::trainee(),
            UserId::fromInt(self::HR),
            UserId::fromInt(self::HR),
        );

        self::assertFalse(SelfReviewPolicy::isSelfReview(
            StatusActor::trainee(),
            UserId::fromInt(self::HR),
            UserId::fromInt(self::HR),
        ));
    }

    /** `system` acts for no person, so it can never be self-review. */
    public function test_the_system_actor_is_never_self_review(): void
    {
        self::assertFalse(SelfReviewPolicy::isSelfReview(
            StatusActor::system(),
            UserId::fromInt(self::HR),
            UserId::fromInt(self::HR),
        ));
    }

    // ------------------------------------------------------- which hat they wear

    public function test_a_person_on_their_own_record_acts_as_a_trainee_even_holding_review(): void
    {
        $actor = SelfReviewPolicy::actorFor(
            UserId::fromInt(self::HR),
            UserId::fromInt(self::HR),
            holdsReviewPermission: true,
        );

        self::assertTrue($actor->isTrainee());
    }

    public function test_an_admin_on_somebody_elses_record_acts_as_a_reviewer(): void
    {
        $actor = SelfReviewPolicy::actorFor(
            UserId::fromInt(self::HR),
            UserId::fromInt(self::SOMEBODY_ELSE),
            holdsReviewPermission: true,
        );

        self::assertTrue($actor->isReviewer());
    }

    public function test_a_trainee_without_the_review_permission_never_becomes_a_reviewer(): void
    {
        $actor = SelfReviewPolicy::actorFor(
            UserId::fromInt(self::HR),
            UserId::fromInt(self::SOMEBODY_ELSE),
            holdsReviewPermission: false,
        );

        self::assertTrue($actor->isTrainee());
    }

    /**
     * The two rules composed, which is how the endpoints will use them: the
     * hat is derived, then the transition is checked. An admin cannot reach
     * `completed` on their own record by any route, because deriving the actor
     * makes them a trainee and a trainee cannot set it.
     */
    public function test_an_admin_cannot_complete_their_own_induction_by_any_route(): void
    {
        $actor = SelfReviewPolicy::actorFor(
            UserId::fromInt(self::HR),
            UserId::fromInt(self::HR),
            holdsReviewPermission: true,
        );

        self::assertFalse(AssignmentStatusPolicy::canSet(
            $actor,
            AssignmentStatus::submitted(),
            AssignmentStatus::completed(),
        ));
    }

    // ------------------------------------------------------------ the user types

    public function test_the_admin_role_is_the_only_one_holding_administrative_permissions(): void
    {
        self::assertSame('ONBOARDING_ADMIN', Module::ROLE_ADMIN);
        self::assertSame(['EMPLOYEE', 'GUEST'], Module::TRAINEE_ROLES);
    }

    /**
     * Owner decision, 21 Sep 2026: admins were inducted years ago and will not be
     * doing one, so the role means "runs onboarding for other people" and says
     * only that. It takes no capability away — every admin also holds `EMPLOYEE`,
     * which carries the self-service three.
     */
    public function test_the_admin_role_grants_no_self_service_permission(): void
    {
        self::assertSame(Module::HR_PERMISSIONS, Module::ADMIN_ROLE_PERMISSIONS);
        self::assertSame(
            [],
            array_intersect(Module::ADMIN_ROLE_PERMISSIONS, Module::SELF_SERVICE_PERMISSIONS),
        );
        self::assertCount(5, Module::ADMIN_ROLE_PERMISSIONS);
    }

    /** Self-service and administrative permissions must not overlap. */
    public function test_the_two_permission_sets_are_disjoint_and_complete(): void
    {
        self::assertSame(
            [],
            array_intersect(Module::SELF_SERVICE_PERMISSIONS, Module::HR_PERMISSIONS),
        );
        self::assertCount(
            8,
            array_unique(array_merge(Module::SELF_SERVICE_PERMISSIONS, Module::HR_PERMISSIONS)),
        );
    }
}
