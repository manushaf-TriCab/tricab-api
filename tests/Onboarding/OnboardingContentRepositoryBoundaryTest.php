<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Onboarding;

use PHPUnit\Framework\TestCase;

/**
 * Two lines 4.4 draws, read from the source because the failure each guards
 * against is a later edit that no behavioural test of today's code would see.
 *
 *  1. The content repository touches one restricted table,
 *     `[onboarding_hr].[FieldClassifications]` — a form's classifications are
 *     part of what its author writes — and never anybody's answers.
 *  2. The authored projection, which carries the answer key, is reachable only
 *     from the content routes. No trainee-facing action or repository names it,
 *     so none can hand it to a trainee by mistake.
 */
final class OnboardingContentRepositoryBoundaryTest extends TestCase
{
    public function test_the_content_repository_reads_only_field_classifications_from_restricted_hr(): void
    {
        $sql = self::withoutComments(dirname(__DIR__, 2) . '/src/Onboarding/Persistence/AzureSqlOnboardingContentRepository.php');

        preg_match_all('#\[?onboarding_hr\]?\.\[?(\w+)\]?#i', $sql, $matches);
        self::assertSame(['FieldClassifications'], array_values(array_unique($matches[1])));

        foreach (['RestrictedSubmissionData', 'PayrollDetails', 'RestrictedPersonalDetails', 'EmergencyContacts', 'FormSubmissions', 'FormDrafts', 'AttemptAnswers'] as $table) {
            self::assertStringNotContainsStringIgnoringCase($table, $sql, $table);
        }
    }

    public function test_the_authored_projection_is_named_only_by_the_content_routes(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/src/Http/Action/Onboarding/*.php') ?: [],
            glob($root . '/src/Onboarding/Persistence/*.php') ?: [],
            glob($root . '/core/src/Onboarding/Application/Service/*.php') ?: [],
        );
        self::assertNotEmpty($files);

        $allowed = [
            'OnboardingContentRequest.php',
            'GetOnboardingContentAction.php',
            'GetOnboardingContentItemAction.php',
            'GetOnboardingContentVersionAction.php',
            'PostOnboardingContentItemAction.php',
            'PutOnboardingContentItemAction.php',
            'DeleteOnboardingContentItemAction.php',
            'PostOnboardingContentDraftAction.php',
            'PutOnboardingContentVersionAction.php',
            'PostOnboardingContentPublishAction.php',
            'AzureSqlOnboardingContentRepository.php',
            'AuthorOnboardingContentService.php',
            'ReadOnboardingContentService.php',
        ];

        foreach ($files as $file) {
            if (in_array(basename($file), $allowed, true)) {
                continue;
            }
            $code = self::withoutComments($file);
            foreach (['AuthoredContent', 'ContentVersionRecord', 'ReadOnboardingContentService', 'AuthorOnboardingContentService'] as $name) {
                self::assertStringNotContainsString($name, $code, basename($file) . ' names ' . $name);
            }
        }
    }

    private static function withoutComments(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source);
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
        self::assertIsString($code);

        return $code;
    }
}
