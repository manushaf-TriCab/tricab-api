<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * What happens to people who are mid-way through an item when a new version of
 * it is published.
 *
 * ## The author decides, per version
 *
 * Owner decision, 21 September 2026. When publishing, the author says whether
 * this version supersedes work already in progress — the
 * `SupersedesWorkInProgress` flag on the version row.
 *
 * Both blanket rules were considered and neither survives contact:
 *
 *  - *Always move everyone* is the strongest compliance position — an
 *    acknowledgement then always refers to current wording — but it resets three
 *    people to zero because somebody fixed a comma on slide 34, and it teaches
 *    HR to avoid publishing.
 *  - *Never move anyone* is kind and simple, and it lets a person acknowledge a
 *    policy that has already been replaced. The evidence then says they agreed
 *    to wording that is no longer TriCab's, which is the one thing the
 *    acknowledgement exists to prove.
 *
 * Only the author knows whether the change is a comma or a new obligation, so
 * the author is asked. The default is `0` — do not disturb anybody — because a
 * default that resets people's work is a default that will be regretted, and
 * ticking a box is a smaller cost than un-resetting an induction.
 *
 * ## A completed assignment is never reissued
 *
 * Whatever the flag says. Somebody who finished the old version finished it, on
 * a date, against wording that is preserved on the version row they were
 * pointed at. Reopening that would rewrite history and put a finished item back
 * on a worklist that had legitimately emptied.
 *
 * Bringing completed holders onto new wording is a different act with a
 * different name — reallocating the new version to them, which is an explicit
 * allocation somebody decides and the audit trail records as such. It is not a
 * side effect of publishing.
 *
 * Pure: no database, no request, no clock.
 */
final class SupersessionPolicy
{
    /**
     * Should this assignment be moved onto the newly published version?
     *
     * @param bool            $supersedesWorkInProgress the flag the author set on the new version
     * @param AssignmentStatus $status                  where the person currently stands
     * @param bool            $isWithdrawn              a withdrawn allocation is nobody's work in progress
     */
    public static function shouldReissue(
        bool $supersedesWorkInProgress,
        AssignmentStatus $status,
        bool $isWithdrawn = false,
    ): bool {
        if (!$supersedesWorkInProgress) {
            return false;
        }

        if ($isWithdrawn) {
            return false;
        }

        // Finished is finished, whatever the author ticked.
        return !$status->isFinal();
    }

    /**
     * The status an assignment is reset to when it is reissued.
     *
     * `not_started`, always. The person is being handed different wording, and
     * carrying `submitted` across would claim they had handed in something they
     * have not read — which is precisely the claim the reissue exists to stop
     * anybody making.
     *
     * Note this is the one place `not_started` is written by the system rather
     * than set by an actor: `AssignmentStatusPolicy` refuses it for every actor,
     * on the grounds that work does not un-begin. Reissue is not work
     * un-beginning; it is a different item wearing the same name, and the
     * previous attempt survives on the old version's evidence.
     */
    public static function statusAfterReissue(): AssignmentStatus
    {
        return AssignmentStatus::notStarted();
    }

    /**
     * Does publishing this version leave everybody alone?
     *
     * The common case, and worth being able to say directly: a minor revision
     * disturbs nobody, and the console should be able to tell the author that
     * before they publish rather than after.
     */
    public static function isMinorRevision(bool $supersedesWorkInProgress): bool
    {
        return !$supersedesWorkInProgress;
    }
}
