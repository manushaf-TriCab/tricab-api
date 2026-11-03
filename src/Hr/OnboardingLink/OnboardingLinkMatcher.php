<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\OnboardingLink;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Which payroll employee might an onboarding starter be (HR Employees plan 5.2).
 *
 * Two rules, tried in order for each starter:
 *
 *  1. **Login.** The sync already links an employee to a platform user by login.
 *     If the employee's platform user is this starter's user, that is the match,
 *     and no name rule is tried for the starter.
 *  2. **Name and start date.** The same name (case, spacing and punctuation
 *     ignored) AND an onboarding start date within
 *     {@see self::START_DATE_TOLERANCE_DAYS} days of the payroll hire date. Both
 *     dates must exist. An employee already tied to a different platform user is
 *     not offered.
 *
 * A name alone is never enough, and a result is only ever a proposal: HR
 * confirms it. Pure; the store supplies the candidates.
 */
final class OnboardingLinkMatcher
{
    public const REASON_LOGIN = 'login';
    public const REASON_NAME_START_DATE = 'name_start_date';

    public const START_DATE_TOLERANCE_DAYS = 7;

    /**
     * @param list<array{profileId: int, userId: int, displayName: string, startDate: ?string}> $starters
     * @param list<array{code: string, userId: ?int, displayName: ?string, firstName: ?string, lastName: ?string, dateHired: ?string}> $employees
     *
     * @return list<array{profileId: int, employeeCode: string, reason: string}>
     */
    public static function match(array $starters, array $employees): array
    {
        $proposals = [];
        foreach ($starters as $starter) {
            $byLogin = array_values(array_filter(
                $employees,
                static fn (array $employee): bool => $employee['userId'] !== null && $employee['userId'] === $starter['userId'],
            ));
            if ($byLogin !== []) {
                foreach ($byLogin as $employee) {
                    $proposals[] = ['profileId' => $starter['profileId'], 'employeeCode' => $employee['code'], 'reason' => self::REASON_LOGIN];
                }
                continue;
            }

            $name = self::normalizeName($starter['displayName']);
            $start = self::date($starter['startDate']);
            if ($name === '' || $start === null) {
                continue;
            }
            foreach ($employees as $employee) {
                if ($employee['userId'] !== null) {
                    continue;
                }
                $hired = self::date($employee['dateHired']);
                if ($hired === null || !in_array($name, self::employeeNames($employee), true)) {
                    continue;
                }
                $days = abs((int) $start->diff($hired)->format('%r%a'));
                if ($days <= self::START_DATE_TOLERANCE_DAYS) {
                    $proposals[] = ['profileId' => $starter['profileId'], 'employeeCode' => $employee['code'], 'reason' => self::REASON_NAME_START_DATE];
                }
            }
        }

        return $proposals;
    }

    public static function normalizeName(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));
        $name = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);

        return trim($name);
    }

    /**
     * @param array{displayName: ?string, firstName: ?string, lastName: ?string} $employee
     *
     * @return list<string>
     */
    private static function employeeNames(array $employee): array
    {
        $names = [
            self::normalizeName($employee['displayName']),
            self::normalizeName(trim((string) $employee['firstName'] . ' ' . (string) $employee['lastName'])),
        ];

        return array_values(array_filter(array_unique($names), static fn (string $n): bool => $n !== ''));
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($value), 0, 10), new DateTimeZone('UTC'));

        return $date === false ? null : $date;
    }
}
