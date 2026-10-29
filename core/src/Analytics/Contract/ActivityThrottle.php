<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Contract;

/**
 * Request limiting and write suppression for activity signals. Suppression is an optimisation only: a store
 * without cross-request memory reports nothing as recorded, and the idempotent writes stay correct.
 */
interface ActivityThrottle
{
    /** Counts the attempt and reports whether it is within $limit per $windowSeconds for $key. */
    public function allow(string $key, int $limit, int $windowSeconds): bool;

    public function isRecorded(string $key): bool;

    public function markRecorded(string $key, int $ttlSeconds): void;

    public function forget(string $key): void;
}
