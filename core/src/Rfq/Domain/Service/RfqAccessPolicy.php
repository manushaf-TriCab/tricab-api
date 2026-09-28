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
 * **Working is narrower.** Editing, workflow, sync, reconcile and archive
 * belong to the assignee, or to a manager (`RFQ.DRAFT.ASSIGN`), and each still
 * needs its own permission. Ported from `draft_is_mine` and
 * `allowed_draft_actions` in the pinned source (`draft_store.py`), *without*
 * the open-access flag: in production that flag also lets anyone with the
 * permission work any draft. Whether to keep that is open (Q8).
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
}
