<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;

/**
 * Least privilege for the reports (4.5), held at the query: the report
 * repository reads no restricted HR table, no email address, no score, no
 * answer and no note.
 *
 * Read from the source rather than exercised, for the reason
 * `OnboardingPeopleRepositoryBoundaryTest` gives: the failure is a column added
 * later "so HR can email the stragglers", which no behavioural test of today's
 * queries would notice.
 */
final class OnboardingReportRepositoryBoundaryTest extends TestCase
{
    public function test_the_report_repository_reads_nothing_restricted_and_no_contact_details(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Onboarding/Persistence/AzureSqlOnboardingReportRepository.php');
        self::assertIsString($source);

        $sql = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
        self::assertIsString($sql);

        self::assertDoesNotMatchRegularExpression('#onboarding_hr#i', $sql);
        foreach (['EmailAddress', 'Mobile', 'Phone', 'ScorePercent', 'Answers', 'ReviewerNote', 'Note]', 'Submission', 'Acknowledgement'] as $column) {
            self::assertStringNotContainsStringIgnoringCase($column, $sql, $column);
        }
    }
}
