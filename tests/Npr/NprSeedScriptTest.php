<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Npr;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Npr\Module;

/** The NPR seed scripts say what `Module` says, and nothing else. */
final class NprSeedScriptTest extends TestCase
{
    private static function script(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/database/scripts/development/' . $name;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_the_permission_seed_creates_exactly_the_module_permissions(): void
    {
        preg_match_all("/^\\s*\\(N'(NPR\\.[A-Z.]+)',/m", self::script('seed-npr-permissions.sql'), $matches);
        $seeded = $matches[1];
        sort($seeded);

        $expected = Module::PERMISSIONS;
        sort($expected);

        self::assertSame($expected, $seeded);
    }

    public function test_the_permission_seed_maps_no_role(): void
    {
        self::assertStringNotContainsString('RolePermissions', self::script('seed-npr-permissions.sql'));
    }

    public function test_the_role_seed_grants_exactly_the_module_role_permissions(): void
    {
        preg_match_all(
            "/\\(N'([A-Z_]+)', N'((?:NPR|TSD)\\.[A-Z.]+)'\\)/",
            self::script('seed-npr-roles.sql'),
            $matches,
            PREG_SET_ORDER,
        );
        $seeded = [];
        foreach ($matches as $m) {
            $seeded[$m[1]][] = $m[2];
        }

        self::assertSame(Module::ROLE_PERMISSIONS, $seeded);
    }

    public function test_the_role_seed_maps_only_npr_and_tsd_access_and_touches_no_platform_role(): void
    {
        foreach (Module::ROLE_PERMISSIONS as $permissions) {
            foreach ($permissions as $permission) {
                self::assertMatchesRegularExpression('/^(NPR\\.|TSD\\.ACCESS$)/', $permission);
            }
        }
        self::assertDoesNotMatchRegularExpression(
            "/\\(N'(EMPLOYEE|GUEST|ADMINISTRATOR)',\\s*N'/",
            self::script('seed-npr-roles.sql'),
        );
    }

    public function test_the_admin_role_holds_npr_admin_and_can_see_the_queue(): void
    {
        self::assertContains(Module::PERMISSION_ADMIN, Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]);
        self::assertContains(Module::PERMISSION_TSD_READ, Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]);
        self::assertContains('TSD.ACCESS', Module::ROLE_PERMISSIONS[Module::ROLE_ADMIN]);
        self::assertStringContainsString("N'NPR_ADMIN',      N'NPR Admin'", self::script('seed-npr-roles.sql'));
        foreach ([Module::ROLE_REQUESTER, Module::ROLE_MANAGER, Module::ROLE_TSD_ENGINEER, Module::ROLE_TSD_SIGNATORY, Module::ROLE_VIEWER] as $other) {
            self::assertNotContains(Module::PERMISSION_ADMIN, Module::ROLE_PERMISSIONS[$other]);
        }
    }

    public function test_the_role_seed_fails_clearly_without_tsd_access(): void
    {
        self::assertStringContainsString('TSD.ACCESS is missing', self::script('seed-npr-roles.sql'));
    }

    public function test_the_schema_has_every_table_and_no_cablefit(): void
    {
        $sql = self::script('create-npr-schema.sql');
        foreach (['Requests', 'RequestRevisions', 'Endorsements', 'Attachments', 'Activity',
            'NotificationOutbox', 'NotificationDeliveries', 'ManagerRoutes'] as $table) {
            self::assertStringContainsString("OBJECT_ID(N'[npr].[$table]'", $sql);
        }
        self::assertDoesNotMatchRegularExpression('/\[npr\]\.\[cablefit/i', $sql);
    }
}
