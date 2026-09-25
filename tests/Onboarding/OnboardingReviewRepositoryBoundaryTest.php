<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;

/**
 * Invariant 11, for the review screen: the review repository may read which
 * fields are restricted (`[onboarding_hr].[FieldClassifications]`), and never
 * what they say.
 *
 * Read from the source rather than exercised, for the reason
 * `OnboardingPeopleRepositoryBoundaryTest` gives: the failure is a join added
 * later "so the reviewer can check the bank details", which no behavioural test
 * of today's queries would notice. A reviewer who needs those reads them through
 * the audited document route.
 */
final class OnboardingReviewRepositoryBoundaryTest extends TestCase
{
    public function test_the_review_repository_reads_only_field_classifications_from_restricted_hr(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Onboarding/Persistence/AzureSqlOnboardingReviewRepository.php');
        self::assertIsString($source);

        $sql = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
        self::assertIsString($sql);

        preg_match_all('#\[?onboarding_hr\]?\.\[?(\w+)\]?#i', $sql, $matches);
        self::assertSame(['FieldClassifications'], array_values(array_unique($matches[1])));

        foreach (['RestrictedSubmissionData', 'PayrollDetails', 'RestrictedPersonalDetails', 'EmergencyContacts'] as $table) {
            self::assertStringNotContainsStringIgnoringCase($table, $sql, $table);
        }
    }
}
