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

    /** No implicit grant: neither script maps anything to EMPLOYEE or GUEST. */
    public function test_neither_script_grants_to_every_employee_or_to_guests(): void
    {
        foreach (['seed-rfq-permissions.sql', 'seed-rfq-roles.sql'] as $name) {
            $sql = self::script($name);
            self::assertDoesNotMatchRegularExpression("/\\(N'(EMPLOYEE|GUEST)',\\s*N'RFQ\\./", $sql, $name);
        }
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
