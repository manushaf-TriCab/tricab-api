<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use TriCab\Core\Onboarding\Domain\Exception\AssignmentStatusIsFinal;
use TriCab\Core\Onboarding\Domain\Exception\StatusChangeNotPermitted;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusActor;

/**
 * Who may move an assignment into which status.
 *
 * This is work order invariant 7, and it is the reason the module's records are
 * worth keeping: **nothing here lets a person mark their own induction
 * complete.** A trainee can say they are working, stuck, or done with their
 * part; only a reviewer can say the work is accepted.
 *
 * Pure — no database, no request, no clock. Ported from `SETTABLE_BY`,
 * `canLeave`, `canSet` and `assertCanSet` in the source's `api/_lib/status.ts`.
 *
 * ## What is deliberately *not* here
 *
 * There is no from → to transition graph beyond the finality rule. A trainee
 * may go straight from `not_started` to `submitted` without passing through
 * `in_progress`, and may re-submit work that is already submitted. That is the
 * source's behaviour and it is preserved rather than tightened, because the
 * looseness is load-bearing: a form filled in one sitting is submitted from
 * `not_started`, and a resubmission after a browser retry must not fail. If a
 * stricter graph is ever wanted it is a behaviour change, and belongs in the
 * work order's deviation list rather than in a quiet edit here.
 */
final class AssignmentStatusPolicy
{
    /**
     * Which statuses each actor may move an assignment *into*.
     *
     * Read the gaps as carefully as the entries:
     *
     *  - `trainee` cannot set `completed`. The whole point.
     *  - `reviewer` cannot set `need_help`. A reviewer raising a hand on
     *    somebody's behalf puts words in their mouth in a record that is later
     *    used as evidence; if a reviewer thinks someone is stuck, the reviewer
     *    sends the work back with a reason, which is attributable to them.
     *  - `system` may only open or complete. It opens an item when an attempt
     *    starts and completes one when an assessment is passed. It cannot
     *    submit on a person's behalf, and it cannot send work back — that is a
     *    judgement, and judgements have names attached.
     *  - Nobody may set `not_started`. Work does not un-begin; there is no path
     *    back to a state that claims nothing ever happened.
     *
     * @var array<string, list<string>>
     */
    private const SETTABLE_BY = [
        StatusActor::TRAINEE => [
            AssignmentStatus::IN_PROGRESS,
            AssignmentStatus::NEED_HELP,
            AssignmentStatus::SUBMITTED,
        ],
        StatusActor::REVIEWER => [
            AssignmentStatus::IN_PROGRESS,
            AssignmentStatus::FIX_AND_RESUBMIT,
            AssignmentStatus::COMPLETED,
        ],
        StatusActor::SYSTEM => [
            AssignmentStatus::IN_PROGRESS,
            AssignmentStatus::COMPLETED,
        ],
    ];

    /**
     * The statuses this actor may set at all, ignoring where the work is now.
     *
     * @return list<AssignmentStatus>
     */
    public static function settableBy(StatusActor $actor): array
    {
        return array_map(
            static fn (string $status): AssignmentStatus => AssignmentStatus::fromString($status),
            self::SETTABLE_BY[$actor->toString()],
        );
    }

    /**
     * Whether this actor may move the work out of where it is.
     *
     * Completed work is the only thing locked, and only a reviewer can reopen
     * it.
     */
    public static function canLeave(StatusActor $actor, AssignmentStatus $from): bool
    {
        if (!$from->isFinal()) {
            return true;
        }

        return $actor->isReviewer();
    }

    public static function canSet(
        StatusActor $actor,
        AssignmentStatus $from,
        AssignmentStatus $to,
    ): bool {
        if (!self::canLeave($actor, $from)) {
            return false;
        }

        return self::isSettableBy($actor, $to);
    }

    /**
     * The same check as a guard, throwing the exception that says which of the
     * two things went wrong. Order matters: "you may never set this" is
     * reported before "not from here", because the first is the more serious
     * answer and is true regardless of where the work sits.
     *
     * @throws StatusChangeNotPermitted when the actor may never set `$to` (→ 403)
     * @throws AssignmentStatusIsFinal  when `$from` is locked to this actor (→ 409)
     */
    public static function assertCanSet(
        StatusActor $actor,
        AssignmentStatus $from,
        AssignmentStatus $to,
    ): void {
        if (!self::isSettableBy($actor, $to)) {
            throw StatusChangeNotPermitted::forActor($actor, $to);
        }

        if (!self::canLeave($actor, $from)) {
            throw AssignmentStatusIsFinal::forActor($actor, $from);
        }
    }

    private static function isSettableBy(StatusActor $actor, AssignmentStatus $to): bool
    {
        return in_array($to->toString(), self::SETTABLE_BY[$actor->toString()], true);
    }
}
