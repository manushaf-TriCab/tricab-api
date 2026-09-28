<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Service;

use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowActor;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;

/**
 * Who may move an RFQ into which workflow status. Pure: no database, no clock.
 *
 * Ported from `action_draft_workflow` (`rfq_cli.py`) and `update_workflow`
 * (`draft_store.py`), with one deliberate tightening:
 *
 * **Nobody sets `completed` by hand.** The source lets a manager set any of its
 * six statuses, `synced` included, so a quote can be marked sent without ever
 * reaching 4js. Here `completed` is the system's answer to a succeeded sync
 * attempt and nothing else (invariants 6 and 8). An RFQ finished some other
 * way is archived, with a reason, which is what archive is for.
 *
 * What is preserved from the source:
 *
 *  - an assignee without `RFQ.DRAFT.ASSIGN` may only start or block their own
 *    work ("Sales may only start or block assigned work");
 *  - a manager may also return work to `new`;
 *  - only a manager changes priority or due date;
 *  - `blocked` needs a reason;
 *  - an archived RFQ's workflow does not change until it is restored.
 *
 * Setting the status an RFQ already has is allowed, as in the source: a
 * retried request must not fail, and a manager changing only the priority
 * sends the status back unchanged.
 */
final class WorkflowTransitionPolicy
{
    private const MACHINE = 'workflow status';

    /** @var array<string, list<string>> */
    private const SETTABLE_BY = [
        WorkflowActor::ASSIGNEE => [WorkflowStatus::IN_PROGRESS, WorkflowStatus::BLOCKED],
        WorkflowActor::MANAGER => [WorkflowStatus::NEW, WorkflowStatus::IN_PROGRESS, WorkflowStatus::BLOCKED],
        WorkflowActor::SYSTEM => [WorkflowStatus::IN_PROGRESS, WorkflowStatus::COMPLETED],
    ];

    /** @return list<WorkflowStatus> */
    public static function settableBy(WorkflowActor $actor): array
    {
        return array_map(
            static fn (string $status): WorkflowStatus => WorkflowStatus::fromString($status),
            self::SETTABLE_BY[$actor->toString()],
        );
    }

    public static function canSet(
        WorkflowActor $actor,
        WorkflowStatus $from,
        WorkflowStatus $to,
        bool $archived,
        string $reason = '',
    ): bool {
        try {
            self::assertCanSet($actor, $from, $to, $archived, $reason);
        } catch (RfqTransitionNotPermitted) {
            return false;
        }

        return true;
    }

    /**
     * Order matches Onboarding's: "you may never set this" (403) before "not
     * from here" (409) before "you left something out" (400).
     *
     * @throws RfqTransitionNotPermitted
     */
    public static function assertCanSet(
        WorkflowActor $actor,
        WorkflowStatus $from,
        WorkflowStatus $to,
        bool $archived,
        string $reason = '',
    ): void {
        $unchanged = $from->equals($to);

        if (!$unchanged && !in_array($to->toString(), self::SETTABLE_BY[$actor->toString()], true)) {
            throw RfqTransitionNotPermitted::forActor(self::MACHINE, $actor->toString(), $to->toString());
        }

        if ($archived) {
            throw RfqTransitionNotPermitted::fromState(self::MACHINE, 'archived', $to->toString());
        }

        // Completed work reopens only because somebody saved a new revision,
        // which the system records as `in_progress`. A person does not reopen
        // it by setting a status: there is no revision to hand to the next
        // sync, and the quote that went out is still the latest one.
        if ($from->is(WorkflowStatus::COMPLETED) && !$unchanged && !$actor->is(WorkflowActor::SYSTEM)) {
            throw RfqTransitionNotPermitted::fromState(self::MACHINE, $from->toString(), $to->toString());
        }

        if ($to->is(WorkflowStatus::BLOCKED) && !$unchanged && trim($reason) === '') {
            throw RfqTransitionNotPermitted::missingInput(self::MACHINE, 'a blocked RFQ requires a reason.');
        }
    }

    /**
     * Priority and due date. A manager's call in the source ("Only managers can
     * change priority or deadline"); the system never changes either.
     */
    public static function mayChangePriorityOrDueDate(WorkflowActor $actor): bool
    {
        return $actor->is(WorkflowActor::MANAGER);
    }
}
