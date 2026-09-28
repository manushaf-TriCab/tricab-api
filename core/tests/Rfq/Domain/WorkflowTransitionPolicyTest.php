<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\Service\WorkflowTransitionPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowActor;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;

/**
 * Plan 1.2 — the workflow machine, ported from `action_draft_workflow` with
 * the one tightening named in the policy: nobody marks work completed by hand.
 */
final class WorkflowTransitionPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function matrix(): iterable
    {
        // Source: "Sales may only start or block assigned work."
        yield 'assignee starts' => [WorkflowActor::ASSIGNEE, WorkflowStatus::NEW, WorkflowStatus::IN_PROGRESS, true];
        yield 'assignee blocks' => [WorkflowActor::ASSIGNEE, WorkflowStatus::IN_PROGRESS, WorkflowStatus::BLOCKED, true];
        yield 'assignee unblocks' => [WorkflowActor::ASSIGNEE, WorkflowStatus::BLOCKED, WorkflowStatus::IN_PROGRESS, true];
        yield 'assignee cannot return to new' => [WorkflowActor::ASSIGNEE, WorkflowStatus::IN_PROGRESS, WorkflowStatus::NEW, false];
        yield 'assignee cannot complete' => [WorkflowActor::ASSIGNEE, WorkflowStatus::IN_PROGRESS, WorkflowStatus::COMPLETED, false];

        yield 'manager returns to new' => [WorkflowActor::MANAGER, WorkflowStatus::IN_PROGRESS, WorkflowStatus::NEW, true];
        yield 'manager blocks' => [WorkflowActor::MANAGER, WorkflowStatus::NEW, WorkflowStatus::BLOCKED, true];
        // The source let a manager set `synced` by hand. Not here.
        yield 'manager cannot complete' => [WorkflowActor::MANAGER, WorkflowStatus::IN_PROGRESS, WorkflowStatus::COMPLETED, false];
        yield 'manager cannot reopen completed' => [WorkflowActor::MANAGER, WorkflowStatus::COMPLETED, WorkflowStatus::IN_PROGRESS, false];

        yield 'system completes on a succeeded sync' => [WorkflowActor::SYSTEM, WorkflowStatus::IN_PROGRESS, WorkflowStatus::COMPLETED, true];
        yield 'system reopens completed on a new revision' => [WorkflowActor::SYSTEM, WorkflowStatus::COMPLETED, WorkflowStatus::IN_PROGRESS, true];
        yield 'system starts on claim' => [WorkflowActor::SYSTEM, WorkflowStatus::NEW, WorkflowStatus::IN_PROGRESS, true];
        yield 'system never blocks' => [WorkflowActor::SYSTEM, WorkflowStatus::IN_PROGRESS, WorkflowStatus::BLOCKED, false];
        yield 'system never returns to new' => [WorkflowActor::SYSTEM, WorkflowStatus::IN_PROGRESS, WorkflowStatus::NEW, false];
    }

    #[DataProvider('matrix')]
    public function test_who_may_set_what(string $actor, string $from, string $to, bool $allowed): void
    {
        self::assertSame(
            $allowed,
            WorkflowTransitionPolicy::canSet(
                WorkflowActor::fromString($actor),
                WorkflowStatus::fromString($from),
                WorkflowStatus::fromString($to),
                archived: false,
                reason: 'Waiting on the customer',
            ),
        );
    }

    public function test_blocking_needs_a_reason(): void
    {
        $this->expectException(RfqTransitionNotPermitted::class);
        $this->expectExceptionMessage('requires a reason');

        WorkflowTransitionPolicy::assertCanSet(
            WorkflowActor::assignee(),
            WorkflowStatus::inProgress(),
            WorkflowStatus::blocked(),
            archived: false,
            reason: '   ',
        );
    }

    public function test_an_archived_rfq_does_not_change_workflow(): void
    {
        try {
            WorkflowTransitionPolicy::assertCanSet(
                WorkflowActor::manager(),
                WorkflowStatus::inProgress(),
                WorkflowStatus::blocked(),
                archived: true,
                reason: 'x',
            );
            self::fail('An archived RFQ changed workflow.');
        } catch (RfqTransitionNotPermitted $refused) {
            self::assertSame(RfqTransitionNotPermitted::REASON_STATE, $refused->reason);
        }
    }

    /** "You may never" is reported before "not from here", as in Onboarding. */
    public function test_an_actor_refusal_outranks_a_state_refusal(): void
    {
        try {
            WorkflowTransitionPolicy::assertCanSet(
                WorkflowActor::assignee(),
                WorkflowStatus::inProgress(),
                WorkflowStatus::completed(),
                archived: true,
            );
            self::fail('Completed by an assignee.');
        } catch (RfqTransitionNotPermitted $refused) {
            self::assertSame(RfqTransitionNotPermitted::REASON_ACTOR, $refused->reason);
        }
    }

    /** A retried request, or a manager changing only the priority, sends the status back unchanged. */
    public function test_setting_the_current_status_again_is_allowed(): void
    {
        self::assertTrue(WorkflowTransitionPolicy::canSet(
            WorkflowActor::assignee(),
            WorkflowStatus::new(),
            WorkflowStatus::new(),
            archived: false,
        ));
        self::assertTrue(WorkflowTransitionPolicy::canSet(
            WorkflowActor::assignee(),
            WorkflowStatus::blocked(),
            WorkflowStatus::blocked(),
            archived: false,
        ));
    }

    public function test_only_a_manager_changes_priority_or_due_date(): void
    {
        self::assertTrue(WorkflowTransitionPolicy::mayChangePriorityOrDueDate(WorkflowActor::manager()));
        self::assertFalse(WorkflowTransitionPolicy::mayChangePriorityOrDueDate(WorkflowActor::assignee()));
        self::assertFalse(WorkflowTransitionPolicy::mayChangePriorityOrDueDate(WorkflowActor::system()));
    }

    /**
     * Every source status has a home, and the three that were never workflow
     * — readiness and two sync outcomes — do not land on a status a person
     * could mistake for them.
     */
    public function test_every_source_status_maps_to_a_workflow_status(): void
    {
        $source = ['new', 'pending', 'in_progress', 'ready_to_sync', 'blocked', 'failed', 'push_failed', 'synced'];
        self::assertSame($source, array_keys(WorkflowStatus::SOURCE_STATUS_MAP));
        foreach (WorkflowStatus::SOURCE_STATUS_MAP as $target) {
            self::assertContains($target, WorkflowStatus::values());
        }
        self::assertSame(WorkflowStatus::IN_PROGRESS, WorkflowStatus::SOURCE_STATUS_MAP['ready_to_sync']);
        self::assertSame(WorkflowStatus::IN_PROGRESS, WorkflowStatus::SOURCE_STATUS_MAP['failed']);
        self::assertSame(WorkflowStatus::COMPLETED, WorkflowStatus::SOURCE_STATUS_MAP['synced']);
    }
}
