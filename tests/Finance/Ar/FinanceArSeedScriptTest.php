<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Module;

/**
 * The two Tri-AR seed scripts say what `Module` says, and nothing else
 * (Phase 5, task T5.1). Same approach as `FinanceTaskSeedScriptTest`: two lists
 * of the same permissions drift the first time somebody edits one of them, so
 * the drift is a failing test instead of a 403 in production.
 */
final class FinanceArSeedScriptTest extends TestCase
{
    private const CODE = 'FINANCE\\.[A-Z0-9_.]+';

    private static function script(string $name): string
    {
        $path = dirname(__DIR__, 3) . '/database/scripts/development/' . $name;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_the_permission_seed_creates_exactly_the_four_ar_permissions(): void
    {
        preg_match_all("/^\\s*\\(N'(" . self::CODE . ")',/m", self::script('seed-finance-ar-permissions.sql'), $matches);
        $seeded = $matches[1];
        sort($seeded);

        $expected = Module::AR_PERMISSIONS;
        sort($expected);

        self::assertCount(4, $expected);
        self::assertSame($expected, $seeded);
    }

    public function test_ar_permissions_are_all_under_the_ar_app_and_do_not_repeat_phase_one(): void
    {
        foreach (Module::AR_PERMISSIONS as $code) {
            self::assertStringStartsWith('FINANCE.AR.', $code);
            self::assertNotContains($code, Module::PERSON_PERMISSIONS, $code);
            self::assertNotContains($code, Module::TASK_PERMISSIONS, $code);
        }
        self::assertSame(count(Module::AR_PERMISSIONS), count(array_unique(Module::AR_PERMISSIONS)));
        // The app's administrator is not the module's.
        self::assertNotSame(Module::PERMISSION_ADMIN, Module::PERMISSION_AR_ADMIN);
    }

    public function test_the_role_seed_grants_exactly_the_module_ar_role_permissions(): void
    {
        preg_match_all(
            "/\\(N'(FINANCE_[A-Z_]+)', N'(" . self::CODE . ")'\\)/",
            self::script('seed-finance-ar-roles.sql'),
            $matches,
            PREG_SET_ORDER,
        );

        $seeded = [];
        foreach ($matches as $match) {
            $seeded[$match[1]][] = $match[2];
        }

        self::assertSame(Module::AR_ROLE_PERMISSIONS, $seeded);
    }

    public function test_the_role_seed_creates_exactly_the_three_roles(): void
    {
        preg_match_all("/^\\s*\\(N'(FINANCE_[A-Z_]+)',\\s*N'[^']+',\\s*$/m", self::script('seed-finance-ar-roles.sql'), $matches);

        self::assertSame(
            [Module::ROLE_AR_REVIEWER, Module::ROLE_AR_OPERATOR, Module::ROLE_AR_ADMIN],
            $matches[1],
        );
    }

    public function test_each_role_is_a_superset_of_the_one_before_in_order(): void
    {
        $reviewer = Module::AR_ROLE_PERMISSIONS[Module::ROLE_AR_REVIEWER];
        $operator = Module::AR_ROLE_PERMISSIONS[Module::ROLE_AR_OPERATOR];
        $admin = Module::AR_ROLE_PERMISSIONS[Module::ROLE_AR_ADMIN];

        // Operator adds exactly RECORDS.WRITE; the administrator adds exactly ADMIN.
        self::assertSame([], array_values(array_diff($reviewer, $operator)));
        self::assertSame([], array_values(array_diff($operator, $admin)));
        self::assertSame([Module::PERMISSION_AR_RECORDS_WRITE], array_values(array_diff($operator, $reviewer)));
        self::assertSame([Module::PERMISSION_AR_ADMIN], array_values(array_diff($admin, $operator)));
    }

    public function test_the_administrator_holds_every_ar_permission(): void
    {
        // Q4: the portal let `ar.admin` stand in for any permission. Here the
        // administrator holds them all, so no route needs a special rule.
        self::assertSame(
            [],
            array_values(array_diff(Module::AR_PERMISSIONS, Module::AR_ROLE_PERMISSIONS[Module::ROLE_AR_ADMIN])),
        );
    }

    public function test_a_reviewer_reads_and_changes_nothing(): void
    {
        $reviewer = Module::AR_ROLE_PERMISSIONS[Module::ROLE_AR_REVIEWER];

        self::assertContains(Module::PERMISSION_AR_RECORDS_READ, $reviewer);
        self::assertNotContains(Module::PERMISSION_AR_RECORDS_WRITE, $reviewer);
        self::assertNotContains(Module::PERMISSION_AR_ADMIN, $reviewer);
    }

    public function test_every_role_can_read_attachments_at_the_start(): void
    {
        // Q5: its own permission, held by all three roles, so day one is as the portal was.
        foreach (Module::AR_ROLE_PERMISSIONS as $role => $permissions) {
            self::assertContains(Module::PERMISSION_AR_ATTACHMENTS_READ, $permissions, $role);
        }
    }

    public function test_every_ar_role_can_open_the_launcher_and_the_app(): void
    {
        foreach (Module::AR_ROLE_PERMISSIONS as $role => $permissions) {
            self::assertContains(Module::PERMISSION_ACCESS, $permissions, $role);
            self::assertContains(Module::PERMISSION_AR_ACCESS, $permissions, $role);
            self::assertSame(count($permissions), count(array_unique($permissions)), $role);
            // Nothing outside Tri-AR's own permissions, the launcher and the app's access permission.
            $allowed = [...Module::AR_PERMISSIONS, Module::PERMISSION_ACCESS, Module::PERMISSION_AR_ACCESS];
            self::assertSame([], array_values(array_diff($permissions, $allowed)), $role);
        }
    }

    public function test_the_portal_string_table_covers_every_ar_permission_exactly_once(): void
    {
        $table = Module::AR_PORTAL_PERMISSIONS;

        self::assertEqualsCanonicalizing(Module::AR_PERMISSIONS, array_keys($table));
        self::assertSame(count($table), count(array_unique($table)), 'No two codes share a portal string.');
        foreach ($table as $code => $portal) {
            // The map's own rule (D1): the portal string is the code, lower-cased, without `FINANCE.`.
            self::assertSame(strtolower(substr($code, strlen('FINANCE.'))), $portal);
            self::assertMatchesRegularExpression('/^ar\\.[a-z_]+(\\.[a-z_]+)?$/', $portal);
        }
        // The three the portal has, as the pages read them.
        self::assertSame('ar.records.read', $table[Module::PERMISSION_AR_RECORDS_READ]);
        self::assertSame('ar.records.write', $table[Module::PERMISSION_AR_RECORDS_WRITE]);
        self::assertSame('ar.admin', $table[Module::PERMISSION_AR_ADMIN]);
    }

    public function test_the_platform_roles_are_untouched_and_the_catalogues_do_not_include_ar_permissions(): void
    {
        foreach (['seed-finance-ar-permissions.sql', 'seed-finance-ar-roles.sql'] as $name) {
            self::assertDoesNotMatchRegularExpression(
                "/\\(N'(EMPLOYEE|GUEST|ADMINISTRATOR|FINANCE_ADMIN)',\\s*N'FINANCE\\./",
                self::script($name),
                $name,
            );
        }
        // D3: Tri-AR permissions are in neither platform list.
        foreach (Module::AR_PERMISSIONS as $code) {
            self::assertNotContains($code, Module::ADMINISTRATOR_PERMISSIONS);
            self::assertNotContains($code, Module::EMPLOYEE_PERMISSIONS);
        }
    }

    public function test_both_scripts_are_guarded_and_say_they_are_not_executed(): void
    {
        foreach (['seed-finance-ar-permissions.sql', 'seed-finance-ar-roles.sql'] as $name) {
            $sql = self::script($name);

            self::assertStringContainsString('WRITTEN, NOT EXECUTED against any shared database', $sql, $name);
            self::assertStringContainsString('THROW 54101', $sql, $name);
            self::assertStringContainsString('THROW 54102', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/\\bDELETE\\s+(FROM\\s+)?[@\\[#]/i', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/\\bDROP\\s+(TABLE|INDEX|SCHEMA|CONSTRAINT)\\b/i', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/INSERT\\s+INTO\\s+\\[identity\\]\\.\\[(Users|UserRoles)\\]/i', $sql, $name);
        }
    }

    public function test_the_roles_are_assigned_to_nobody(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\\[identity\\]\\.\\[UserRoles\\]/i',
            self::script('seed-finance-ar-roles.sql'),
        );
    }

    public function test_both_scripts_are_in_the_local_rebuild_in_order(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');
        $order = ['seed-finance-permissions.sql', 'seed-finance-roles.sql', 'seed-finance-ar-permissions.sql', 'seed-finance-ar-roles.sql'];

        $last = -1;
        foreach ($order as $name) {
            $at = strpos($rebuild, "'" . $name . "'");
            self::assertIsInt($at, $name);
            self::assertGreaterThan($last, $at, $name . ' runs after the one before it.');
            $last = $at;
        }
    }
}
