<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Module;

/**
 * The two finance seed scripts say what `Module` says, and nothing else.
 *
 * Copied from `RfqSeedScriptTest`. Two lists of the same permissions drift the
 * first time somebody edits one of them; this makes the drift a failing test
 * instead of a 403 in production. The patterns allow `_` in codes
 * (`UPDATE_OWN`), which the RFQ test's do not, and digits.
 */
final class FinanceSeedScriptTest extends TestCase
{
    private const CODE = 'FINANCE\\.[A-Z0-9_.]+';

    private static function script(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/database/scripts/development/' . $name;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_the_permission_seed_creates_exactly_the_module_catalogue(): void
    {
        preg_match_all("/^\\s*\\(N'(" . self::CODE . ")',/m", self::script('seed-finance-permissions.sql'), $matches);
        $seeded = $matches[1];
        sort($seeded);

        $expected = Module::PERSON_PERMISSIONS;
        sort($expected);

        self::assertCount(8, $expected);
        self::assertSame($expected, $seeded);
    }

    public function test_the_module_defines_one_access_permission_per_app_and_the_shared_two(): void
    {
        $expected = ['FINANCE.ACCESS', 'FINANCE.ADMIN'];
        foreach (array_keys(Module::APPS) as $key) {
            $expected[] = Module::appAccessPermission($key);
        }
        sort($expected);
        $actual = Module::PERSON_PERMISSIONS;
        sort($actual);

        self::assertSame($expected, $actual);
    }

    public function test_the_role_seed_grants_exactly_the_module_role_permissions(): void
    {
        preg_match_all(
            "/\\(N'(FINANCE_[A-Z_]+)', N'(" . self::CODE . ")'\\)/",
            self::script('seed-finance-roles.sql'),
            $matches,
            PREG_SET_ORDER,
        );

        $seeded = [];
        foreach ($matches as $match) {
            $seeded[$match[1]][] = $match[2];
        }

        self::assertSame(Module::ROLE_PERMISSIONS, $seeded);
    }

    /**
     * The platform roles get exactly what `Module` says: ADMINISTRATOR
     * administers (decision D3), EMPLOYEE and GUEST get nothing. The role seed
     * touches none of the three.
     */
    public function test_platform_roles_get_exactly_the_module_grants_and_guests_nothing(): void
    {
        preg_match_all(
            "/\\(N'([A-Z_]+)',\\s*N'(" . self::CODE . ")'\\)/",
            self::script('seed-finance-permissions.sql'),
            $matches,
            PREG_SET_ORDER,
        );
        $mapped = [];
        foreach ($matches as $match) {
            $mapped[$match[1]][] = $match[2];
        }

        self::assertSame(['ADMINISTRATOR' => Module::ADMINISTRATOR_PERMISSIONS], $mapped);
        self::assertSame([], Module::EMPLOYEE_PERMISSIONS);
        self::assertDoesNotMatchRegularExpression(
            "/\\(N'(EMPLOYEE|GUEST|ADMINISTRATOR)',\\s*N'FINANCE\\./",
            self::script('seed-finance-roles.sql'),
        );
    }

    public function test_no_role_holds_a_permission_the_catalogue_does_not_define(): void
    {
        foreach (Module::ROLE_PERMISSIONS as $role => $permissions) {
            self::assertSame([], array_values(array_diff($permissions, Module::PERSON_PERMISSIONS)), $role);
        }
        self::assertSame([], array_values(array_diff(Module::ADMINISTRATOR_PERMISSIONS, Module::PERSON_PERMISSIONS)));
    }

    public function test_both_scripts_touch_nothing_outside_the_finance_family(): void
    {
        foreach (['seed-finance-permissions.sql', 'seed-finance-roles.sql'] as $name) {
            $sql = self::script($name);

            self::assertDoesNotMatchRegularExpression('/\\bDELETE\\b/i', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/\\bDROP\\b/i', $sql, $name);
            self::assertDoesNotMatchRegularExpression('/INSERT\\s+INTO\\s+\\[identity\\]\\.\\[(Users|UserRoles)\\]/i', $sql, $name);
        }
    }

    public function test_both_scripts_are_part_of_the_local_rebuild_after_the_rfq_seeds(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');

        self::assertStringContainsString("'seed-finance-permissions.sql'", $rebuild);
        self::assertStringContainsString("'seed-finance-roles.sql'", $rebuild);
        self::assertLessThan(
            strpos($rebuild, "'seed-finance-roles.sql'"),
            strpos($rebuild, "'seed-finance-permissions.sql'"),
            'Roles are seeded after the permissions they grant.',
        );
        self::assertLessThan(
            strpos($rebuild, "'seed-finance-permissions.sql'"),
            strpos($rebuild, "'seed-rfq-roles.sql'"),
            'Finance seeds run after the RFQ seeds.',
        );
    }
}
