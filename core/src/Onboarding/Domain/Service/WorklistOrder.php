<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Application\DTO\OnboardingAssignmentView;

/**
 * The order a person's own induction is listed in.
 *
 * Deliberately NOT {@see \TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus::queueOrder()},
 * which is the reviewer's order and answers a different question. A reviewer
 * wants whoever is most stuck first, so `need_help` outranks everything. The
 * person doing the induction wants the opposite emphasis: what they can act on
 * now, soonest deadline first. Sorting a trainee's list by the reviewer's
 * priorities would put "needs help" above an item due tomorrow.
 *
 * Three bands, then two tie-breaks:
 *
 *  1. **Actionable** — not started, in progress, needs help, changes requested.
 *     Work only this person can move.
 *  2. **Waiting** — submitted. Nothing for them to do, but not finished either,
 *     and they should see it has not been forgotten.
 *  3. **Done** — completed.
 *
 * Within a band, by due date ascending with undated items LAST. An item with no
 * deadline is not urgent, and sorting nulls first — which is what a naive string
 * comparison does — buries the dated work underneath it. Then by allocation
 * time, then by public id.
 *
 * The last tie-break is what makes the order TOTAL, and that is the point of it
 * rather than tidiness: work order §8 requires deterministic ordering on every
 * list, and two items allocated in the same batch share a timestamp to the
 * microsecond often enough that "usually stable" means "reorders occasionally
 * for no reason the user can see".
 */
final class WorklistOrder
{
    private const BAND_ACTIONABLE = 0;
    private const BAND_WAITING = 1;
    private const BAND_DONE = 2;

    /**
     * @param list<OnboardingAssignmentView> $assignments
     * @return list<OnboardingAssignmentView>
     */
    public static function sort(array $assignments): array
    {
        usort($assignments, static function (OnboardingAssignmentView $a, OnboardingAssignmentView $b): int {
            return self::band($a) <=> self::band($b)
                ?: self::dueRank($a->dueDate) <=> self::dueRank($b->dueDate)
                ?: $a->allocatedAtUtc <=> $b->allocatedAtUtc
                ?: $a->publicId <=> $b->publicId;
        });

        return $assignments;
    }

    private static function band(OnboardingAssignmentView $assignment): int
    {
        if ($assignment->isActionable()) {
            return self::BAND_ACTIONABLE;
        }

        return $assignment->status->isFinal() ? self::BAND_DONE : self::BAND_WAITING;
    }

    /**
     * A sortable due date with undated work last.
     *
     * `~` sorts after every digit in ASCII, so an undated item falls to the
     * bottom of its band without a second comparison or a sentinel date that
     * somebody would later mistake for real data.
     */
    private static function dueRank(?string $dueDate): string
    {
        return $dueDate ?? '~';
    }
}
