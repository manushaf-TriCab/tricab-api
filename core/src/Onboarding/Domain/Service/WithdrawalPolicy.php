<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\Exception\WithdrawalNotPermitted;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * Taking an item off somebody's plate without taking back what they did.
 *
 * ## Withdrawal is a date, never a DELETE
 *
 * HR decides a person no longer needs an induction item — their role changed,
 * the item was superseded by a different process, it was allocated to the wrong
 * person. Every attempt, acknowledgement and submission already made against it
 * stays exactly where it is: the tables holding them are append-only at the
 * database level, so this is not a rule the application could break even by
 * accident.
 *
 * What withdrawal changes is forward-looking. The item leaves the person's
 * worklist and the reviewer queue, and it stops being something anybody is
 * waiting on.
 *
 * ## A completed item stays completed
 *
 * Owner decision, 21 September 2026. Withdrawing something a person already
 * finished means "not required from here on". It does not mean "this did not
 * happen", so the completion projection keeps its row and the Skill Matrix
 * reading that projection does not forget a qualification because an allocation
 * was tidied up.
 *
 * The alternative — withdrawal erases the completion — was considered and not
 * taken. It is right for the one case where something was allocated in error,
 * and wrong for every other case, and the two are indistinguishable from the
 * action alone. If that case ever needs serving, it wants its own verb with its
 * own audit trail, not a reinterpretation of this one.
 *
 * Pure: no database, no request, no clock.
 */
final class WithdrawalPolicy
{
    /**
     * @throws WithdrawalNotPermitted
     */
    public static function assertWithdrawable(bool $isAlreadyWithdrawn): void
    {
        if ($isAlreadyWithdrawn) {
            throw WithdrawalNotPermitted::alreadyWithdrawn();
        }
    }

    /**
     * Whether withdrawing this assignment leaves a completion standing.
     *
     * True only for work that was actually finished. A withdrawn `submitted`
     * assignment is not a completion — nobody reviewed it — and recording one
     * would be inventing evidence.
     */
    public static function completionSurvives(AssignmentStatus $status): bool
    {
        return $status->isFinal();
    }

    /**
     * Whether the person's completion projection row should be removed.
     *
     * Always the negation of {@see completionSurvives()}, and written as its own
     * question because the projection is what leaves this module. A caller
     * reasoning about Skill Matrix should not have to invert a sentence about
     * withdrawal to find out what Skill Matrix will see.
     */
    public static function removesFromCompletionFeed(AssignmentStatus $status): bool
    {
        return !self::completionSurvives($status);
    }

    /**
     * Withdrawal never touches evidence. Stated as a method because it is the
     * property the whole class exists to guarantee, and a constant that is
     * asserted in a test is harder to quietly change than a comment.
     */
    public static function preservesEvidence(): bool
    {
        return true;
    }
}
