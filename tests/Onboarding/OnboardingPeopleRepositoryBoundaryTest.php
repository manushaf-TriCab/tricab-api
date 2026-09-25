<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;

/**
 * Invariant 11, for HR's list views: the roster and allocation repository reads
 * `[onboarding]` and `[identity].[Users]`, and never `[onboarding_hr]`.
 *
 * Read from the source rather than exercised, because the failure it guards
 * against is a join somebody adds later "just to show the bank on the roster",
 * which no behavioural test of today's queries would notice.
 */
final class OnboardingPeopleRepositoryBoundaryTest extends TestCase
{
    public function test_the_people_repository_never_reads_restricted_hr_data(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Onboarding/Persistence/AzureSqlOnboardingPeopleRepository.php');
        self::assertIsString($source);

        $sql = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
        self::assertIsString($sql);

        self::assertStringNotContainsStringIgnoringCase('onboarding_hr', $sql);
    }
}
