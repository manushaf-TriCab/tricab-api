<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Rfq;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Module;

/**
 * The two RFQ seed scripts say what `Module` says, and nothing else.
 *
 * Two lists of the same permissions drift the first time somebody edits one
 * of them; this makes the drift a failing test instead of a 403 in production.
 */
final class RfqSeedScriptTest extends TestCase
{
    private static function script(string $name): string
    {
        $path = dirname(__DIR__, 2) . '/database/scripts/development/' . $name;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_the_permission_seed_creates_exactly_the_module_catalogue(): void
    {
        preg_match_all("/^\\s*\\(N'(RFQ\\.[A-Z.]+)',/m", self::script('seed-rfq-permissions.sql'), $matches);
        $seeded = $matches[1];
        sort($seeded);

        $expected = [...Module::PERSON_PERMISSIONS, ...Module::INTEGRATION_PERMISSIONS];
        sort($expected);

        self::assertSame($expected, $seeded);
    }

    public function test_the_role_seed_grants_exactly_the_module_role_permissions(): void
    {
        preg_match_all("/\\(N'(RFQ_[A-Z_]+)', N'(RFQ\\.[A-Z.]+)'\\)/", self::script('seed-rfq-roles.sql'), $matches, PREG_SET_ORDER);

        $seeded = [];
        foreach ($matches as $match) {
            $seeded[$match[1]][] = $match[2];
        }

        self::assertSame(Module::ROLE_PERMISSIONS, $seeded);
    }

    /**
     * The platform roles get exactly what `Module` says: ADMINISTRATOR
     * administers, EMPLOYEE reads every RFQ (owner decision, 28 Sep 2026), and
     * GUEST gets nothing. The role seed touches none of the three.
     */
    public function test_platform_roles_get_exactly_the_module_grants_and_guests_nothing(): void
    {
        preg_match_all("/\\(N'([A-Z_]+)',\\s*N'(RFQ\\.[A-Z.]+)'\\)/", self::script('seed-rfq-permissions.sql'), $matches, PREG_SET_ORDER);
        $mapped = [];
        foreach ($matches as $match) {
            $mapped[$match[1]][] = $match[2];
        }
        self::assertSame(
            ['ADMINISTRATOR' => Module::ADMINISTRATOR_PERMISSIONS, 'EMPLOYEE' => Module::EMPLOYEE_PERMISSIONS],
            $mapped,
        );
        self::assertDoesNotMatchRegularExpression("/\\(N'(EMPLOYEE|GUEST|ADMINISTRATOR)',\\s*N'RFQ\\./", self::script('seed-rfq-roles.sql'));
    }

    public function test_both_scripts_are_part_of_the_local_rebuild(): void
    {
        $rebuild = self::script('rebuild-local-db.ps1');

        self::assertStringContainsString("'seed-rfq-permissions.sql'", $rebuild);
        self::assertStringContainsString("'seed-rfq-roles.sql'", $rebuild);
        self::assertLessThan(
            strpos($rebuild, "'seed-rfq-roles.sql'"),
            strpos($rebuild, "'seed-rfq-permissions.sql'"),
            'Roles are seeded after the permissions they grant.',
        );
    }
}
