<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CableFit;

use PHPUnit\Framework\TestCase;

/** The research tables are guarded and constrained; the sources seed is the agreed first list and never overwrites. */
final class ResearchScriptsTest extends TestCase
{
    private static function read(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/database/scripts/development/' . $file;
        $sql = file_get_contents($path);
        self::assertIsString($sql, $path);

        return $sql;
    }

    public function test_the_schema_creates_the_three_research_tables_idempotently(): void
    {
        $sql = self::read('create-cablefit-schema.sql');

        foreach (['research_sources', 'research_runs', 'research_findings'] as $table) {
            self::assertStringContainsString("OBJECT_ID(N'[cablefit].[" . $table . "]', N'U') IS NULL", $sql);
        }
        self::assertStringContainsString("name = N'IX_cablefit_research_runs_status_requested'", $sql);
        self::assertMatchesRegularExpression('/ON \[cablefit\]\.\[research_runs\] \(\[status\], \[requested_at\]\)/', $sql);
    }

    public function test_sources_columns_and_constraints(): void
    {
        $sql = self::read('create-cablefit-schema.sql');

        self::assertMatchesRegularExpression('/\[domain\]\s+NVARCHAR\(253\) NOT NULL/', $sql);
        self::assertStringContainsString('UNIQUE ([domain])', $sql);
        self::assertStringContainsString("N'standards_body', N'manufacturer', N'technical_publisher', N'other'", $sql);
        self::assertMatchesRegularExpression('/\[enabled\]\s+BIT NOT NULL/', $sql);
        self::assertMatchesRegularExpression('/\[created_by_user_id\]\s+BIGINT NULL/', $sql);
        self::assertStringContainsString('LOWER([domain])', $sql);
    }

    public function test_runs_columns_and_constraints(): void
    {
        $sql = self::read('create-cablefit-schema.sql');

        foreach (['candidate_id', 'status', 'application', 'parameters_json', 'sources_used_json', 'model', 'input_tokens', 'output_tokens', 'search_requests', 'dropped_findings', 'error', 'requested_by_user_id', 'requested_at', 'started_at', 'finished_at'] as $column) {
            self::assertStringContainsString('[' . $column . ']', $sql, $column);
        }
        self::assertStringContainsString("N'queued', N'running', N'succeeded', N'failed'", $sql);
        self::assertStringContainsString('ISJSON([parameters_json]) = 1', $sql);
        self::assertStringContainsString('REFERENCES [identity].[Users]([UserId])', $sql);
        self::assertStringContainsString('REFERENCES [cablefit].[candidates]([candidate_id])', $sql);
    }

    public function test_findings_columns_and_constraints(): void
    {
        $sql = self::read('create-cablefit-schema.sql');

        self::assertMatchesRegularExpression('/\[published_on\]\s+NVARCHAR\(10\) NULL/', $sql);
        self::assertStringContainsString("N'page_age', N'stated_on_page', N'undated'", $sql);
        self::assertStringContainsString("N'high', N'medium', N'low'", $sql);
        self::assertMatchesRegularExpression('/REFERENCES \[cablefit\]\.\[research_runs\]\(\[run_id\]\) ON DELETE CASCADE/', $sql);
        foreach (['parameter', 'claim', 'relevance', 'url', 'title', 'publisher', 'sort_order'] as $column) {
            self::assertStringContainsString('[' . $column . ']', $sql, $column);
        }
    }

    public function test_the_seed_is_the_agreed_first_list(): void
    {
        $sql = self::read('seed-cablefit-research-sources.sql');
        preg_match_all("/^\\s*\\(N'([a-z0-9.]+)',\\s+N'[^']+',\\s+N'([a-z_]+)'\\)/m", $sql, $m, PREG_SET_ORDER);
        $byDomain = [];
        foreach ($m as $row) {
            $byDomain[$row[1]] = $row[2];
        }

        self::assertSame([
            'iec.ch' => 'standards_body',
            'standards.org.au' => 'standards_body',
            'ieee.org' => 'standards_body',
            'cigre.org' => 'standards_body',
            'iso.org' => 'standards_body',
            'prysmian.com' => 'manufacturer',
            'nexans.com' => 'manufacturer',
            'southwire.com' => 'manufacturer',
            'generalcable.com' => 'manufacturer',
            'lscns.com' => 'manufacturer',
            'sumitomoelectric.com' => 'manufacturer',
            'olex.com.au' => 'manufacturer',
            'theiet.org' => 'technical_publisher',
            'sciencedirect.com' => 'technical_publisher',
            'springer.com' => 'technical_publisher',
            'mdpi.com' => 'technical_publisher',
        ], $byDomain);
    }

    public function test_the_seed_only_inserts_missing_domains_and_says_chabilal_reviews_it(): void
    {
        $sql = self::read('seed-cablefit-research-sources.sql');

        self::assertStringContainsString('Chabilal', $sql);
        self::assertStringContainsString('OPEN QUESTION', $sql);
        self::assertStringContainsString('WHERE NOT EXISTS', $sql);
        self::assertDoesNotMatchRegularExpression('/\b(UPDATE|DELETE|MERGE|DROP|TRUNCATE)\b\s/i', preg_replace('~/\*.*?\*/~s', '', $sql) ?? '');
        // System rows: no author is invented.
        self::assertStringNotContainsString('created_by_user_id], [created_at]', $sql);
    }
}
