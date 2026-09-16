<?php

declare(strict_types=1);

namespace TriCab\Api\Support;

/**
 * Typed readers for decoded JSON structures (array<string, mixed>).
 *
 * Unlike {@see \TriCab\Api\Http\Request\Admin\RequestFields}, which validates
 * inbound HTTP payloads and throws on the wrong shape, these accessors coerce
 * and fall back to a default. They are for trusted-but-untyped data — bundled
 * content manifests, persisted JSON columns, upstream service payloads — where
 * a malformed field should degrade to a default rather than fail the request.
 */
final class JsonValue
{
    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function stringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value) || is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function intOrNull(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_bool($value) || is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : $default;
    }

    /**
     * Reads a nested JSON object, or an empty array when absent/malformed.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            return [];
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Reads a nested JSON object, or null when absent/malformed.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public static function objectOrNull(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Reads a JSON array of objects, skipping any non-object entries.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Reads a JSON array of strings, skipping any non-string entries.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Narrows an arbitrary mixed value to array<string, mixed>.
     *
     * @return array<string, mixed>
     */
    public static function toObject(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $object = [];
        foreach ($value as $key => $item) {
            $object[(string) $key] = $item;
        }

        return $object;
    }
}
