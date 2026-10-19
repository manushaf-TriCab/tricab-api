<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use PHPUnit\Framework\TestCase;

/** The CableFit scripts seed the two permissions, look the role up by name, and are guarded. */
final class CableFitSeedScriptTest extends TestCase
{
    private static function read(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/database/scripts/development/' . $file;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_the_seed_creates_exactly_the_cablefit_permissions(): void
    {
        preg_match_all("/^\\s*\\(N'(TSD\\.[A-Z.]+)',/m", self::read('seed-cablefit-permissions.sql'), $matches);
        $seeded = $matches[1];
        sort($seeded);

        self::assertSame(['TSD.CABLEFIT.RESEARCH', 'TSD.CABLEFIT.USE'], $seeded);
    }

    public function test_the_seed_finds_the_role_by_name_and_skips_when_missing(): void
    {
        $sql = self::read('seed-cablefit-permissions.sql');

        self::assertStringContainsString("[RoleName] = N'TSD Engineer'", $sql);
        self::assertStringContainsString('PRINT', $sql);
        self::assertStringNotContainsString('INSERT INTO [identity].[Roles]', $sql);
    }

    public function test_the_schema_script_is_guarded_and_allows_designs_without_a_request(): void
    {
        $sql = self::read('create-cablefit-schema.sql');

        self::assertStringContainsString("OBJECT_ID(N'[cablefit].[candidates]', N'U') IS NULL", $sql);
        self::assertStringContainsString("OBJECT_ID(N'[cablefit].[candidate_revisions]', N'U') IS NULL", $sql);
        self::assertMatchesRegularExpression('/\[npr_request_public_id\]\s+NVARCHAR\(64\) NULL/', $sql);
        self::assertMatchesRegularExpression('/\[npr_variant_id\]\s+NVARCHAR\(64\) NULL/', $sql);
        self::assertStringNotContainsString('ROWVERSION', $sql);
        self::assertMatchesRegularExpression('/\[candidate_name\]\s+NVARCHAR\(255\) NOT NULL/', $sql);
        self::assertMatchesRegularExpression('/\[current_revision_number\]\s+INT NOT NULL/', $sql);
        foreach (['chosen_alternatives_json', 'partial_import_json', 'unresolved_requirements_json', 'construction_json', 'npr_request_revision', 'row_version'] as $dropped) {
            self::assertStringNotContainsString('[' . $dropped . ']', $sql);
        }
        self::assertStringContainsString('[design_json]', $sql);
        self::assertStringContainsString('UNIQUE ([candidate_id], [revision_number])', $sql);
    }
}
