<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Hr\Employees;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Hr\Employees\EmployeeMapper;

final class EmployeeMapperTest extends TestCase
{
    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    public static function row(array $override = []): array
    {
        return $override + [
            'employee_code' => 'E100', 'user_login' => 'ELOISED', 'first_name' => ' Eloise ', 'last_name' => 'D', 'display_name' => '',
            'department' => 'OpsHRSaf', 'factory' => ' TRADING', 'email' => 'eloise@gmail.com',
            'al_remain' => '12.5', 'lsl_remain' => 0.0, 'sl_remain' => null, 'date_hired' => '2020-02-03', 'dob' => '1990-01-02',
            'street' => '1 High St', 'suburb' => 'Clayton', 'state' => 'VIC', 'postcode' => 3168, 'mobile' => '0400 000 000',
            'update_date' => '2026-10-15', 'manager' => 'diemn',
        ];
    }

    public function testTrimsAndTurnsEmptyIntoNull(): void
    {
        $record = EmployeeMapper::map(self::row());

        self::assertNotNull($record);
        self::assertSame('Eloise', $record->base['FirstName']);
        self::assertNull($record->base['DisplayName']);
        self::assertSame('TRADING', $record->base['FactoryRaw']);
        self::assertSame('ELOISED', $record->base['UserLogin']);
        self::assertSame('eloised', $record->normalizedUserLogin);
        self::assertSame('3168', $record->restricted['Postcode']);
        self::assertSame('12.50', $record->restricted['AnnualLeaveRemaining']);
        self::assertSame('0.00', $record->restricted['LongServiceLeaveRemaining']);
        self::assertNull($record->restricted['SickLeaveRemaining']);
        self::assertSame('2026-10-15', $record->restricted['LeaveAsOf']);
    }

    public function testNoEmployeeCodeMeansNoRecord(): void
    {
        self::assertNull(EmployeeMapper::map(self::row(['employee_code' => '  '])));
    }

    public function testWorkEmailStaysOnTheBaseRowAndPersonalGoesToRestricted(): void
    {
        $work = EmployeeMapper::map(self::row(['email' => ' Eloise.D@TriCab.com ']));
        self::assertNotNull($work);
        self::assertSame('Eloise.D@TriCab.com', $work->base['WorkEmail']);
        self::assertNull($work->restricted['PersonalEmail']);

        $personal = EmployeeMapper::map(self::row(['email' => 'eloise@gmail.com']));
        self::assertNotNull($personal);
        self::assertNull($personal->base['WorkEmail']);
        self::assertSame('eloise@gmail.com', $personal->restricted['PersonalEmail']);

        $lookalike = EmployeeMapper::map(self::row(['email' => 'x@tricab.com.evil.net']));
        self::assertNotNull($lookalike);
        self::assertNull($lookalike->base['WorkEmail']);
    }

    public function testRestrictedValuesNeverReachTheBaseRow(): void
    {
        $record = EmployeeMapper::map(self::row());
        self::assertNotNull($record);

        $base = json_encode($record->base, JSON_THROW_ON_ERROR);
        foreach (['eloise@gmail.com', '0400 000 000', '1 High St', 'Clayton', '3168', '1990-01-02', '12.5'] as $secret) {
            self::assertStringNotContainsString($secret, $base);
        }
        self::assertSame(['UserLogin', 'FirstName', 'LastName', 'DisplayName', 'DepartmentRaw', 'FactoryRaw', 'WorkEmail', 'ManagerLogin', 'DateHired', 'LegacyUpdateDate'], array_keys($record->base));
    }

    public function testZeroDatesAreNull(): void
    {
        $record = EmployeeMapper::map(self::row(['dob' => '0000-00-00', 'date_hired' => '', 'update_date' => '2026-02-30']));
        self::assertNotNull($record);
        self::assertNull($record->restricted['DateOfBirth']);
        self::assertNull($record->base['DateHired']);
        self::assertNull($record->legacyUpdateDate);
    }

    public function testHashFollowsTheWholeSourceRow(): void
    {
        $a = EmployeeMapper::map(self::row());
        $same = EmployeeMapper::map(self::row(['al_remain' => 12.5, 'postcode' => '3168']));
        $changed = EmployeeMapper::map(self::row(['mobile' => '0411 111 111']));
        self::assertNotNull($a);
        self::assertNotNull($same);
        self::assertNotNull($changed);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->sourceHash);
        self::assertSame($a->sourceHash, $same->sourceHash, 'the same values spelt as MySQL or PHP types hash alike');
        self::assertNotSame($a->sourceHash, $changed->sourceHash);
    }
}
