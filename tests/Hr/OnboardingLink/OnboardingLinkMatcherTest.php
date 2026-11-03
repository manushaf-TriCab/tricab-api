<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\OnboardingLink;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Hr\OnboardingLink\OnboardingLinkMatcher;

final class OnboardingLinkMatcherTest extends TestCase
{
    /**
     * @return array{profileId: int, userId: int, displayName: string, startDate: ?string}
     */
    private static function starter(int $id, string $name, ?string $start, int $userId = 100): array
    {
        return ['profileId' => $id, 'userId' => $userId + $id, 'displayName' => $name, 'startDate' => $start];
    }

    /**
     * @return array{code: string, userId: ?int, displayName: ?string, firstName: ?string, lastName: ?string, dateHired: ?string}
     */
    private static function employee(string $code, ?string $first, ?string $last, ?string $hired, ?int $userId = null, ?string $display = null): array
    {
        return ['code' => $code, 'userId' => $userId, 'displayName' => $display ?? trim($first . ' ' . $last), 'firstName' => $first, 'lastName' => $last, 'dateHired' => $hired];
    }

    public function test_the_login_link_wins_and_is_reported_as_login(): void
    {
        $result = OnboardingLinkMatcher::match(
            [self::starter(1, 'Zed Q', null)],
            [self::employee('E1', 'Someone', 'Else', null, 101)],
        );

        self::assertSame([['profileId' => 1, 'employeeCode' => 'E1', 'reason' => 'login']], $result);
    }

    public function test_a_login_match_stops_the_name_rule_for_that_starter(): void
    {
        $result = OnboardingLinkMatcher::match(
            [self::starter(1, 'Ann Lee', '2026-11-02')],
            [self::employee('E1', 'X', 'Y', null, 101), self::employee('E2', 'Ann', 'Lee', '2026-11-02')],
        );

        self::assertSame(['E1'], array_column($result, 'employeeCode'));
    }

    public function test_name_and_a_start_date_inside_the_window_match(): void
    {
        $result = OnboardingLinkMatcher::match(
            [self::starter(1, 'ann  LEE', '2026-11-02')],
            [self::employee('E1', 'Ann', 'Lee', '2026-11-09'), self::employee('E2', 'Ann', 'Lee', '2026-11-10')],
        );

        self::assertSame([['profileId' => 1, 'employeeCode' => 'E1', 'reason' => 'name_start_date']], $result);
    }

    public function test_a_name_alone_never_matches(): void
    {
        self::assertSame([], OnboardingLinkMatcher::match([self::starter(1, 'Ann Lee', null)], [self::employee('E1', 'Ann', 'Lee', '2026-11-02')]));
        self::assertSame([], OnboardingLinkMatcher::match([self::starter(1, 'Ann Lee', '2026-11-02')], [self::employee('E1', 'Ann', 'Lee', null)]));
        self::assertSame([], OnboardingLinkMatcher::match([self::starter(1, 'Ann Lee', '2026-11-02')], [self::employee('E1', 'Ann', 'Lee', '2024-01-01')]));
    }

    public function test_an_employee_tied_to_another_platform_user_is_not_offered_by_name(): void
    {
        $result = OnboardingLinkMatcher::match(
            [self::starter(1, 'Ann Lee', '2026-11-02')],
            [self::employee('E1', 'Ann', 'Lee', '2026-11-02', 999)],
        );

        self::assertSame([], $result);
    }

    public function test_punctuation_and_case_are_ignored_and_first_plus_last_name_counts(): void
    {
        $result = OnboardingLinkMatcher::match(
            [self::starter(1, "Ann-Marie O'Lee", '2026-11-02')],
            [self::employee('E1', 'Ann Marie', 'O Lee', '2026-11-02', null, 'LEE, A.M.')],
        );

        self::assertCount(1, $result);
        self::assertSame('', OnboardingLinkMatcher::normalizeName('  - '));
    }

    public function test_two_people_with_the_same_name_both_come_back_for_hr_to_choose(): void
    {
        $result = OnboardingLinkMatcher::match(
            [self::starter(1, 'Ann Lee', '2026-11-02')],
            [self::employee('E1', 'Ann', 'Lee', '2026-11-02'), self::employee('E2', 'Ann', 'Lee', '2026-11-04')],
        );

        self::assertSame(['E1', 'E2'], array_column($result, 'employeeCode'));
    }
}
