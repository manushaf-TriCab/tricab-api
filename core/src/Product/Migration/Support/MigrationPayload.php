<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Support;

final class MigrationPayload
{
    /** @param mixed $value */
    public static function string(mixed $value, string $default = ''): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /** @param mixed $value */
    public static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = self::string($value, '');

        return $s === '' ? null : $s;
    }

    /** @param mixed $value */
    public static function bool(mixed $value, bool $default = false): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /** @param mixed $value */
    public static function int(mixed $value, int $default = 0): int
    {
        return is_int($value) ? $value : $default;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function assoc(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return self::stringKeyed($value);
    }

    /**
     * @param mixed $value
     * @return list<array<string, mixed>>
     */
    public static function assocList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = self::stringKeyed($row);
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @param list<string> $default
     * @return list<string>
     */
    public static function stringList(mixed $value, array $default = []): array
    {
        if (!is_array($value)) {
            return $default;
        }

        return self::collectStrings($value);
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function collectStrings(array $value): array
    {
        $out = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $out[] = $item;
            } elseif (is_int($item) || is_float($item)) {
                $out[] = (string) $item;
            }
        }

        return $out;
    }
}
