<?php

declare(strict_types=1);

namespace TriCab\Api\Hr\Employees;

/**
 * `tc.employee` row to platform record. Strings are trimmed and empty means
 * NULL. Only an @tricab.com address is a work email; any other address is
 * personal and goes to the restricted table with the rest of the sensitive
 * columns, never to the base row.
 */
final class EmployeeMapper
{
    /** The columns read from tc.employee, in hash order (`my_row_id` is a surrogate and is not read). */
    public const COLUMNS = [
        'employee_code', 'user_login', 'first_name', 'last_name', 'display_name', 'department', 'factory', 'email',
        'al_remain', 'lsl_remain', 'sl_remain', 'date_hired', 'dob', 'street', 'suburb', 'state', 'postcode',
        'mobile', 'update_date', 'manager',
    ];

    /** Bump when the mapping changes, so every row is written once under the new rules. */
    private const HASH_VERSION = 'hr-employee-v1';

    private const WORK_DOMAIN = '@tricab.com';

    /** @param array<string, mixed> $row */
    public static function map(array $row): ?EmployeeRecord
    {
        $code = self::text($row['employee_code'] ?? null);
        if ($code === null) {
            return null;
        }

        $login = self::text($row['user_login'] ?? null);
        $email = self::text($row['email'] ?? null);
        $isWork = $email !== null && str_ends_with(strtolower($email), self::WORK_DOMAIN);
        $updateDate = self::date($row['update_date'] ?? null);

        return new EmployeeRecord(
            $code,
            $login === null ? null : strtolower($login),
            $updateDate,
            self::hash($row),
            [
                'UserLogin' => $login,
                'FirstName' => self::text($row['first_name'] ?? null),
                'LastName' => self::text($row['last_name'] ?? null),
                'DisplayName' => self::text($row['display_name'] ?? null),
                'DepartmentRaw' => self::text($row['department'] ?? null),
                'FactoryRaw' => self::text($row['factory'] ?? null),
                'WorkEmail' => $isWork ? $email : null,
                'ManagerLogin' => self::text($row['manager'] ?? null),
                'DateHired' => self::date($row['date_hired'] ?? null),
                'LegacyUpdateDate' => $updateDate,
            ],
            [
                'PersonalEmail' => $isWork ? null : $email,
                'Mobile' => self::text($row['mobile'] ?? null),
                'Street' => self::text($row['street'] ?? null),
                'Suburb' => self::text($row['suburb'] ?? null),
                'State' => self::text($row['state'] ?? null),
                'Postcode' => self::text($row['postcode'] ?? null),
                'DateOfBirth' => self::date($row['dob'] ?? null),
                'AnnualLeaveRemaining' => self::leave($row['al_remain'] ?? null),
                'LongServiceLeaveRemaining' => self::leave($row['lsl_remain'] ?? null),
                'SickLeaveRemaining' => self::leave($row['sl_remain'] ?? null),
                'LeaveAsOf' => $updateDate,
            ],
        );
    }

    /** Trimmed text; empty is NULL. */
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('A column value that is not a scalar: ' . get_debug_type($value));
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** A `Y-m-d` date; NULL for empty, malformed or zero dates (MySQL's 0000-00-00). */
    public static function date(mixed $value): ?string
    {
        $text = self::text($value);
        if ($text === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    /** Leave hours as a two-decimal string for DECIMAL(9,2). */
    private static function leave(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === null || !is_numeric($text) ? null : number_format((float) $text, 2, '.', '');
    }

    /**
     * SHA-256 of the whole source row: every column in a fixed order, raw
     * (a change of spacing counts), floats by value.
     *
     * @param array<string, mixed> $row
     */
    public static function hash(array $row): string
    {
        $values = [self::HASH_VERSION];
        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? null;
            if ($value !== null && !is_scalar($value)) {
                throw new \UnexpectedValueException('A column value that is not a scalar: ' . get_debug_type($value));
            }
            $values[] = match (true) {
                $value === null => null,
                in_array($column, ['al_remain', 'lsl_remain', 'sl_remain'], true) && is_numeric($value) => sprintf('%.17g', (float) $value),
                $column === 'postcode' && is_numeric($value) => (string) (int) $value,
                default => (string) $value,
            };
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
