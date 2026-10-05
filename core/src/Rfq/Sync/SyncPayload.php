<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Sync;

use InvalidArgumentException;
use TriCab\Core\Rfq\Storage\PythonJson;

/**
 * The frozen plan as it is stored and hashed (parity plan E7.3).
 *
 * The source writes `rfq_sync_attempts.payload` as
 * `json.dumps(plan, sort_keys=True, separators=(",", ":"), ensure_ascii=False)`
 * and keeps the SHA-256 of that text, so a resume can prove it is sending what
 * was reviewed (architecture doc §6.5: "Phase 5 must write that format"). This
 * is that format: keys sorted at every level, no whitespace, text as UTF-8, and
 * numbers as Python spells them.
 */
final class SyncPayload
{
    /** @param array<string, mixed> $plan */
    public static function encode(array $plan): string
    {
        return self::value($plan);
    }

    public static function sha256(string $payload): string
    {
        return hash('sha256', $payload);
    }

    private static function value(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return PythonJson::floatRepr($value, json: true);
        }
        if (is_string($value)) {
            return self::string($value);
        }
        if (!is_array($value)) {
            throw new InvalidArgumentException('A sync payload holds only JSON values.');
        }
        if ($value === []) {
            return '[]';
        }
        if (array_is_list($value)) {
            return '[' . implode(',', array_map(self::value(...), $value)) . ']';
        }
        $keys = array_map('strval', array_keys($value));
        // Python sorts keys by code point; for the plan's ASCII keys that is byte order.
        sort($keys, SORT_STRING);
        $parts = [];
        foreach ($keys as $key) {
            $parts[] = self::string($key) . ':' . self::value($value[$key]);
        }

        return '{' . implode(',', $parts) . '}';
    }

    /** `json.dumps(..., ensure_ascii=False)` for one string: UTF-8 as is; `"`, `\` and controls below 0x20 escaped. */
    private static function string(string $text): string
    {
        $encoded = json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);

        return $encoded;
    }
}
