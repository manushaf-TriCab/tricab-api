<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Module;

/**
 * The two Tri-Task seed scripts say what `Module` says, and nothing else
 * (Phase 2, task T2.1). Same approach as `FinanceSeedScriptTest`: two lists of
 * the same permissions drift the first time somebody edits one of them, so the
 * drift is a failing test instead of a 403 in production.
 */
final class FinanceTaskSeedScriptTest extends TestCase
{
    private const CODE = 'FINANCE\\.[A-Z0-9_.]+';

    private static function script(string $name): string
    {
        $path = dirname(__DIR__, 3) . '/database/scripts/development/' . $name;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_the_permission_seed_creates_exactly_the_eleven_task_permissions(): void
    {
        preg_match_all("/^\\s*\\(N'(" . self::CODE . ")',/m", self::script('seed-finance-task-permissions.sql'), $matches);
        $seeded = $matches[1];
        sort($seeded);

        $expected = Module::TASK_PERMISSIONS;
        sort($expected);

        self::assertCount(11, $expected);
        self::assertSame($expected, $seeded);
        self::assertSame([], array_values(array_unique(array_diff($seeded, $expected))));
    }

    public function test_task_permissions_are_all_under_the_task_app_and_do_not_repeat_phase_one(): void
    {
        foreach (Module::TASK_PERMISSIONS as $code) {
            self::assertStringStartsWith('FINANCE.TASK.', $code);
            self::assertNotContains($code, Module::PERSON_PERMISSIONS, $code);
        }
        self::assertSame(count(Module::TASK_PERMISSIONS), count(array_unique(Module::TASK_PERMISSIONS)));
    }

    public function test_the_role_seed_grants_exactly_the_module_task_role_permissions(): void
    {
        preg_match_all(
            "/\\(N'(FINANCE_[A-Z_]+)', N'(" . self::CODE . ")'\\)/",
            self::script('seed-finance-task-roles.sql'),
            $matches,
            PREG_SET_ORDER,
        );

        $seeded = [];
        foreach ($matches as $match) {
            $seeded[$match[1]][] = $match[2];
        }

        self::assertSame(Module::TASK_ROLE_PERMISSIONS, $seeded);
    }

    public function test_the_role_seed_creates_exactly_the_three_roles(): void
    {
        preg_match_all("/^\\s*\\(N'(FINANCE_[A-Z_]+)',\\s*N'[^']+',\\s*$/m", self::script('seed-finance-task-roles.sql'), $matches);

        self::assertSame(
            [Module::ROLE_TASK_USER, Module::ROLE_TASK_MANAGER, Module::ROLE_TASK_ADMIN],
            $matches[1],
        );
    }

    public function test_each_role_is_a_superset_of_the_one_before_in_order(): void
    {
        $user = Module::TASK_ROLE_PERMISSIONS[Module::ROLE_TASK_USER];
        $manager = Module::TASK_ROLE_PERMISSIONS[Module::ROLE_TASK_MANAGER];
        $admin = Module::TASK_ROLE_PERMISSIONS[Module::ROLE_TASK_ADMIN];

        self::assertSame([], array_values(array_diff($user, $manager)));
        self::assertSame([], array_values(array_diff($manager, $admin)));
        self::assertGreaterThan(count($user), count($manager));
        self::assertGreaterThan(count($manager), count($admin));
        // Manager adds exactly READ_ALL, UPDATE, DELETE; admin adds exactly ANALYTICS.READ, SETTINGS.MANAGE.
        self::assertEqualsCanonicalizing(
            [Module::PERMISSION_TASK_TASKS_READ_ALL, Module::PERMISSION_TASK_TASKS_UPDATE, Module::PERMISSION_TASK_TASKS_DELETE],
            array_values(array_diff($manager, $user)),
        );
        self::assertEqualsCanonicalizing(
            [Module::PERMISSION_TASK_ANALYTICS_READ, Module::PERMISSION_TASK_SETTINGS_MANAGE],
            array_values(array_diff($admin, $manager)),
        );
        // The administrator holds every Tri-Task permission.
        self::assertSame([], array_values(array_diff(Module::TASK_PERMISSIONS, $admin)));
    }

    public function test_every_task_role_can_open_the_launcher_and_the_app(): void
    {
        foreach (Module::TASK_ROLE_PERMISSIONS as $role => $permissions) {
            self::assertContains(Module::PERMISSION_ACCESS, $permissions, $role);
            self::assertContains(Module::PERMISSION_TASK_ACCESS, $permissions, $role);
            self::assertSame(count($permissions), count(array_unique($permissions)), $role);
            // Nothing outside Tri-Task's own permissions, the launcher and the app's access permission.
            $allowed = [...Module::TASK_PERMISSIONS, Module::PERMISSION_ACCESS, Module::PERMISSION_TASK_ACCESS];
            self::assertSame([], array_values(array_diff($permissions, $allowed)), $role);
        }
    }

    public function test_a_role_that_may_update_any_task_may_also_update_its_own(): void
    {
        // Decision D14: routes open to "update or update_own" are gated on the weaker one.
        foreach (Module::TASK_ROLE_PERMISSIONS as $role => $permissions) {
            if (in_array(Module::PERMISSION_TASK_TASKS_UPDATE, $permissions, true)) {
                self::assertContains(Module::PERMISSION_TASK_TASKS_UPDATE_OWN, $permissions, $role);
            }
            if (in_array(Module::PERMISSION_TASK_TASKS_DELETE, $permissions, true)) {
                self::assertContains(Module::PERMISSION_TASK_TASKS_DELETE_OWN, $permissions, $role);
            }
        }
    }

    public function test_the_portal_string_table_covers_every_task_permission_exactly_once(): void
    {
        $table = Module::TASK_PORTAL_PERMISSIONS;

        self::assertEqualsCanonicalizing(Module::TASK_PERMISSIONS, array_keys($table));
        self::assertSame(count($table), count(array_unique($table)), 'No two codes share a portal string.');
        foreach ($table as $code => $portal) {
            // The map's own rule (D1): the portal string is the code, lower-cased, without `FINANCE.`.
            self::assertSame(strtolower(substr($code, strlen('FINANCE.'))), $portal);
            self::assertMatchesRegularExpression('/^task\\.[a-z_]+\\.[a-z_]+$/', $portal);
        }
    }

    public function test_the_platform_roles_are_untouched_and_the_catalogues_do_not_include_task_permissions(): void
    {
        foreach (['seed-finance-task-permissions.sql', 'seed-finance-task-roles.sql'] as $name) {
            self::assertDoesNotMatchRegularExpression(
                "/\\(N'(EMPLOYEE|GUEST|ADMINISTRATOR)',\\s*N'FINANCE\\./",
                self::script($name),
                $name,
            );
        }
        // D3: Tri-Task permissions are in neither platform list.
        foreach (Module::TASK_PERMISSIONS as $code) {
            self::assertNotContains($code, Module::ADMINISTRATOR_PERMISSIONS);
            self::assertNotContains($code, Module::EMPLOYEE_PERMISSIONS);
        }
    }

    public function test_both_scripts_are_guarded_and_say_they_are_not_executed(): void
    {
        foreach (['seed-finance-task-permissions.sql', 'seed-finance-task-roles.sql'] as $name) {
            $sql = self::script($name);

            self::assertStringContainsString('WRITTEN, NOT EXECUTED against any shared database', $sql, $name);
            self::assertStringContainsString('THROW 54101', $sql, $name);
            self::assertStringContainsString('THROW 54102', $sql, $name);
            // Statements only: a permission's name and description may say "delete".
            self::assertDoesNotMatchRegularExpression('/\\bDELETE\\s+(FROM\\s+)?[@\\[#]/i', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/\\bDROP\\s+(TABLE|INDEX|SCHEMA|CONSTRAINT)\\b/i', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/INSERT\\s+INTO\\s+\\[identity\\]\\.\\[(Users|UserRoles)\\]/i', $sql, $name);
        }
    }

    public function test_the_roles_are_assigned_to_nobody(): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/\\[identity\\]\\.\\[UserRoles\\]/i',
            self::script('seed-finance-task-roles.sql'),
        );
    }

    public function test_both_scripts_are_in_the_local_rebuild_in_order(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');
        $order = ['seed-finance-permissions.sql', 'seed-finance-roles.sql', 'seed-finance-task-permissions.sql', 'seed-finance-task-roles.sql'];

        $last = -1;
        foreach ($order as $name) {
            $at = strpos($rebuild, "'" . $name . "'");
            self::assertIsInt($at, $name);
            self::assertGreaterThan($last, $at, $name . ' runs after the one before it.');
            $last = $at;
        }
    }
}
