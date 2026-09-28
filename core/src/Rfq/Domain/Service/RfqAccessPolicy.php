<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Service;

use TriCab\Core\Rfq\Domain\ValueObject\RfqAction;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\RfqOwnership;
use TriCab\Core\Rfq\Domain\ValueObject\RfqScope;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowActor;
use TriCab\Core\Rfq\Module;

/**
 * Whose RFQs an actor may see, and what they may do to each one.
 *
 * Pure. Every endpoint that returns or changes an RFQ asks this on the
 * server, and a list query applies the same rule in SQL (invariant 1). The
 * browser receives the answer as `allowedActions` and renders from that.
 *
 * Ported from `can_see_draft`, `draft_is_mine` and `allowed_draft_actions` in
 * the pinned source (`draft_store.py`), with four deliberate differences.
 * Each is a place where the source's behaviour depended on something the
 * platform does not have, or contradicted an invariant:
 *
 *  1. **An assignee sees what is assigned to them.** The source matches only
 *     the owner, so work assigned across a branch boundary disappeared from
 *     the assignee's own list. (Assigning out of scope is refused in Phase 4.5.)
 *  2. **An unattributed RFQ is visible only at all scope.** The source also
 *     shows it to every `drafts.assign` holder in every branch, as a triage
 *     bucket. That is owner question Q10; until answered, the narrower rule.
 *  3. **A branch-scoped actor with no branch is own-scoped**, as in the source
 *     (`access.py` falls back to own). The source's reuse of a last-seen
 *     branch when the directory returns none is not carried.
 *  4. **`RFQ_OPEN_DRAFT_ACCESS` is not carried.** If production runs with it
 *     on (Q8), today's behaviour is a shared pool, and reproducing it is a
 *     grant of `RFQ.SCOPE.ALL` plus the manager permission — a role decision,
 *     not a flag.
 *
 * Permanent deletion is absent from {@see allowedActions()} on purpose: it has
 * no retention policy and no route (plan 1.3).
 */
final class RfqAccessPolicy
{
    public static function effectiveScope(RfqActor $actor): RfqScope
    {
        if ($actor->can(Module::PERMISSION_SCOPE_ALL)) {
            return RfqScope::all();
        }

        if ($actor->can(Module::PERMISSION_SCOPE_BRANCH) && self::hasBranch($actor)) {
            return RfqScope::branch();
        }

        return RfqScope::own();
    }

    /**
     * The scope the actor's grants ask for, before branch membership is
     * applied. Reported beside the effective scope so that "you are
     * branch-scoped but have no branch" can be said out loud rather than
     * looking like an empty queue.
     */
    public static function grantedScope(RfqActor $actor): RfqScope
    {
        if ($actor->can(Module::PERMISSION_SCOPE_ALL)) {
            return RfqScope::all();
        }

        return $actor->can(Module::PERMISSION_SCOPE_BRANCH) ? RfqScope::branch() : RfqScope::own();
    }

    public static function canSee(RfqActor $actor, RfqOwnership $rfq): bool
    {
        if (!$actor->can(Module::PERMISSION_DRAFT_READ)) {
            return false;
        }

        $scope = self::effectiveScope($actor);
        if ($scope->is(RfqScope::ALL)) {
            return true;
        }

        if ($rfq->ownerUserId === $actor->userId || $rfq->assigneeUserId === $actor->userId) {
            return true;
        }

        if ($scope->is(RfqScope::BRANCH) && !$rfq->isUnattributed()) {
            return self::sameBranch($rfq->branchCode, $actor->branchCode);
        }

        return false;
    }

    /** Assigned to this actor. The source's `draft_is_mine`, less the open-access flag. */
    public static function isMine(RfqActor $actor, RfqOwnership $rfq): bool
    {
        return $rfq->assigneeUserId === $actor->userId;
    }

    public static function isManager(RfqActor $actor): bool
    {
        return $actor->can(Module::PERMISSION_DRAFT_ASSIGN);
    }

    /**
     * Which workflow actor this person is on this RFQ, or null if they may not
     * change its workflow at all.
     */
    public static function workflowActor(RfqActor $actor, RfqOwnership $rfq): ?WorkflowActor
    {
        if (!self::canSee($actor, $rfq) || !$actor->can(Module::PERMISSION_DRAFT_WORKFLOW)) {
            return null;
        }

        if (self::isManager($actor)) {
            return WorkflowActor::manager();
        }

        return self::isMine($actor, $rfq) ? WorkflowActor::assignee() : null;
    }

    /**
     * Invisible means no actions — not even `view` — so a caller cannot learn
     * an RFQ exists by asking what they may do to it.
     *
     * @return list<string> {@see RfqAction} values, in declaration order
     */
    public static function allowedActions(RfqActor $actor, RfqOwnership $rfq): array
    {
        if (!self::canSee($actor, $rfq)) {
            return [];
        }

        $mineOrManager = self::isMine($actor, $rfq) || self::isManager($actor);
        $actions = [RfqAction::VIEW];

        if ($actor->can(Module::PERMISSION_DRAFT_COMMENT)) {
            $actions[] = RfqAction::COMMENT;
        }

        if ($rfq->archived) {
            if ($actor->can(Module::PERMISSION_DRAFT_RESTORE)) {
                $actions[] = RfqAction::RESTORE;
            }

            return $actions;
        }

        if ($rfq->isUnassigned() && $actor->can(Module::PERMISSION_DRAFT_CLAIM)) {
            $actions[] = RfqAction::CLAIM;
        }

        if (self::isManager($actor)) {
            $actions[] = RfqAction::ASSIGN;
        }

        $gated = [
            RfqAction::WORKFLOW => Module::PERMISSION_DRAFT_WORKFLOW,
            RfqAction::EDIT => Module::PERMISSION_DRAFT_EDIT,
            RfqAction::SYNC => Module::PERMISSION_QUOTE_SYNC,
            RfqAction::RECONCILE => Module::PERMISSION_QUOTE_RECONCILE,
            RfqAction::ARCHIVE => Module::PERMISSION_DRAFT_ARCHIVE,
        ];
        foreach ($gated as $action => $permission) {
            if ($mineOrManager && $actor->can($permission)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    private static function hasBranch(RfqActor $actor): bool
    {
        return $actor->branchCode !== null && trim($actor->branchCode) !== '';
    }

    private static function sameBranch(?string $a, ?string $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        $a = strtoupper(trim($a));

        return $a !== '' && $a === strtoupper(trim($b));
    }
}
