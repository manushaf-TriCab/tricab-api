<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use DateTimeImmutable;
use TriCab\Core\Onboarding\Domain\Exception\AllocationNotPermitted;

/**
 * What may be allocated, and to whom.
 *
 * ## Allocation is explicit, and that is the whole design
 *
 * Work order invariant 8 and gap A9: nothing is granted by cohort, role, title
 * or department. Somebody decides that this person does this item, and that
 * decision is the only reason the row exists. The schema is built so it cannot
 * be otherwise — `AllocationSource` admits one value, and there is no `Cohorts`
 * table to derive anything from — and this class is the application half.
 *
 * The consequence worth stating, because it looks like a bug the first time
 * somebody sees it: **a person with nothing allocated sees an empty page.**
 * That is correct. It is not a fault to be worked around by deriving a default
 * pack from somebody's job title, and the first time that shortcut is taken,
 * allocation stops being evidence of a decision.
 *
 * Pure: no database, no request, no clock. The caller supplies `now`.
 */
final class AllocationPolicy
{
    /**
     * Whether this version of an item may be allocated at all.
     *
     * Draft and retired are separate refusals because they have different
     * remedies — publish it, or allocate the current one — and an HR user given
     * "cannot allocate" has to guess which.
     *
     * @throws AllocationNotPermitted
     */
    public static function assertVersionAllocatable(bool $isPublished, bool $isRetired): void
    {
        if (!$isPublished) {
            throw AllocationNotPermitted::notPublished();
        }

        if ($isRetired) {
            throw AllocationNotPermitted::retired();
        }
    }

    /**
     * Whether this person may be given new work.
     *
     * A leaver may not. {@see LeaverPolicy} already stops them submitting, so
     * allocating to them would create an item nobody can ever complete — and if
     * the departure date is later corrected, work dated after somebody's last
     * day. Serving out notice is not departure: a future last day is a current
     * employee who may still be finishing their induction.
     *
     * @throws AllocationNotPermitted
     */
    public static function assertPersonAllocatable(
        ?DateTimeImmutable $departedAtUtc,
        DateTimeImmutable $nowUtc,
    ): void {
        if (LeaverPolicy::hasDeparted($departedAtUtc, $nowUtc)) {
            throw AllocationNotPermitted::departed();
        }
    }

    /**
     * Whether this item may be allocated to this person again.
     *
     * A live allocation blocks a second one — that is the filtered unique index
     * in the schema, restated here so the refusal is a sentence rather than a
     * constraint violation. A *withdrawn* one does not: re-allocating after a
     * withdrawal is an ordinary thing to do, and the evidence of the first time
     * stays exactly where it is.
     *
     * @throws AllocationNotPermitted
     */
    public static function assertNotAlreadyAllocated(bool $hasLiveAllocation): void
    {
        if ($hasLiveAllocation) {
            throw AllocationNotPermitted::alreadyAllocated();
        }
    }

    /**
     * All three, in the order whose message is most useful first.
     *
     * Version before person before duplication: "that version is a draft" is
     * about the thing being allocated and is true regardless of who it was
     * aimed at, so reporting it first saves HR fixing the wrong end.
     *
     * @throws AllocationNotPermitted
     */
    public static function assertAllocatable(
        bool $isPublished,
        bool $isRetired,
        bool $hasLiveAllocation,
        ?DateTimeImmutable $departedAtUtc,
        DateTimeImmutable $nowUtc,
    ): void {
        self::assertVersionAllocatable($isPublished, $isRetired);
        self::assertPersonAllocatable($departedAtUtc, $nowUtc);
        self::assertNotAlreadyAllocated($hasLiveAllocation);
    }

    /**
     * The only value `Assignments.AllocationSource` accepts.
     *
     * Named rather than written as a literal at each call site, so that adding a
     * second source means changing this constant and watching what breaks.
     */
    public const SOURCE_EXPLICIT = 'explicit';
}
