<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use PHPUnit\Framework\TestCase;

/** alter-cablefit-candidate-delete.sql is applied to a live [cablefit] schema: every change guarded and nullable. */
final class CableFitCandidateDeleteScriptTest extends TestCase
{
    private static function read(): string
    {
        $path = dirname(__DIR__, 2) . '/database/scripts/development/alter-cablefit-candidate-delete.sql';
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_both_columns_are_nullable_and_added_only_when_missing(): void
    {
        $sql = self::read();

        self::assertStringContainsString("COL_LENGTH(N'[cablefit].[candidates]', N'deleted_at') IS NULL", $sql);
        self::assertStringContainsString('ALTER TABLE [cablefit].[candidates] ADD [deleted_at] DATETIME2(7) NULL;', $sql);
        self::assertStringContainsString("COL_LENGTH(N'[cablefit].[candidates]', N'deleted_by_user_id') IS NULL", $sql);
        self::assertStringContainsString('ALTER TABLE [cablefit].[candidates] ADD [deleted_by_user_id] BIGINT NULL;', $sql);
    }

    public function test_the_foreign_key_is_guarded_and_points_at_users(): void
    {
        $sql = self::read();

        self::assertStringContainsString("WHERE name = N'FK_cablefit_candidates_deleted_by'", $sql);
        self::assertStringContainsString('FOREIGN KEY ([deleted_by_user_id]) REFERENCES [identity].[Users]([UserId])', $sql);
    }

    public function test_it_deletes_and_updates_nothing(): void
    {
        $sql = self::read();

        self::assertDoesNotMatchRegularExpression('/^\s*(DELETE|UPDATE|DROP)\b/mi', $sql);
    }
}
