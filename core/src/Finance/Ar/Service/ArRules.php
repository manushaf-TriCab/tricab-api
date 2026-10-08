<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Ar\Domain\ArInvalid;

/**
 * The source's small validators and Python habits, in one place so every adapter and use case
 * reads input the same way. Framework-free.
 */
final class ArRules
{
    /** A new 32-character lower-case hex id (decision D40). */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Python's `str.strip()`: whitespace of any kind, including a no-break space, at both ends. */
    public static function strip(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value) ?? $value;
    }

    /** Python's `repr()` of a string, which is how the source's messages quote a label or a value. */
    public static function repr(string $value): string
    {
        if (str_contains($value, "'") && !str_contains($value, '"')) {
            return '"' . str_replace('\\', '\\\\', $value) . '"';
        }

        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /** Python's truthiness of a JSON value: null, false, 0, "" and empty lists are false. */
    public static function truthy(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }
        if (is_string($value)) {
            return $value !== '';
        }

        return (bool) $value;
    }

    /** `(value or "").strip()` for a JSON value that should be text; anything that is not a scalar reads as empty. */
    public static function textOrEmpty(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return self::strip(is_bool($value) ? ($value ? 'True' : 'False') : (string) $value);
    }

    /** `?name=1` or `?name=true`, as the source read `deleted`, `permanent` and now `search_body`. */
    public static function flag(mixed $value): bool
    {
        return $value === '1' || $value === 'true' || $value === true || $value === 1;
    }

    /**
     * A `yyyy-MM-dd` date from the first ten characters of the text (the source's
     * `date.fromisoformat(value[:10])`), or null when the text is empty.
     *
     * @throws ArInvalid with `$message` when it is not a real date
     */
    public static function dateOrNull(mixed $value, string $message): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = self::strip(is_scalar($value) ? (string) $value : '');
        if ($text === '') {
            return null;
        }
        $day = substr($text, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            throw new ArInvalid($message);
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
            throw new ArInvalid($message);
        }

        return $day;
    }
}
