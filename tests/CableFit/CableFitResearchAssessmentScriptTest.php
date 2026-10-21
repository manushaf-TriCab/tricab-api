<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * alter-cablefit-research-assessment.sql (C4.3) is applied to a live [cablefit] schema, so every change in it must be
 * guarded and nullable, and the create script must stay as it was.
 */
final class CableFitResearchAssessmentScriptTest extends TestCase
{
    private const SCRIPT = 'alter-cablefit-research-assessment.sql';

    private static function read(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/database/scripts/development/' . $file;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function columns(): array
    {
        return [
            'candidate revision' => ['research_runs', 'candidate_revision_number', 'INT NULL'],
            'application code' => ['research_runs', 'application_code', 'NVARCHAR(32) NULL'],
            'standard code' => ['research_runs', 'standard_code', 'NVARCHAR(64) NULL'],
            'rule check' => ['research_runs', 'rule_check_json', 'NVARCHAR(MAX) NULL'],
            'verdict' => ['research_runs', 'verdict', 'NVARCHAR(32) NULL'],
            'assessment' => ['research_runs', 'assessment_json', 'NVARCHAR(MAX) NULL'],
            'stance' => ['research_findings', 'stance', 'NVARCHAR(16) NULL'],
        ];
    }

    #[DataProvider('columns')]
    public function test_each_column_is_nullable_and_added_only_when_missing(string $table, string $column, string $type): void
    {
        $sql = self::read(self::SCRIPT);

        self::assertStringContainsString("COL_LENGTH(N'[cablefit].[$table]', N'$column') IS NULL", $sql);
        self::assertStringContainsString("ALTER TABLE [cablefit].[$table] ADD [$column] $type;", $sql);
    }

    public function test_the_checks_allow_null_and_name_exactly_the_agreed_values(): void
    {
        $sql = self::read(self::SCRIPT);

        self::assertStringContainsString(
            "[verdict] IS NULL OR [verdict] IN\n            (N'likely_suitable', N'suitable_with_concerns', N'likely_unsuitable', N'insufficient_evidence')",
            $sql,
        );
        self::assertStringContainsString("[stance] IS NULL OR [stance] IN (N'supports', N'concern', N'neutral')", $sql);
        self::assertStringContainsString('[assessment_json] IS NULL OR ISJSON([assessment_json]) = 1', $sql);
        self::assertStringContainsString('[rule_check_json] IS NULL OR ISJSON([rule_check_json]) = 1', $sql);
    }

    public function test_every_constraint_and_the_index_are_guarded(): void
    {
        $sql = self::read(self::SCRIPT);

        preg_match_all('/ADD CONSTRAINT \[(\w+)\]/', $sql, $added);
        self::assertCount(4, $added[1]);
        foreach ($added[1] as $name) {
            self::assertStringContainsString("sys.check_constraints WHERE name = N'$name'", $sql);
        }
        self::assertStringContainsString("sys.indexes WHERE name = N'IX_cablefit_research_runs_reuse'", $sql);
        self::assertStringContainsString(
            '([candidate_id], [candidate_revision_number], [application_code], [standard_code], [status])',
            $sql,
        );
    }

    public function test_no_check_shares_a_batch_with_the_column_it_names(): void
    {
        foreach (preg_split('/^GO\s*$/m', self::read(self::SCRIPT)) ?: [] as $batch) {
            if (preg_match('/ADD \[(\w+)\] /', $batch, $column) === 1) {
                self::assertStringNotContainsString('CHECK', $batch, 'batch adding ' . $column[1]);
            }
        }
    }

    public function test_it_changes_no_data_and_leaves_the_create_script_alone(): void
    {
        $sql = self::read(self::SCRIPT);

        foreach (['UPDATE ', 'DELETE ', 'INSERT ', 'DROP '] as $statement) {
            self::assertStringNotContainsString($statement, $sql);
        }
        $create = self::read('create-cablefit-schema.sql');
        self::assertStringNotContainsString('[verdict]', $create);
        self::assertStringNotContainsString('[stance]', $create);
    }
}
