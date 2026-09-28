<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Service;

use TriCab\Core\Rfq\Domain\ValueObject\RfqAction;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\RfqOwnership;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowActor;
use TriCab\Core\Rfq\Module;

/**
 * Whether an actor may see RFQs, and what they may do to each one.
 *
 * Pure. Every endpoint that returns or changes an RFQ asks this on the
 * server; the browser receives the answer as `allowedActions` and renders
 * from that.
 *
 * **Seeing is not scoped.** Anyone holding `RFQ.DRAFT.READ` sees every RFQ,
 * as everybody sees every quote in 4js, and every employee holds it (owner
 * decision, 28 Sep 2026). Branch is a queue filter, not a limit. Production
 * sees the same: the source runs with `RFQ_OPEN_DRAFT_ACCESS` on, which
 * replaces its own/branch/all `draft_scope` with "all".
 *
 * **Anyone may work any RFQ** their permissions allow (owner decision, 28 Sep
 * 2026): editing, workflow, sync, reconcile and archive are not reserved to
 * the assignee. That is production today (`RFQ_OPEN_DRAFT_ACCESS=true` makes
 * the source's `draft_is_mine` true for everybody). What stays with a manager
 * (`RFQ.DRAFT.ASSIGN`) is assigning, and the wider workflow moves.
 * Ported from `allowed_draft_actions` in the pinned source (`draft_store.py`).
 *
 * Permanent deletion is absent from {@see allowedActions()} on purpose: it has
 * no retention policy and no route (plan 1.3).
 */
final class RfqAccessPolicy
{
    /**
     * Every RFQ, for anyone who reads RFQs. `$rfq` is kept so that callers ask
     * about a particular RFQ, which is where a rule would go if one returned.
     */
    public static function canSee(RfqActor $actor, RfqOwnership $rfq): bool
    {
        return $actor->can(Module::PERMISSION_DRAFT_READ);
    }

    /** Assigned to this actor: the `mine` filter. It no longer decides what they may do. */
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
     * change its workflow at all. Anyone with the permission works it as the
     * assignee would; a manager has the manager's moves.
     */
    public static function workflowActor(RfqActor $actor, RfqOwnership $rfq): ?WorkflowActor
    {
        if (!self::canSee($actor, $rfq) || !$actor->can(Module::PERMISSION_DRAFT_WORKFLOW)) {
            return null;
        }

        if (self::isManager($actor)) {
            return WorkflowActor::manager();
        }

        return WorkflowActor::assignee();
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
            if ($actor->can($permission)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }
}
