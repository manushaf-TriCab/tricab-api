<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Onboarding\Domain\Exception\SelfReviewNotPermitted;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusActor;

/**
 * Nobody reviews their own onboarding.
 *
 * ## Why this class has to exist
 *
 * {@see AssignmentStatusPolicy} stops a *trainee* completing their own work.
 * That is enough only while reviewing is a separate job held by someone else.
 * TriCab has two Onboarding user types — admin and trainee — and an admin has
 * an induction of their own, so one person can legitimately hold
 * `ONBOARDING.SELF.SUBMIT` over their own record and `ONBOARDING.REVIEW` over
 * everyone's. Without this check they could hand in their own medical
 * questionnaire and accept it in the next request, and the record would say a
 * reviewer approved it.
 *
 * The source application has this hole: `api/_lib/handlers/admin.ts` calls
 * `requireAdmin()` and then `assertCanSet('reviewer', …)` with no comparison
 * between the reviewer and the subject, and `trainees.is_admin` sits on the
 * trainee's own row. It is not a criticism of the source — with a handful of
 * people who all know each other it never mattered. It matters as soon as the
 * evidence is used to answer a regulator asking who checked what.
 *
 * ## What this is not
 *
 * Not an ownership check. A person reaching *somebody else's* record is a
 * different fault with a different answer: the repository scopes every
 * self-service query by the authenticated `UserId` and returns **404**, because
 * confirming that another person's record exists is itself a disclosure. This
 * class only ever compares two ids that are both already known to the caller.
 *
 * Pure: no database, no request, no clock.
 */
final class SelfReviewPolicy
{
    /**
     * True when this actor is reviewing work belonging to the person acting.
     *
     * Only `reviewer` can self-review. A trainee acting on their own record is
     * the ordinary case and always allowed, and `system` acts for no person.
     */
    public static function isSelfReview(
        StatusActor $actor,
        UserId $actingUser,
        UserId $subject,
    ): bool {
        if (!$actor->isReviewer()) {
            return false;
        }

        return $actingUser->equals($subject);
    }

    /**
     * @throws SelfReviewNotPermitted
     */
    public static function assertNotSelfReview(
        StatusActor $actor,
        UserId $actingUser,
        UserId $subject,
    ): void {
        if (self::isSelfReview($actor, $actingUser, $subject)) {
            throw SelfReviewNotPermitted::forUser($actingUser);
        }
    }

    /**
     * Which actor a person is acting as on a given record, when they hold both
     * capabilities.
     *
     * Deliberately biased towards `trainee` on one's own record. Somebody
     * opening their own induction is doing their induction, not reviewing it,
     * and an admin who wants to review must be looking at somebody else. The
     * alternative — letting the caller declare which hat they are wearing —
     * makes the privilege the default for whoever asks nicely.
     */
    public static function actorFor(
        UserId $actingUser,
        UserId $subject,
        bool $holdsReviewPermission,
    ): StatusActor {
        if ($actingUser->equals($subject)) {
            return StatusActor::trainee();
        }

        if ($holdsReviewPermission) {
            return StatusActor::reviewer();
        }

        // Neither their own record nor a review permission. The caller should
        // never have got this far; the repository answers 404 before here.
        return StatusActor::trainee();
    }
}
