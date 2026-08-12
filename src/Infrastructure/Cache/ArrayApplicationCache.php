<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Cache;

/**
 * Request/container-local cache. Does not survive a new PHP process or php -S child.
 * Use only in tests or when APCu is unavailable (diagnostics must say so).
 */
final class ArrayApplicationCache implements ApplicationCache
{
    /** @var array<string, array{value: mixed, expiresAt: int}> */
    private array $items = [];

    public function get(string $key): mixed
    {
        $item = $this->items[$key] ?? null;
        if ($item === null) {
            return null;
        }
        if ($item['expiresAt'] <= time()) {
            unset($this->items[$key]);

            return null;
        }

        return $item['value'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->items[$key] = [
            'value' => $value,
            'expiresAt' => time() + max(1, $ttlSeconds),
        ];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function deleteByPrefix(string $prefix): void
    {
        foreach (array_keys($this->items) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->items[$key]);
            }
        }
    }

    public function add(string $key, mixed $value, int $ttlSeconds): bool
    {
        if ($this->get($key) !== null) {
            return false;
        }
        $this->set($key, $value, $ttlSeconds);

        return true;
    }

    public function driver(): string
    {
        return 'array';
    }

    public function isCrossRequest(): bool
    {
        return false;
    }

    public function clear(): void
    {
        $this->items = [];
    }
}
