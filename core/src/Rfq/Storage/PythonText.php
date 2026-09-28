<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

use IntlChar;
use stdClass;

/**
 * The few pieces of Python's text behaviour the source store's change log is
 * built from, ported exactly, because they decide what counts as an edit and
 * what lands in `rfq_draft_row_edits.old_value` / `new_value`.
 *
 * The source's `_edit_text` is `format(float(str(v).strip()), 'g')` when that
 * parses and `str(v).strip()` otherwise, so that the browser rewriting "100"
 * as "100.0" is not a change. Each step has Python semantics this reproduces:
 * `str()` of bools, floats and containers; `strip()`'s Unicode whitespace;
 * `float()`'s grammar (underscores, `inf`, `nan`, any Unicode digit); and
 * `%g`'s six significant digits with `e+06` exponents.
 */
final class PythonText
{
    /** Python's `str.isspace()` set, which `strip()` removes. */
    private const WHITESPACE = "\\x{09}-\\x{0D}\\x{1C}-\\x{20}\\x{85}\\x{A0}\\x{1680}\\x{2000}-\\x{200A}\\x{2028}\\x{2029}\\x{202F}\\x{205F}\\x{3000}";

    /** The source's `_edit_text`. */
    public static function editText(mixed $value): string
    {
        $text = self::strip(self::str($value ?? ''));
        $number = self::parseFloat($text);

        return $number === null ? $text : self::formatG($number);
    }

    /** Python's `str(value)`. */
    public static function str(mixed $value): string
    {
        return match (true) {
            $value === null => 'None',
            $value === true => 'True',
            $value === false => 'False',
            is_int($value) => (string) $value,
            is_float($value) => PythonJson::floatRepr($value),
            is_string($value) => $value,
            $value instanceof PythonJsonInt => $value->digits,
            default => self::repr($value),
        };
    }

    public static function strip(string $text): string
    {
        return (string) preg_replace('/^[' . self::WHITESPACE . ']+|[' . self::WHITESPACE . ']+$/u', '', $text);
    }

    /** Python's `str.upper()`, which uses full case mapping (`ß` → `SS`). */
    public static function upper(string $text): string
    {
        return mb_strtoupper($text, 'UTF-8');
    }

    /**
     * Python's `float(text)` on already-stripped text, or null where Python
     * raises `ValueError`.
     */
    public static function parseFloat(string $text): ?float
    {
        if (preg_match('/^([+-]?)(inf|infinity|nan)$/i', $text, $m) === 1) {
            $special = strtolower($m[2]) === 'nan' ? NAN : INF;

            return $m[1] === '-' ? -$special : $special;
        }
        $digit = '\p{Nd}';
        $run = "{$digit}(?:_?{$digit})*";
        $pattern = "/^[+-]?(?:{$run}(?:\\.(?:{$run})?)?|\\.{$run})(?:[eE][+-]?{$run})?$/u";
        if (preg_match($pattern, $text) !== 1) {
            return null;
        }
        $ascii = str_replace('_', '', self::asciiDigits($text));

        return (float) $ascii;
    }

    /**
     * Python's `format(number, 'g')`: six significant digits, trailing zeros
     * dropped, scientific below 1e-4 and from 1e6, exponent at least two digits.
     */
    public static function formatG(float $number): string
    {
        if (is_nan($number)) {
            return 'nan';
        }
        if (is_infinite($number)) {
            return $number < 0 ? '-inf' : 'inf';
        }
        if ($number == 0.0) {
            return fdiv(1.0, $number) < 0 ? '-0' : '0';
        }
        // %.5e rounds to six significant digits correctly; its exponent is the
        // one %g decides with.
        $scientific = sprintf('%.5e', $number);
        [$mantissa, $exponent] = explode('e', $scientific);
        $exponent = (int) $exponent;
        if ($exponent < -4 || $exponent >= 6) {
            $mantissa = rtrim(rtrim($mantissa, '0'), '.');

            return $mantissa . 'e' . ($exponent < 0 ? '-' : '+') . str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
        }
        $fixed = sprintf('%.' . (5 - $exponent) . 'F', $number);

        return str_contains($fixed, '.') ? rtrim(rtrim($fixed, '0'), '.') : $fixed;
    }

    /** Python's `repr()` of a list or dict, as `str()` prints containers. */
    private static function repr(mixed $value): string
    {
        if (is_string($value)) {
            $quote = str_contains($value, "'") && !str_contains($value, '"') ? '"' : "'";
            $escaped = addcslashes($value, '\\' . $quote);
            $escaped = strtr($escaped, ["\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);

            return $quote . $escaped . $quote;
        }
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            if ($value === []) {
                return '{}';
            }
        }
        if (is_array($value)) {
            if ($value !== [] && !array_is_list($value)) {
                $parts = [];
                foreach ($value as $key => $item) {
                    $parts[] = self::repr((string) $key) . ': ' . self::repr($item);
                }

                return '{' . implode(', ', $parts) . '}';
            }

            return '[' . implode(', ', array_map(self::repr(...), $value)) . ']';
        }

        return self::str($value);
    }

    private static function asciiDigits(string $text): string
    {
        if (preg_match('/[^\x00-\x7F]/', $text) !== 1) {
            return $text;
        }

        return (string) preg_replace_callback('/\p{Nd}/u', static function (array $m): string {
            if (class_exists(IntlChar::class)) {
                return (string) IntlChar::charDigitValue(mb_ord($m[0], 'UTF-8'));
            }

            return $m[0];
        }, $text);
    }
}
