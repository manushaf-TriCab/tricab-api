<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Domain\Credit;

/**
 * bcmath helpers for credit arithmetic. Strings in, strings out, no floats.
 */
final class CustomerContactReportDecimal
{
    /** Round half up (away from zero) to $scale places. */
    public static function round(string $value, int $scale): string
    {
        $half = '0.' . str_repeat('0', $scale) . '5';

        return bccomp($value, '0', 12) >= 0
            ? bcadd($value, $half, $scale)
            : bcsub($value, $half, $scale);
    }

    public static function money(string $value): string
    {
        return self::round($value, 2);
    }

    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, 6) <= 0 ? $a : $b;
    }

    public static function isNumeric(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        return is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value)) === 1;
    }

    public static function normalise(mixed $value): string
    {
        $text = is_string($value) ? trim($value) : (string) $value;

        return str_replace(',', '', $text);
    }
}
