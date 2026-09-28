<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Service\RfqAccessPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\RfqActor;
use TriCab\Core\Rfq\Domain\ValueObject\RfqOwnership;
use TriCab\Core\Rfq\Module;

/**
 * Plan 1.3: who sees RFQs and what they may do to each, against platform
 * grants. Seeing is not scoped (owner decision, 28 Sep 2026); working is the
 * assignee's or a manager's, as in the source's `allowed_draft_actions`.
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
        $actor = self::actor([Module::PERMISSION_ACCESS, Module::PERMISSION_DRAFT_COMMENT]);

        self::assertFalse(RfqAccessPolicy::canSee($actor, self::rfq(self::ME, self::ME, 'VIC')));
        self::assertSame([], RfqAccessPolicy::allowedActions($actor, self::rfq(self::ME, self::ME, 'VIC')));
    }

    /** As in 4js: anyone who reads RFQs reads every one, whatever its branch or owner. */
    public function test_a_reader_sees_every_rfq_whatever_its_branch_owner_or_assignee(): void
    {
        foreach ([self::actor(Module::EMPLOYEE_PERMISSIONS, null), self::actor(self::sales(), 'VIC'), self::actor(self::manager(), null)] as $actor) {
            foreach ([
                self::rfq(self::COLLEAGUE, null, 'NSW'),
                self::rfq(self::COLLEAGUE, self::COLLEAGUE, 'VIC'),
                self::rfq(null, null, null),
                self::rfq(null, null, '  '),
                self::rfq(self::ME, self::ME, 'NZNTH', archived: true),
            ] as $rfq) {
                self::assertTrue(RfqAccessPolicy::canSee($actor, $rfq));
            }
        }
    }

    /** Every employee reads; reading alone offers nothing that changes an RFQ. */
    public function test_an_employee_may_view_and_do_nothing_else(): void
    {
        $employee = self::actor(Module::EMPLOYEE_PERMISSIONS, null);

        self::assertSame(['view'], RfqAccessPolicy::allowedActions($employee, self::rfq(self::ME, self::ME, 'VIC')));
        self::assertSame(['view'], RfqAccessPolicy::allowedActions($employee, self::rfq(null, null, null)));
        self::assertNull(RfqAccessPolicy::workflowActor($employee, self::rfq(self::ME, self::ME, 'VIC')));
    }

    public function test_an_unassigned_rfq_offers_claim_and_the_working_actions_to_a_salesperson(): void
    {
        $actions = RfqAccessPolicy::allowedActions(self::actor(self::sales()), self::rfq(self::COLLEAGUE, null, 'VIC'));

        self::assertSame(['view', 'comment', 'claim', 'workflow', 'edit', 'sync', 'reconcile', 'archive'], $actions);
    }

    /** Source: an assignee may edit, sync, archive and change workflow on their own work. */
    public function test_my_assigned_work_offers_the_working_actions(): void
    {
        $actions = RfqAccessPolicy::allowedActions(self::actor(self::sales()), self::rfq(self::COLLEAGUE, self::ME, 'VIC'));

        self::assertSame(['view', 'comment', 'workflow', 'edit', 'sync', 'reconcile', 'archive'], $actions);
    }

    /** Owner decision, 28 Sep 2026 (production's open access): anyone works any RFQ their role allows. */
    public function test_a_salesperson_works_a_colleague_s_rfq_in_any_branch(): void
    {
        foreach (['VIC', 'NSW', null] as $branch) {
            $actions = RfqAccessPolicy::allowedActions(self::actor(self::sales()), self::rfq(self::COLLEAGUE, self::COLLEAGUE, $branch));
            self::assertSame(['view', 'comment', 'workflow', 'edit', 'sync', 'reconcile', 'archive'], $actions);
        }
    }

    public function test_a_manager_works_any_rfq(): void
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

    public function test_without_read_no_rfq_offers_any_action(): void
    {
        $noRead = array_values(array_diff(self::manager(), [Module::PERMISSION_DRAFT_READ]));

        self::assertSame([], RfqAccessPolicy::allowedActions(self::actor($noRead), self::rfq(self::ME, self::ME, 'VIC')));
    }

    public function test_workflow_actor_is_manager_or_assignee_for_anyone_who_may_change_workflow(): void
    {
        $theirs = self::rfq(self::COLLEAGUE, self::COLLEAGUE, 'VIC');

        self::assertSame('assignee', RfqAccessPolicy::workflowActor(self::actor(self::sales()), $theirs)?->toString());
        self::assertSame('manager', RfqAccessPolicy::workflowActor(self::actor(self::manager()), $theirs)?->toString());
        self::assertNull(RfqAccessPolicy::workflowActor(self::actor(Module::EMPLOYEE_PERMISSIONS), $theirs), 'no workflow permission');
    }

    public function test_permission_codes_are_matched_case_insensitively(): void
    {
        $actor = self::actor(['rfq.draft.read', 'Rfq.Draft.Comment']);

        self::assertTrue(RfqAccessPolicy::canSee($actor, self::rfq(null, null, null)));
        self::assertSame(['RFQ.DRAFT.COMMENT', 'RFQ.DRAFT.READ'], $actor->rfqPermissions());
    }
}
