<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;

/**
 * The source's validators (`store.py`: `_text`, `_valid_date`, `_bool`, `_progress`), with
 * its messages word for word, and its lists. Framework-free: every adapter and every
 * use case validates the same way.
 */
final class TaskRules
{
    public const STATUSES = ['in_progress', 'completed'];
    public const CATEGORIES = ['routine', 'ad_hoc'];
    public const PROJECT_STATUSES = ['planning', 'active', 'completed', 'on_hold'];
    public const STATEMENT_CATEGORIES = ['balance_sheet', 'income_statement', 'cash_flow', 'equity', 'notes', 'other'];
    public const MAX_BULK = 200;
    public const MAX_BULK_AREAS = 100;

    /** A new 32-character lower-case hex id (decision D16). */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Python's `str.strip()`: whitespace of any kind, including a no-break space, at both ends. */
    public static function strip(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value) ?? $value;
    }

    /** `key` as the source's messages say it: `due_date` is "Due date". */
    private static function label(string $key): string
    {
        $words = str_replace('_', ' ', $key);

        return mb_strtoupper(mb_substr($words, 0, 1)) . mb_strtolower(mb_substr($words, 1));
    }

    /**
     * `_text`: null when the key is absent and not required; the stripped text otherwise.
     *
     * @param array<string, mixed> $data
     */
    public static function text(array $data, string $key, ?int $limit = null, bool $required = false): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            if ($required) {
                throw new TaskInvalid(self::label($key) . ' is required');
            }

            return null;
        }
        if (!is_scalar($value)) {
            throw new TaskInvalid(self::label($key) . ' must be text');
        }
        $text = self::strip(is_bool($value) ? ($value ? 'True' : 'False') : (string) $value);
        if ($required && $text === '') {
            throw new TaskInvalid(self::label($key) . ' is required');
        }
        if ($limit !== null && mb_strlen($text) > $limit) {
            throw new TaskInvalid(self::label($key) . ' must be at most ' . $limit . ' characters');
        }

        return $text;
    }

    /** `_valid_date`: `yyyy-MM-dd` and a real calendar date, or null for none. */
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = self::strip(is_scalar($value) ? (string) $value : '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            throw new TaskInvalid('Dates must be yyyy-MM-dd');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $text) {
            throw new TaskInvalid('That is not a real date');
        }

        return $text;
    }

    /** `_bool`: a string is true when it says 1, true or yes. */
    public static function bool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(self::strip($value)), ['1', 'true', 'yes'], true);
        }

        return (bool) $value;
    }

    /** `_progress`: a number from 0 to 100, rounded to a whole number. */
    public static function progress(mixed $value): int
    {
        if (is_bool($value) || !(is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value))))) {
            throw new TaskInvalid('Progress must be a number from 0 to 100');
        }
        $number = (float) $value;
        if (is_nan($number) || is_infinite($number)) {
            throw new TaskInvalid('Progress must be a number from 0 to 100');
        }
        // Python's round() goes to the even number on a tie; PHP's rounds half away from zero.
        $rounded = (int) round($number, 0, PHP_ROUND_HALF_EVEN);
        if ($rounded < 0 || $rounded > 100) {
            throw new TaskInvalid('Progress must be a number from 0 to 100');
        }

        return $rounded;
    }

    /**
     * A person id from a payload: empty means none; a whole number or a numeric string is the
     * platform user id. Anything else cannot be a member.
     */
    public static function userId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false || $value === 0 || $value === '0') {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d{0,17}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw new TaskInvalid('The preparer and reviewer must be members of Tri-Task');
    }
}
