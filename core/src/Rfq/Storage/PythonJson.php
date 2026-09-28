<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Storage;

use JsonException;
use stdClass;

/**
 * JSON exactly as the source RFQ store writes it: Python's `json.dumps` with
 * its defaults.
 *
 * The RFQ tables hold the source's JSON as it is (architecture doc §6.5), and
 * the platform writes into the same columns. Writing Python's format keeps old
 * and new rows byte-comparable, so a migrated line and a re-saved one hash the
 * same, and the parity fixture can check the port byte for byte.
 *
 *  - `", "` and `": "` separators; `ensure_ascii`: anything outside printable
 *    ASCII as `\uXXXX`, surrogate pairs above the BMP; `/` is not escaped.
 *  - Floats as Python's `repr`: shortest round-trip digits, `1e+16`, `1e-07`,
 *    `100.0`. `NaN`, `Infinity` and `-Infinity` as bare tokens.
 *
 * **Decoding accepts those three tokens.** Python writes them and reads them
 * back; PHP's `json_decode` refuses them, and 18 live row snapshots carry a
 * `NaN` (a failed score). They decode to PHP's `NAN`/`INF` and encode back
 * as the same tokens.
 *
 * Shape is kept: an empty JSON object decodes to a `stdClass` so that it
 * encodes back as `{}` rather than `[]`, and an integer too large for PHP
 * decodes to a {@see PythonJsonInt} that prints its digits unchanged. Known
 * limit: an object whose keys are exactly "0", "1", … in order reads as a
 * list, as it does anywhere in PHP.
 */
final class PythonJson
{
    /** What a protected token decodes to… */
    private const TOKEN = "\u{0}py:";

    /** …and how it is written into the JSON text, escaped as JSON requires. */
    private const TOKEN_JSON = '\\u0000py:';

    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::floatRepr($value, json: true);
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if ($value instanceof PythonJsonInt) {
            return $value->digits;
        }
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            if ($value === []) {
                return '{}';
            }
        }
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }
            if (array_is_list($value)) {
                return '[' . implode(', ', array_map(self::encode(...), $value)) . ']';
            }
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = self::string((string) $key) . ': ' . self::encode($item);
            }

            return '{' . implode(', ', $parts) . '}';
        }

        throw new \InvalidArgumentException('PythonJson cannot encode ' . get_debug_type($value) . '.');
    }

    /**
     * @throws JsonException on text that is not JSON even with Python's tokens
     */
    public static function decode(string $json): mixed
    {
        $decoded = json_decode(self::protectTokens($json), false, 512, JSON_THROW_ON_ERROR);

        return self::restore($decoded);
    }

    /**
     * The source's `_json_object`: an object, or `[]` for anything else,
     * including text that does not parse.
     *
     * @return array<string, mixed>
     */
    public static function decodeObject(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        try {
            $value = self::decode($json);
        } catch (JsonException) {
            return [];
        }
        if ($value instanceof stdClass) {
            return [];
        }

        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [];
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Python's `repr(float)`, which is also what `str(float)` gives. JSON
     * spells the non-finite values `NaN`/`Infinity`; `repr` spells them
     * `nan`/`inf`.
     */
    public static function floatRepr(float $value, bool $json = false): string
    {
        if (is_nan($value)) {
            return $json ? 'NaN' : 'nan';
        }
        if (is_infinite($value)) {
            return ($value < 0 ? '-' : '') . ($json ? 'Infinity' : 'inf');
        }
        if ($value == 0.0) {
            return fdiv(1.0, $value) < 0 ? '-0.0' : '0.0';
        }

        // Shortest round-trip digits: PHP's json_encode uses the same
        // algorithm (serialize_precision -1) and a parseable layout.
        $shortest = self::shortestDigits($value);
        [$negative, $digits, $exponent] = $shortest;
        $sign = $negative ? '-' : '';
        $length = strlen($digits);
        // Python: scientific when the decimal exponent is < -4 or >= 16.
        if ($exponent < -4 || $exponent >= 16) {
            $mantissa = $digits[0] . ($length > 1 ? '.' . substr($digits, 1) : '');

            return $sign . $mantissa . 'e' . ($exponent < 0 ? '-' : '+') . str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
        }
        if ($exponent < 0) {
            return $sign . '0.' . str_repeat('0', -$exponent - 1) . $digits;
        }
        if ($exponent + 1 >= $length) {
            return $sign . $digits . str_repeat('0', $exponent + 1 - $length) . '.0';
        }

        return $sign . substr($digits, 0, $exponent + 1) . '.' . substr($digits, $exponent + 1);
    }

    /**
     * @return array{0: bool, 1: string, 2: int} sign, significant digits, decimal exponent of the first digit
     */
    private static function shortestDigits(float $value): array
    {
        $previous = ini_set('serialize_precision', '-1');
        try {
            $text = json_encode(abs($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }
        $exponent = 0;
        $lower = strtolower($text);
        if (str_contains($lower, 'e')) {
            [$lower, $e] = explode('e', $lower, 2);
            $exponent = (int) $e;
        }
        [$int, $frac] = array_pad(explode('.', $lower, 2), 2, '');
        $all = ltrim($int . $frac, '0');
        $leadingZeros = strlen($int . $frac) - strlen($all);
        $exponent += strlen($int) - 1 - $leadingZeros;
        $digits = rtrim($all, '0');

        return [$value < 0, $digits === '' ? '0' : $digits, $exponent];
    }

    private static function string(string $value): string
    {
        $out = '"';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);
            if ($byte < 0x80) {
                $out .= match (true) {
                    $byte === 0x22 => '\\"',
                    $byte === 0x5C => '\\\\',
                    $byte === 0x0A => '\\n',
                    $byte === 0x0D => '\\r',
                    $byte === 0x09 => '\\t',
                    $byte === 0x08 => '\\b',
                    $byte === 0x0C => '\\f',
                    $byte < 0x20 || $byte === 0x7F => sprintf('\\u%04x', $byte),
                    default => $value[$i],
                };
                continue;
            }
            $width = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);
            $character = substr($value, $i, $width);
            if (!mb_check_encoding($character, 'UTF-8')) {
                throw new \InvalidArgumentException('PythonJson: string is not valid UTF-8.');
            }
            $code = mb_ord($character, 'UTF-8');
            $i += $width - 1;
            if ($code > 0xFFFF) {
                $code -= 0x10000;
                $out .= sprintf('\\u%04x\\u%04x', 0xD800 + ($code >> 10), 0xDC00 + ($code & 0x3FF));
            } else {
                $out .= sprintf('\\u%04x', $code);
            }
        }

        return $out . '"';
    }

    /**
     * Replaces the tokens PHP cannot read, outside strings only, with marker
     * strings that {@see restore()} turns back into values.
     */
    private static function protectTokens(string $json): string
    {
        if (!preg_match('/NaN|Infinity|\d{19}/', $json)) {
            return $json;
        }
        $out = '';
        $length = strlen($json);
        $inString = false;
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($inString) {
                $out .= $char;
                if ($char === '\\') {
                    $out .= $json[++$i] ?? '';
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($char === '"') {
                $inString = true;
                $out .= $char;
                continue;
            }
            foreach (['-Infinity', 'Infinity', 'NaN'] as $token) {
                if (substr_compare($json, $token, $i, strlen($token)) === 0) {
                    $out .= '"' . self::TOKEN_JSON . $token . '"';
                    $i += strlen($token) - 1;
                    continue 2;
                }
            }
            if (($char === '-' || ctype_digit($char))
                && preg_match('/\G-?\d+(?![.eE\d])/', $json, $m, 0, $i) === 1
                && strlen(ltrim($m[0], '-')) >= 19
                && !is_int(json_decode($m[0]))) {
                $out .= '"' . self::TOKEN_JSON . 'int:' . $m[0] . '"';
                $i += strlen($m[0]) - 1;
                continue;
            }
            $out .= $char;
        }

        return $out;
    }

    private static function restore(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, self::TOKEN)) {
            $token = substr($value, strlen(self::TOKEN));

            return match (true) {
                $token === 'NaN' => NAN,
                $token === 'Infinity' => INF,
                $token === '-Infinity' => -INF,
                str_starts_with($token, 'int:') => new PythonJsonInt(substr($token, 4)),
                default => $value,
            };
        }
        if (is_array($value)) {
            return array_map(self::restore(...), $value);
        }
        if ($value instanceof stdClass) {
            $vars = get_object_vars($value);
            if ($vars === []) {
                return new stdClass();
            }

            return array_map(self::restore(...), $vars);
        }

        return $value;
    }
}
