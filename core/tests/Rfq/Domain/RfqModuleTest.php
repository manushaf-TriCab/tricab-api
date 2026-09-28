<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Domain\Service\PermissionAssurancePolicy;
use TriCab\Core\Identity\Domain\ValueObject\AuthenticationAssurance;
use TriCab\Core\Identity\Domain\ValueObject\PermissionCode;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Domain\ValueObject\LineDisposition;
use TriCab\Core\Rfq\Domain\ValueObject\Priority;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;
use TriCab\Core\Rfq\Domain\ValueObject\WorkflowStatus;
use TriCab\Core\Rfq\Module;

/**
 * Plan 1.3 — the permission catalogue and the draft roles, and the reading
 * rules shared by every RFQ vocabulary.
 */
final class RfqModuleTest extends TestCase
{
    public function test_every_code_is_a_valid_platform_permission_code(): void
    {
        foreach ([...Module::PERSON_PERMISSIONS, ...Module::INTEGRATION_PERMISSIONS] as $code) {
            self::assertSame($code, PermissionCode::fromString($code)->toString());
            self::assertStringStartsWith('RFQ.', $code);
        }
    }

    public function test_roles_carry_only_person_permissions(): void
    {
        foreach (Module::ROLE_PERMISSIONS as $role => $permissions) {
            self::assertSame(array_values(array_unique($permissions)), $permissions, $role . ' lists a permission twice.');
            foreach ($permissions as $permission) {
                self::assertContains($permission, Module::PERSON_PERMISSIONS, $role);
                self::assertNotContains($permission, Module::INTEGRATION_PERMISSIONS, $role);
            }
        }
    }

    /** Each role is the one below it plus more, as in the source role ladder. */
    public function test_the_roles_are_a_ladder(): void
    {
        $ladder = [Module::ROLE_SALES, Module::ROLE_ADMIN, Module::ROLE_DEVELOPER];
        for ($i = 1; $i < count($ladder); $i++) {
            $lower = Module::ROLE_PERMISSIONS[$ladder[$i - 1]];
            $upper = Module::ROLE_PERMISSIONS[$ladder[$i]];
            self::assertSame([], array_values(array_diff($lower, $upper)), $ladder[$i] . ' lacks something ' . $ladder[$i - 1] . ' has.');
            self::assertGreaterThan(count($lower), count($upper));
        }
    }

    /** Parity: `pricing.view` sits with admin and developer in the source, not with sales. */
    public function test_pricing_view_sits_where_the_source_put_it(): void
    {
        self::assertNotContains(Module::PERMISSION_PRICING_VIEW, Module::ROLE_PERMISSIONS[Module::ROLE_SALES]);
        self::assertContains(Module::PERMISSION_PRICING_VIEW, Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]);
    }

    /** Owner decision, 28 Sep 2026: no sales manager. Admin holds what a manager did. */
    public function test_there_is_no_sales_manager_and_admin_is_the_manager(): void
    {
        self::assertSame([Module::ROLE_SALES, Module::ROLE_ADMIN, Module::ROLE_DEVELOPER], array_keys(Module::ROLE_PERMISSIONS));
        self::assertNotContains(Module::PERMISSION_DRAFT_ASSIGN, Module::ROLE_PERMISSIONS[Module::ROLE_SALES]);
        foreach ([Module::PERMISSION_DRAFT_ASSIGN, Module::PERMISSION_DRAFT_RESTORE, Module::PERMISSION_ANALYTICS_READ, Module::PERMISSION_AUDIT_READ] as $permission) {
            self::assertContains($permission, Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]);
        }
    }

    /** The platform administrator can grant RFQ access without reading a quote. */
    public function test_the_platform_administrator_holds_no_rfq_data_permission(): void
    {
        self::assertSame([Module::PERMISSION_ADMIN], Module::ADMINISTRATOR_PERMISSIONS);
    }

    public function test_live_erp_and_destructive_permissions_need_substantial_assurance(): void
    {
        foreach ([
            Module::PERMISSION_QUOTE_RECONCILE,
            Module::PERMISSION_DRAFT_PURGE,
            Module::PERMISSION_REVIEWLOG_READ,
            Module::PERMISSION_ADMIN,
            Module::PERMISSION_MAIL_INGEST,
        ] as $code) {
            self::assertSame(
                AuthenticationAssurance::SUBSTANTIAL,
                PermissionAssurancePolicy::requiredFor(PermissionCode::fromString($code))->toString(),
                $code,
            );
        }

        self::assertSame(
            AuthenticationAssurance::LOW,
            PermissionAssurancePolicy::requiredFor(PermissionCode::fromString(Module::PERMISSION_DRAFT_READ))->toString(),
        );
    }

    // --------------------------------------------------------- vocabularies

    public function test_a_client_value_outside_the_vocabulary_is_refused(): void
    {
        $this->expectException(InvalidRfqValue::class);
        $this->expectExceptionMessage('Must be one of: new, in_progress, blocked, completed');

        WorkflowStatus::fromString('ready_to_sync');
    }

    public function test_client_values_are_trimmed_and_lower_cased(): void
    {
        self::assertSame('in_progress', WorkflowStatus::fromString(' IN_PROGRESS ')->toString());
    }

    /** Each vocabulary falls back to the reading that hides nothing and claims nothing. */
    public function test_unreadable_stored_values_fall_back_safely(): void
    {
        self::assertSame('new', WorkflowStatus::fromStorage('ready_to_sync')->toString());
        self::assertSame('normal', Priority::fromStorage('whenever')->toString());
        self::assertSame('failed', ReaderJobStatus::fromStorage(42)->toString());
        self::assertSame('included', LineDisposition::fromStorage(null)->toString());
    }

    public function test_priority_rank_is_the_source_queue_order(): void
    {
        $ranked = array_map(static fn (Priority $p): string => $p->toString(), Priority::cases());
        usort($ranked, static fn (string $a, string $b): int => Priority::fromString($a)->rank() <=> Priority::fromString($b)->rank());

        self::assertSame(['urgent', 'high', 'normal', 'low'], $ranked);
    }

    public function test_equality_is_by_type_and_value(): void
    {
        self::assertTrue(WorkflowStatus::new()->equals(WorkflowStatus::fromString('new')));
        self::assertFalse(WorkflowStatus::new()->equals(WorkflowStatus::blocked()));
    }
}
