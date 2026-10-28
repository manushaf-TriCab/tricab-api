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

        return self::cmp($value, '0', 12) >= 0
            ? self::add($value, $half, $scale)
            : self::sub($value, $half, $scale);
    }

    public static function money(string $value): string
    {
        return self::round($value, 2);
    }

    public static function min(string $a, string $b): string
    {
        return self::cmp($a, $b, 6) <= 0 ? $a : $b;
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
        $text = is_string($value) ? trim($value) : (is_scalar($value) ? (string) $value : '');

        return str_replace(',', '', $text);
    }

    public static function add(string $a, string $b, int $scale): string
    {
        return bcadd(self::num($a), self::num($b), $scale);
    }

    public static function sub(string $a, string $b, int $scale): string
    {
        return bcsub(self::num($a), self::num($b), $scale);
    }

    public static function mul(string $a, string $b, int $scale): string
    {
        return bcmul(self::num($a), self::num($b), $scale);
    }

    public static function cmp(string $a, string $b, int $scale): int
    {
        return bccomp(self::num($a), self::num($b), $scale);
    }

    /**
     * Same acceptance as bcmath (it throws ValueError on anything else).
     *
     * @return numeric-string
     */
    private static function num(string $value): string
    {
        if (!is_numeric($value) || preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value) !== 1) {
            throw new \ValueError('Value is not a well-formed numeric string: ' . $value);
        }

        return $value;
    }
}
