<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Access;

use JsonException;

/**
 * Reads an export of the finance portal's `platform_users`, as JSON (an array of
 * objects, or `{"users": [...]}`) or CSV (a header row). Columns: `id`, `email`,
 * `full_name` (or `name`) and, optionally, `is_active` (absent means active). Rows
 * that cannot be used are returned with the reason, not dropped silently.
 */
final class FinancePortalUserExport
{
    private function __construct()
    {
    }

    /**
     * @return array{users: list<FinancePortalUser>, rejected: list<array{row: int, reason: string}>}
     */
    public static function parse(string $contents, string $format): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $rows = $format === 'csv' ? self::csvRows($contents) : self::jsonRows($contents);

        $users = [];
        $rejected = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $id = self::text($row['id'] ?? null);
            $email = strtolower(self::text($row['email'] ?? null));
            if ($id === '' || preg_match('/^[A-Za-z0-9-]{1,32}$/', $id) !== 1) {
                $rejected[] = ['row' => $number, 'reason' => 'The id is missing or is not a portal user id.'];
                continue;
            }
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $rejected[] = ['row' => $number, 'reason' => 'The email is missing or not an address.'];
                continue;
            }
            if (isset($seen[$id])) {
                $rejected[] = ['row' => $number, 'reason' => 'The id appears more than once in the export.'];
                continue;
            }
            $seen[$id] = true;
            $users[] = new FinancePortalUser($id, $email, self::text($row['full_name'] ?? $row['name'] ?? null), self::flag($row['is_active'] ?? null));
        }

        return ['users' => $users, 'rejected' => $rejected];
    }

    /** @return list<array<string, mixed>> */
    private static function jsonRows(string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (is_array($decoded) && isset($decoded['users']) && is_array($decoded['users'])) {
            $decoded = $decoded['users'];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /** @return list<array<string, mixed>> */
    private static function csvRows(string $contents): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($contents)) ?: [];
        $header = null;
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            if ($header === null) {
                $header = array_map(static fn ($cell): string => strtolower(trim((string) $cell)), $cells);
                continue;
            }
            $row = [];
            foreach ($header as $position => $name) {
                $row[$name] = $cells[$position] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function flag(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) (is_scalar($value) ? $value : '1'))), ['0', 'false', 'no', 'n', 'f'], true);
    }
}
