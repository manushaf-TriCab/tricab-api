<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Cache;

/**
 * Process-local TTL cache. Shared across HTTP requests in php -S / PHP-FPM workers.
 * Not safe for user-specific or permission-sensitive payloads.
 */
final class ProcessMemoryCache
{
    /** @var array<string, array{value: mixed, expiresAt: int}> */
    private static array $items = [];

    public function get(string $key): mixed
    {
        $item = self::$items[$key] ?? null;
        if ($item === null) {
            return null;
        }
        if ($item['expiresAt'] <= time()) {
            unset(self::$items[$key]);

            return null;
        }

        return $item['value'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $ttl = max(1, $ttlSeconds);
        self::$items[$key] = [
            'value' => $value,
            'expiresAt' => time() + $ttl,
        ];
    }

    public function delete(string $key): void
    {
        unset(self::$items[$key]);
    }

    public function deleteByPrefix(string $prefix): void
    {
        foreach (array_keys(self::$items) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$items[$key]);
            }
        }
    }

    public static function clearAll(): void
    {
        self::$items = [];
    }
}
