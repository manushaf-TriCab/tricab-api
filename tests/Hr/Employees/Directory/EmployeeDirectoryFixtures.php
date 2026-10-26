<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\Employees\Directory;

use TriCab\Api\Hr\Employees\Directory\InMemoryEmployeeDirectory;

/** A small payroll mirror: a reporting line, a leaver, a dangling manager and a manager cycle. */
final class EmployeeDirectoryFixtures
{
    public static function directory(): InMemoryEmployeeDirectory
    {
        return self::seed(new InMemoryEmployeeDirectory());
    }

    public static function seed(InMemoryEmployeeDirectory $directory): InMemoryEmployeeDirectory
    {
        // ceo <- ann <- bob, ben; cat reports to a login nobody has; dan and eve manage each other.
        $directory->withEmployee(self::employee('E001', 'ceo', 'Chris', 'Owen', 'OpsHRSaf', 'MELB', null, '2010-01-04'))
            ->withEmployee(self::employee('E002', 'ANN', 'Ann', 'Lee', 'OpsExtru', 'MELB', 'Ceo', '2015-03-02'), [
                'personalEmail' => 'ann@example.test', 'mobile' => '0400 000 001', 'street' => '1 Fake St',
                'suburb' => 'Faketon', 'state' => 'VIC', 'postcode' => '3000', 'dateOfBirth' => '1980-02-03',
                'annualLeaveRemaining' => 12.5, 'longServiceLeaveRemaining' => 3.5, 'sickLeaveRemaining' => 8.25,
                'leaveAsOf' => '2026-10-15',
            ])
            ->withEmployee(self::employee('E003', 'bob', 'Bob', 'Ray', 'OpsExtru', 'SYD', 'ann', '2020-06-01'))
            ->withEmployee(self::employee('E004', 'ben', 'Ben', 'Ray', 'DistCent', 'SYD', 'Ann', '2021-07-01'))
            ->withEmployee(self::employee('E005', 'cat', 'Cat', 'Kim', 'DistCent', 'MELB', 'ghost', '2022-01-10'))
            ->withEmployee(self::employee('E006', 'dan', 'Dan', 'Fox', 'OpsExtru', 'MELB', 'eve', '2018-01-01'))
            ->withEmployee(self::employee('E007', 'eve', 'Eve', 'Fox', 'OpsExtru', 'MELB', 'dan', '2018-02-01'))
            ->withEmployee(self::employee('E008', 'old', 'Olga', 'Dee', 'OpsExtru', 'MELB', 'ann', '2012-05-05', false));

        return $directory;
    }

    /** @return array<string, mixed> */
    public static function employee(
        string $code,
        string $login,
        string $first,
        string $last,
        string $department,
        string $factory,
        ?string $manager,
        string $hired,
        bool $current = true,
    ): array {
        return [
            'code' => $code,
            'login' => $login,
            'firstName' => $first,
            'lastName' => $last,
            'displayName' => $first . ' ' . $last,
            'department' => $department,
            'factory' => $factory,
            'workEmail' => $login . '@tricab.com',
            'managerLogin' => $manager,
            'dateHired' => $hired,
            'isCurrent' => $current,
            'lastSeenInPayroll' => $current ? '2026-10-15' : '2024-01-12',
            'missingFromSourceAt' => null,
            'linkedUser' => null,
        ];
    }
}
