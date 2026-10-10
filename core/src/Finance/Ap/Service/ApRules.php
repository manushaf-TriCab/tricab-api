<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

/**
 * The source's small validators and Python habits, in one place so every use case reads input the
 * same way. Framework-free. Later Tri-AP tasks add to it.
 */
final class ApRules
{
    /** A new 32-character lower-case hex id (decision D71). */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** Python's `str.strip()`: whitespace of any kind, including a no-break space, at both ends. */
    public static function strip(string $value): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $value) ?? $value;
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
}
