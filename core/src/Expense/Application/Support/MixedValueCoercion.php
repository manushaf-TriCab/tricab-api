<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Support;

/**
 * Narrow mixed legacy/view values before string/int/float use.
 */
final class MixedValueCoercion
{
    public static function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    public static function stringValue(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        return trim((string) $value);
    }

    public static function floatValue(mixed $value): float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    public static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = self::stringValue($value);

        return $normalized === '' ? null : $normalized;
    }

    public static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
