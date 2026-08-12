<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Cache;

/**
 * Cross-request cache boundary. Implementations must not log values.
 */
interface ApplicationCache
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;

    public function delete(string $key): void;

    public function deleteByPrefix(string $prefix): void;

    /**
     * Store only if the key is absent. Used for short-lived locks.
     */
    public function add(string $key, mixed $value, int $ttlSeconds): bool;

    public function driver(): string;

    public function isCrossRequest(): bool;
}
