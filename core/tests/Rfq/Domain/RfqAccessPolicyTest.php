<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Service\RfqAccessPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\RfqOwnership;
use TriCab\Core\Rfq\Module;

/**
 * Plan 1.3, invariant 1 — scope and allowed actions, re-expressed from the
 * source's `can_see_draft` / `allowed_draft_actions` against platform grants.
 * Named after the source tests where one exists.
 */
final class RfqAccessPolicyTest extends TestCase
{
    private const ME = 10;
    private const COLLEAGUE = 20;

    /** @param list<string> $permissions */
    private static function actor(array $permissions, ?string $branch = 'VIC', int $id = self::ME): RfqActor
    {
        return new RfqActor($id, 'user-' . $id, $permissions, $branch);
    }

    private static function rfq(?int $owner, ?int $assignee, ?string $branch, bool $archived = false): RfqOwnership
    {
        return new RfqOwnership($owner, $assignee, $branch, $archived);
    }

    /** @return list<string> */
    private static function sales(): array
    {
        return Module::ROLE_PERMISSIONS[Module::ROLE_SALES];
    }

    /** @return list<string> */
    private static function manager(): array
    {
        return Module::ROLE_PERMISSIONS[Module::ROLE_SALES_MANAGER];
    }

    public function test_no_read_permission_sees_nothing_not_even_their_own(): void
    {
        $actor = self::actor([Module::PERMISSION_ACCESS, Module::PERMISSION_SCOPE_ALL]);

        self::assertFalse(RfqAccessPolicy::canSee($actor, self::rfq(self::ME, self::ME, 'VIC')));
        self::assertSame([], RfqAccessPolicy::allowedActions($actor, self::rfq(self::ME, self::ME, 'VIC')));
    }

    /** Source `sales_sees_own_branch_but_not_another`. */
    public function test_a_branch_scoped_person_sees_their_branch_and_not_another(): void
    {
        $actor = self::actor(self::sales(), 'VIC');

        self::assertTrue(RfqAccessPolicy::canSee($actor, self::rfq(self::COLLEAGUE, null, 'VIC')));
        self::assertTrue(RfqAccessPolicy::canSee($actor, self::rfq(self::COLLEAGUE, null, ' vic ')));
        self::assertFalse(RfqAccessPolicy::canSee($actor, self::rfq(self::COLLEAGUE, null, 'NSW')));
    }

    /** Source `branchless_user_falls_back_to_own_drafts_only` — today's state for everybody (Q7). */
    public function test_a_branch_scoped_person_with_no_branch_sees_only_their_own(): void
    {
        $actor = self::actor(self::sales(), null);

        self::assertSame('branch', RfqAccessPolicy::grantedScope($actor)->toString());
        self::assertSame('own', RfqAccessPolicy::effectiveScope($actor)->toString());
        self::assertTrue(RfqAccessPolicy::canSee($actor, self::rfq(self::ME, null, 'VIC')));
        self::assertFalse(RfqAccessPolicy::canSee($actor, self::rfq(self::COLLEAGUE, null, 'VIC')));
    }

    /** Source `developer_sees_everything`, as a grant rather than a role. */
    public function test_all_scope_sees_every_branch_and_unattributed_work(): void
    {
        $actor = self::actor([Module::PERMISSION_DRAFT_READ, Module::PERMISSION_SCOPE_ALL], null);

        self::assertTrue(RfqAccessPolicy::canSee($actor, self::rfq(self::COLLEAGUE, null, 'NSW')));
        self::assertTrue(RfqAccessPolicy::canSee($actor, self::rfq(null, null, null)));
    }

    /** Difference 1: work assigned to me is on my list, even from another branch. */
    public function test_an_assignee_sees_what_is_assigned_to_them(): void
    {
        $actor = self::actor(self::sales(), 'VIC');

        self::assertTrue(RfqAccessPolicy::canSee($actor, self::rfq(self::COLLEAGUE, self::ME, 'NSW')));
    }

    /** Difference 2, pending owner question Q10: no cross-branch triage bucket for managers. */
    public function test_an_unattributed_rfq_is_hidden_below_all_scope(): void
    {
        $manager = self::actor(self::manager(), 'VIC');

        self::assertFalse(RfqAccessPolicy::canSee($manager, self::rfq(self::COLLEAGUE, null, null)));
        self::assertFalse(RfqAccessPolicy::canSee($manager, self::rfq(self::COLLEAGUE, null, '  ')));
    }

    public function test_an_unassigned_rfq_offers_claim_but_not_edit_to_a_salesperson(): void
    {
        $actions = RfqAccessPolicy::allowedActions(self::actor(self::sales()), self::rfq(self::COLLEAGUE, null, 'VIC'));

        self::assertSame(['view', 'comment', 'claim'], $actions);
    }

    /** Source: an assignee may edit, sync, archive and change workflow on their own work. */
    public function test_my_assigned_work_offers_the_working_actions(): void
    {
        $actions = RfqAccessPolicy::allowedActions(self::actor(self::sales()), self::rfq(self::COLLEAGUE, self::ME, 'VIC'));

        self::assertSame(['view', 'comment', 'workflow', 'edit', 'sync', 'reconcile', 'archive'], $actions);
    }

    /** Source: a salesperson in scope does not work somebody else's assigned RFQ. */
    public function test_a_colleague_s_assigned_work_is_view_and_comment_only(): void
    {
        $actions = RfqAccessPolicy::allowedActions(self::actor(self::sales()), self::rfq(self::COLLEAGUE, self::COLLEAGUE, 'VIC'));

        self::assertSame(['view', 'comment'], $actions);
    }

    public function test_a_manager_works_anything_in_scope(): void
    {
        $actions = RfqAccessPolicy::allowedActions(self::actor(self::manager()), self::rfq(self::COLLEAGUE, self::COLLEAGUE, 'VIC'));

        self::assertSame(['view', 'comment', 'assign', 'workflow', 'edit', 'sync', 'reconcile', 'archive'], $actions);
    }

    public function test_an_archived_rfq_offers_restore_and_nothing_that_changes_it(): void
    {
        $archived = self::rfq(self::ME, self::ME, 'VIC', archived: true);

        self::assertSame(['view', 'comment', 'restore'], RfqAccessPolicy::allowedActions(self::actor(self::manager()), $archived));
        self::assertSame(['view', 'comment'], RfqAccessPolicy::allowedActions(self::actor(self::sales()), $archived));
    }

    /** No retention policy, no route: purge is never offered (plan 1.3). */
    public function test_purge_is_never_offered(): void
    {
        $developer = self::actor(Module::ROLE_PERMISSIONS[Module::ROLE_DEVELOPER]);

        self::assertNotContains('purge', RfqAccessPolicy::allowedActions($developer, self::rfq(self::ME, self::ME, 'VIC')));
    }

    public function test_an_invisible_rfq_offers_no_actions_at_all(): void
    {
        self::assertSame([], RfqAccessPolicy::allowedActions(self::actor(self::manager(), 'VIC'), self::rfq(self::COLLEAGUE, null, 'NSW')));
    }

    public function test_workflow_actor_is_manager_assignee_or_nobody(): void
    {
        $mine = self::rfq(self::COLLEAGUE, self::ME, 'VIC');
        $theirs = self::rfq(self::COLLEAGUE, self::COLLEAGUE, 'VIC');

        self::assertSame('assignee', RfqAccessPolicy::workflowActor(self::actor(self::sales()), $mine)?->toString());
        self::assertNull(RfqAccessPolicy::workflowActor(self::actor(self::sales()), $theirs));
        self::assertSame('manager', RfqAccessPolicy::workflowActor(self::actor(self::manager()), $theirs)?->toString());
    }

    public function test_permission_codes_are_matched_case_insensitively(): void
    {
        $actor = self::actor(['rfq.draft.read', 'Rfq.Scope.All']);

        self::assertTrue(RfqAccessPolicy::canSee($actor, self::rfq(null, null, null)));
        self::assertSame(['RFQ.DRAFT.READ', 'RFQ.SCOPE.ALL'], $actor->rfqPermissions());
    }
}
