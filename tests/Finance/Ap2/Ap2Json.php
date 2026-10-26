<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap2;

/** Reading decoded JSON in a test without PHPStan losing the thread at every level. */
final class Ap2Json
{
    /** A value at a dotted path (`error.details.issues`, `forecast.0.kind`), null when any step is missing. */
    public static function get(mixed $json, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($json) || !array_key_exists($key, $json)) {
                return null;
            }
            $json = $json[$key];
        }

        return $json;
    }

    public static function str(mixed $json, string $path): string
    {
        $value = self::get($json, $path);

        return is_string($value) ? $value : '';
    }

    /** @return array<array-key, mixed> */
    public static function arr(mixed $json, string $path = ''): array
    {
        $value = $path === '' ? $json : self::get($json, $path);

        return is_array($value) ? $value : [];
    }
}
