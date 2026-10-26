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
    /**
     * The stored statuses: Active, On Hold and Done (Nick Han, 13 Oct 2026). Overdue is still worked out from the due
     * date, for Active tasks only. The review step is a Send for review button, not a status.
     */
    public const STATUSES = ['active', 'on_hold', 'completed'];
    public const STATUS_LABELS = [
        'active' => 'Active',
        'on_hold' => 'On Hold',
        'completed' => 'Done',
    ];
    /**
     * The board's statuses of the morning of 13 Oct 2026, all now Active: a row not yet moved by
     * alter-finance-task-simple-status.sql, or a page loaded before the change, reads and sends them.
     */
    public const LEGACY_STATUSES = ['not_started', 'in_progress', 'stuck', 'ready_for_review'];
    /** A new task starts here unless it says otherwise. */
    public const DEFAULT_STATUS = 'active';
    /** Moving into one of these is refused while a dependency is unfinished. */
    public const BLOCKED_STATUSES = ['completed'];
    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    /** Estimated Time is free text ("2 hours", "half a day"), stored as `time_spent`; 200 characters is the column. */
    public const TIME_SPENT_LIMIT = 200;
    public const CATEGORIES = ['routine', 'ad_hoc'];
    /** Where a task given no project goes (item 3, 13 Oct 2026). */
    public const GENERAL_PROJECT = 'General';
    /** "Planning" went on 13 Oct 2026 (Nick Han): a project is Active from the start. */
    public const PROJECT_STATUSES = ['active', 'completed', 'on_hold'];
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
        // `time_spent` holds the estimate: the screens call it Estimated Time (Nick Han, 26 Oct 2026).
        if ($key === 'time_spent') {
            return 'Estimated time';
        }
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

    /** The most preparers (or reviewers) one task can have: a guard against a runaway payload, far above any real team. */
    public const MAX_ASSIGNEES = 20;

    /**
     * The people of one role from a payload (Nick Han, 23 Oct 2026: "multiple Preparers and Reviewers"). The list key
     * (`preparer_ids`) wins when it is sent; otherwise the single key (`preparer_id`, what clients before this release
     * send) names one person, or none when empty. Neither sent returns null: the role is left as it is. Duplicates and
     * empty entries drop out; the order is kept (the first person is the one older clients see).
     *
     * @param array<string, mixed> $data
     * @return ?list<int>
     */
    public static function people(array $data, string $listKey, string $singleKey): ?array
    {
        if (array_key_exists($listKey, $data)) {
            $raw = $data[$listKey];
            if ($raw === null || $raw === '' || $raw === false) {
                return [];
            }
            if (!is_array($raw) || !array_is_list($raw)) {
                throw new TaskInvalid('The preparers and reviewers must be lists of Tri-Task members');
            }
            $out = [];
            foreach ($raw as $item) {
                $id = self::userId($item);
                if ($id !== null && !in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }
            if (count($out) > self::MAX_ASSIGNEES) {
                throw new TaskInvalid('A task can have at most ' . self::MAX_ASSIGNEES . ' preparers and ' . self::MAX_ASSIGNEES . ' reviewers');
            }

            return $out;
        }
        if (array_key_exists($singleKey, $data)) {
            $id = self::userId($data[$singleKey]);

            return $id === null ? [] : [$id];
        }

        return null;
    }

    /** A stored status as it is now: a legacy one is Active. */
    public static function status(string $stored): string
    {
        return in_array($stored, self::LEGACY_STATUSES, true) ? 'active' : $stored;
    }
}
