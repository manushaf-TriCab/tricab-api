<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Tests\Finance\Ar\LocalArIdentityServer;
use TriCab\Core\Finance\Module;

/**
 * The two Tri-AP seed scripts say what `Module` says, and nothing else
 * (Phase 6, task T6.1). Same approach as `FinanceArSeedScriptTest`: two lists
 * of the same permissions drift the first time somebody edits one of them, so
 * the drift is a failing test instead of a 403 in production.
 */
final class FinanceApSeedScriptTest extends TestCase
{
    private const CODE = 'FINANCE\\.[A-Z0-9_.]+';

    private const SCRIPTS = ['seed-finance-ap-permissions.sql', 'seed-finance-ap-roles.sql'];

    private static function script(string $name): string
    {
        $path = dirname(__DIR__, 3) . '/database/scripts/development/' . $name;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_the_permission_seed_creates_exactly_the_eight_ap_permissions(): void
    {
        preg_match_all("/^\\s*\\(N'(" . self::CODE . ")',/m", self::script('seed-finance-ap-permissions.sql'), $matches);
        $seeded = $matches[1];
        sort($seeded);

        $expected = Module::AP_PERMISSIONS;
        sort($expected);

        self::assertCount(8, $expected);
        self::assertSame($expected, $seeded);
    }

    public function test_ap_permissions_are_all_under_the_ap_app_and_repeat_no_other_apps(): void
    {
        foreach (Module::AP_PERMISSIONS as $code) {
            self::assertStringStartsWith('FINANCE.AP.', $code);
            self::assertNotContains($code, Module::PERSON_PERMISSIONS, $code);
            self::assertNotContains($code, Module::TASK_PERMISSIONS, $code);
            self::assertNotContains($code, Module::AR_PERMISSIONS, $code);
        }
        self::assertSame(count(Module::AP_PERMISSIONS), count(array_unique(Module::AP_PERMISSIONS)));
    }

    public function test_the_role_seed_grants_exactly_the_module_ap_role_permissions(): void
    {
        preg_match_all(
            "/\\(N'(FINANCE_[A-Z_]+)', N'(" . self::CODE . ")'\\)/",
            self::script('seed-finance-ap-roles.sql'),
            $matches,
            PREG_SET_ORDER,
        );

        $seeded = [];
        foreach ($matches as $match) {
            $seeded[$match[1]][] = $match[2];
        }

        self::assertSame(Module::AP_ROLE_PERMISSIONS, $seeded);
    }

    public function test_the_role_seed_creates_exactly_the_four_roles(): void
    {
        preg_match_all("/^\\s*\\(N'(FINANCE_[A-Z_]+)',\\s*N'[^']+',\\s*$/m", self::script('seed-finance-ap-roles.sql'), $matches);

        self::assertSame(
            [Module::ROLE_AP_OPERATOR, Module::ROLE_AP_PREPARER, Module::ROLE_AP_APPROVER, Module::ROLE_AP_ADMIN],
            $matches[1],
        );
    }

    public function test_the_roles_are_the_portals_and_are_not_a_ladder(): void
    {
        $own = static fn (string $role): array => array_values(array_diff(
            Module::AP_ROLE_PERMISSIONS[$role],
            [Module::PERMISSION_ACCESS, Module::PERMISSION_AP_ACCESS],
        ));

        // permissions.md section 8, from the portal's ROLE_SEED.
        self::assertSame(
            [Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_INVOICES_WRITE, Module::PERMISSION_AP_MAIL_READ, Module::PERMISSION_AP_MAIL_SEND],
            $own(Module::ROLE_AP_OPERATOR),
        );
        self::assertSame([Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_APPROVALS_PREPARE], $own(Module::ROLE_AP_PREPARER));
        self::assertSame([Module::PERMISSION_AP_INVOICES_READ, Module::PERMISSION_AP_APPROVALS_DECIDE], $own(Module::ROLE_AP_APPROVER));

        // Nobody but the administrator both prepares and decides, or deletes for good.
        foreach ([Module::ROLE_AP_OPERATOR, Module::ROLE_AP_PREPARER, Module::ROLE_AP_APPROVER] as $role) {
            self::assertNotContains(Module::PERMISSION_AP_INVOICES_DELETE, $own($role), $role);
            self::assertNotContains(Module::PERMISSION_AP_SETTINGS_MANAGE, $own($role), $role);
        }
        self::assertNotContains(Module::PERMISSION_AP_APPROVALS_DECIDE, $own(Module::ROLE_AP_PREPARER));
        self::assertNotContains(Module::PERMISSION_AP_APPROVALS_PREPARE, $own(Module::ROLE_AP_APPROVER));
    }

    public function test_the_administrator_holds_every_ap_permission(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(Module::AP_PERMISSIONS, Module::AP_ROLE_PERMISSIONS[Module::ROLE_AP_ADMIN])),
        );
    }

    public function test_every_ap_role_can_open_the_launcher_and_the_app_and_read_invoices(): void
    {
        foreach (Module::AP_ROLE_PERMISSIONS as $role => $permissions) {
            self::assertContains(Module::PERMISSION_ACCESS, $permissions, $role);
            self::assertContains(Module::PERMISSION_AP_ACCESS, $permissions, $role);
            self::assertContains(Module::PERMISSION_AP_INVOICES_READ, $permissions, $role);
            self::assertSame(count($permissions), count(array_unique($permissions)), $role);
            // Nothing outside Tri-AP's own permissions, the launcher and the app's access permission.
            $allowed = [...Module::AP_PERMISSIONS, Module::PERMISSION_ACCESS, Module::PERMISSION_AP_ACCESS];
            self::assertSame([], array_values(array_diff($permissions, $allowed)), $role);
        }
    }

    public function test_the_portal_string_table_covers_every_ap_permission_with_the_portals_invoice_prefix(): void
    {
        $table = Module::AP_PORTAL_PERMISSIONS;

        self::assertEqualsCanonicalizing(Module::AP_PERMISSIONS, array_keys($table));
        self::assertSame(count($table), count(array_unique($table)), 'No two codes share a portal string.');
        foreach ($table as $code => $portal) {
            // The portal calls the app `invoice` (permissions.md section 1), so the prefix is not the app key.
            self::assertSame('invoice.' . strtolower(substr($code, strlen('FINANCE.AP.'))), $portal);
        }
        self::assertSame('invoice.approvals.decide', $table[Module::PERMISSION_AP_APPROVALS_DECIDE]);
    }

    public function test_the_platform_roles_are_untouched_and_the_catalogues_do_not_include_ap_permissions(): void
    {
        foreach (self::SCRIPTS as $name) {
            self::assertDoesNotMatchRegularExpression(
                "/\\(N'(EMPLOYEE|GUEST|ADMINISTRATOR|FINANCE_ADMIN)',\\s*N'FINANCE\\./",
                self::script($name),
                $name,
            );
        }
        foreach (Module::AP_PERMISSIONS as $code) {
            self::assertNotContains($code, Module::ADMINISTRATOR_PERMISSIONS);
            self::assertNotContains($code, Module::EMPLOYEE_PERMISSIONS);
        }
    }

    public function test_both_scripts_are_guarded_say_they_are_not_executed_and_assign_nobody(): void
    {
        foreach (self::SCRIPTS as $name) {
            $sql = self::script($name);

            self::assertStringContainsString('WRITTEN, NOT EXECUTED against any shared database', $sql, $name);
            self::assertStringContainsString('THROW 54101', $sql, $name);
            self::assertStringContainsString('THROW 54102', $sql, $name);
            self::assertStringNotContainsString('Tri-AR', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/\\bDELETE\\s+(FROM\\s+)?[@\\[#]/i', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/\\bDROP\\s+(TABLE|INDEX|SCHEMA|CONSTRAINT)\\b/i', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/\\[identity\\]\\.\\[(Users|UserRoles)\\]/i', $sql, $name);
        }
    }

    public function test_both_scripts_are_in_the_local_rebuild_in_order(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');
        $order = ['seed-finance-permissions.sql', 'seed-finance-roles.sql', 'seed-finance-ap-permissions.sql', 'seed-finance-ap-roles.sql'];

        $last = -1;
        foreach ($order as $name) {
            $at = strpos($rebuild, "'" . $name . "'");
            self::assertIsInt($at, $name);
            self::assertGreaterThan($last, $at, $name . ' runs after the one before it.');
            $last = $at;
        }
    }

    #[Group('local-sql-server')]
    public function test_both_scripts_run_twice_on_a_real_local_server_with_the_same_result(): void
    {
        $pdo = LocalArIdentityServer::connect();
        if ($pdo === null) {
            self::markTestSkipped('No local SQL Server (set FINANCE_TEST_SQLSERVER and FINANCE_TEST_SQLSERVER_PASSWORD).');
        }
        foreach ([1, 2] as $pass) {
            foreach (self::SCRIPTS as $name) {
                $statement = $pdo->query(self::script($name));
                self::assertInstanceOf(PDOStatement::class, $statement);
                // Walk every result set, so an error raised after the first one is not lost.
                do {
                    if ($statement->columnCount() > 0) {
                        $statement->fetchAll();
                    }
                } while ($statement->nextRowset());
            }
            $held = [];
            $rows = $pdo->query(
                "SELECT r.[RoleCode], COUNT(*) AS [n] FROM [identity].[RolePermissions] rp
                 JOIN [identity].[Roles] r ON r.[RoleId] = rp.[RoleId]
                 WHERE rp.[IsActive] = 1 AND r.[RoleCode] LIKE N'FINANCE[_]AP[_]%' GROUP BY r.[RoleCode]",
            );
            self::assertInstanceOf(PDOStatement::class, $rows);
            foreach ($rows->fetchAll(PDO::FETCH_KEY_PAIR) as $role => $count) {
                self::assertIsString($role);
                self::assertIsNumeric($count);
                $held[$role] = (int) $count;
            }
            ksort($held);
            self::assertSame(
                ['FINANCE_AP_ADMIN' => 10, 'FINANCE_AP_APPROVER' => 4, 'FINANCE_AP_OPERATOR' => 6, 'FINANCE_AP_PREPARER' => 4],
                $held,
                'pass ' . $pass,
            );
        }
        $assigned = $pdo->query("SELECT COUNT(*) FROM [identity].[UserRoles] ur JOIN [identity].[Roles] r ON r.[RoleId] = ur.[RoleId] WHERE r.[RoleCode] LIKE N'FINANCE[_]AP[_]%'");
        self::assertInstanceOf(PDOStatement::class, $assigned);
        self::assertSame(0, (int) $assigned->fetchColumn(), 'the roles are assigned to nobody');
    }
}
