<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use DateTimeImmutable;

/**
 * What a person may still reach after they leave TriCab.
 *
 * ## The decision
 *
 * Owner decision, 21 September 2026, answering open question 3: **a departed
 * person keeps read access to their own onboarding records for fourteen days
 * after they leave.** After that the window closes and they are refused.
 *
 * Fourteen days is a *departure grace window*, and it is the only thing this
 * class decides. Two people ask for it and both are served by it: someone who
 * wants their own signed induction paperwork on the way out, and HR, who would
 * otherwise field that request by hand for every leaver.
 *
 * ## Read, not write
 *
 * The window is read-only. {@see maySubmit()} turns false the moment the
 * departure date passes, before the read window closes. Somebody who has left
 * must not be able to hand in a form, acknowledge a policy or sit an
 * assessment — evidence dated after a person's last day is worse than no
 * evidence, because it looks like evidence.
 *
 * ## What this class deliberately does NOT decide
 *
 * **How long the records are kept.** They are two different clocks and
 * conflating them would be the expensive kind of mistake. Onboarding holds
 * employee records — signed deeds, acknowledgements, assessment evidence — and
 * under the Fair Work Act 2009 (Cth) s.535 and reg 3.31–3.44 an employer must
 * keep employee records for **seven years**. Deleting an induction record
 * fourteen days after somebody leaves would destroy evidence the business is
 * required to hold, and would destroy it precisely in the case where it is
 * most likely to be asked for.
 *
 * So: the retention figure per data class is still owed by the owner, it is
 * measured in years rather than days, and until it is stated the retention job
 * deletes nothing. `onboarding.RetentionClasses.RetentionDays` is nullable for
 * exactly that reason — NULL means "no figure decided; keep".
 *
 * ## The part that is not built yet
 *
 * A grace window needs the person to be able to sign in during it, and today
 * they cannot: deactivating an account sets `identity.Users` to `disabled`,
 * `AllowsLogin = 0`, and `assertAuthenticationAllowed()` refuses the login
 * before any Onboarding permission is consulted. This class states the rule;
 * honouring it needs a platform identity change — a departed person who keeps
 * login while holding nothing but the self-service three — which is a change to
 * shared authentication and is not made here. Recorded in the plan under Phase
 * 2.2 and in `docs/onboarding-architecture.md` §5.
 *
 * Until that lands, the practical effect of this policy is on the HR side: the
 * fourteen days are the window in which HR can still produce a leaver's records
 * to them, and the window that retention tooling must never shorten.
 *
 * Pure: no database, no request, no clock. The caller supplies `now`.
 */
final class LeaverPolicy
{
    /**
     * Days of read access after departure. Owner decision, 21 Sep 2026.
     *
     * Mirrored by the computed column `AccessEndsAtUtc` on
     * `onboarding.TraineeProfiles`, which is there so "whose window has closed"
     * is an indexed query rather than a table scan through PHP.
     * `LeaverPolicyMatchesSchemaTest` asserts the two agree, because a constant
     * changed in one place and not the other is a silent authorization change.
     */
    public const ACCESS_WINDOW_DAYS = 14;

    /**
     * When the read window shuts for somebody who departed at this instant.
     *
     * The window is inclusive of the departure instant and exclusive of this
     * one: at exactly `accessEndsAt()` the person is refused.
     */
    public static function accessEndsAt(DateTimeImmutable $departedAtUtc): DateTimeImmutable
    {
        return $departedAtUtc->modify(sprintf('+%d days', self::ACCESS_WINDOW_DAYS));
    }

    /**
     * Has this person left yet?
     *
     * A departure date in the future is the ordinary case — HR records a last
     * day as soon as it is known, often weeks ahead — and somebody serving out
     * notice is a current employee with an induction they may still be
     * finishing.
     */
    public static function hasDeparted(
        ?DateTimeImmutable $departedAtUtc,
        DateTimeImmutable $nowUtc,
    ): bool {
        if ($departedAtUtc === null) {
            return false;
        }

        return $nowUtc >= $departedAtUtc;
    }

    /**
     * May this person still read their own onboarding records?
     *
     * Never granted for anyone else's — ownership is resolved from the
     * authenticated `UserId` by the repository, and a record belonging to
     * somebody else answers 404 whether or not either party has left.
     */
    public static function mayReadOwnRecords(
        ?DateTimeImmutable $departedAtUtc,
        DateTimeImmutable $nowUtc,
    ): bool {
        if (!self::hasDeparted($departedAtUtc, $nowUtc)) {
            return true;
        }

        /** @var DateTimeImmutable $departedAtUtc — hasDeparted() is false for null */
        return $nowUtc < self::accessEndsAt($departedAtUtc);
    }

    /**
     * May this person still submit work?
     *
     * False from the departure instant. The read window does not extend this:
     * a leaver reads what they already did, and adds nothing to it.
     */
    public static function maySubmit(
        ?DateTimeImmutable $departedAtUtc,
        DateTimeImmutable $nowUtc,
    ): bool {
        return !self::hasDeparted($departedAtUtc, $nowUtc);
    }

    /**
     * Whether the grace window has run out — the state in which a departed
     * person is refused their own records.
     *
     * The negation of {@see mayReadOwnRecords()}, named for the side a
     * retention or notification job actually asks about.
     */
    public static function hasWindowClosed(
        ?DateTimeImmutable $departedAtUtc,
        DateTimeImmutable $nowUtc,
    ): bool {
        return !self::mayReadOwnRecords($departedAtUtc, $nowUtc);
    }

    /**
     * Whole days of read access left, for telling somebody how long they have.
     *
     * 0 once the window has closed, and 0 for a person who has not departed —
     * ask {@see hasDeparted()} to tell those two apart, because "no days left"
     * and "no countdown running" are different states and a UI that renders
     * both as "0 days remaining" is lying to one of them.
     */
    public static function daysRemaining(
        ?DateTimeImmutable $departedAtUtc,
        DateTimeImmutable $nowUtc,
    ): int {
        if (!self::hasDeparted($departedAtUtc, $nowUtc)) {
            return 0;
        }

        /** @var DateTimeImmutable $departedAtUtc — hasDeparted() is false for null */
        $endsAt = self::accessEndsAt($departedAtUtc);

        if ($nowUtc >= $endsAt) {
            return 0;
        }

        return (int) ceil(($endsAt->getTimestamp() - $nowUtc->getTimestamp()) / 86400);
    }
}
