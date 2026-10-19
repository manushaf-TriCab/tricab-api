<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Tsd;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Tsd\Module;

/** The TSD permission seed says what `Module` says, and maps no role. */
final class TsdSeedScriptTest extends TestCase
{
    private static function script(): string
    {
        $path = dirname(__DIR__, 2) . '/database/scripts/development/seed-tsd-permissions.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_the_seed_creates_exactly_the_module_permissions(): void
    {
        preg_match_all("/^\\s*\\(N'(TSD\\.[A-Z.]+)',/m", self::script(), $matches);
        $seeded = $matches[1];
        sort($seeded);

        $expected = Module::PERMISSIONS;
        sort($expected);

        self::assertSame($expected, $seeded);
    }

    public function test_the_seed_maps_no_role(): void
    {
        self::assertStringNotContainsString('RolePermissions', self::script());
    }
}
