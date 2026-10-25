<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Cache;

/**
 * Keys and TTLs for infrequently changing catalogue/lookup payloads.
 */
final class ReferenceDataCache
{
    public const LOOKUPS_KEY = 'admin:product-lookups:v2';

    public const LOOKUPS_TTL_SECONDS = 600;

    public const REGIONS_KEY = 'public:regions:v1';

    public const REGIONS_TTL_SECONDS = 600;

    public const CATALOGUE_PREFIX = 'catalogue:';

    public const CATALOGUE_TTL_SECONDS = 300;

    /** Outside CATALOGUE_PREFIX so invalidation bumps it instead of deleting it. */
    public const CATALOGUE_GENERATION_KEY = 'catalogue-generation:v1';

    /** Outside CATALOGUE_PREFIX ("catalogue:"), so invalidation never removes a held lock. */
    public const CATALOGUE_REBUILD_LOCK_PREFIX = 'catalogue-rebuild:';

    public const CATALOGUE_REBUILD_LOCK_TTL_SECONDS = 60;

    /**
     * @param int $rebuildWaitMs how long a request waits for another request's rebuild of the same key
     *                           before building it itself
     */
    public function __construct(
        private readonly ApplicationCache $cache,
        private readonly int $rebuildWaitMs = 8000,
        private readonly int $rebuildPollMs = 100,
    ) {
    }

    /**
     * Cached catalogue payload, or build it. Only one request per key (per cache instance) builds at a
     * time; others wait up to rebuildWaitMs for that result instead of repeating the same expensive
     * build. A build that started before an invalidation is returned to its caller but not cached.
     *
     * @param callable(): array<string, mixed> $build
     * @return array{0: array<string, mixed>, 1: bool} the payload and whether it came from the cache
     */
    public function rememberCatalogue(string $key, callable $build): array
    {
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            return [$cached, true];
        }

        $lockKey = self::CATALOGUE_REBUILD_LOCK_PREFIX . $key;
        $lockToken = bin2hex(random_bytes(8));
        $deadline = hrtime(true) + $this->rebuildWaitMs * 1_000_000;
        while (!$this->cache->add($lockKey, $lockToken, self::CATALOGUE_REBUILD_LOCK_TTL_SECONDS)) {
            // Another request is building. Wait for its result; if it gives up without one, compete
            // for the lock again so only one waiter rebuilds. Past the deadline, build unlocked.
            if (hrtime(true) >= $deadline) {
                return [$this->buildAndStore($key, $build), false];
            }
            usleep(max(1, $this->rebuildPollMs) * 1000);
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return [$cached, true];
            }
        }

        try {
            $cached = $this->cache->get($key);
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return [$cached, true];
            }

            return [$this->buildAndStore($key, $build), false];
        } finally {
            // A build that outlived the lock TTL must not release a lock another request now holds.
            if ($this->cache->get($lockKey) === $lockToken) {
                $this->cache->delete($lockKey);
            }
        }
    }

    /**
     * @param callable(): array<string, mixed> $build
     * @return array<string, mixed>
     */
    private function buildAndStore(string $key, callable $build): array
    {
        $generation = $this->catalogueGeneration();
        $value = $build();
        if ($this->catalogueGeneration() !== $generation) {
            return $value;
        }
        $this->cache->set($key, $value, self::CATALOGUE_TTL_SECONDS);
        // Invalidation bumps the generation before deleting entries, so an invalidation that
        // landed between the check above and this write is visible here.
        if ($this->catalogueGeneration() !== $generation) {
            $this->cache->delete($key);
        }

        return $value;
    }

    private function catalogueGeneration(): string
    {
        $generation = $this->cache->get(self::CATALOGUE_GENERATION_KEY);

        return is_string($generation) ? $generation : '';
    }

    public function getLookups(): mixed
    {
        return $this->cache->get(self::LOOKUPS_KEY);
    }

    public function setLookups(mixed $value): void
    {
        $this->cache->set(self::LOOKUPS_KEY, $value, self::LOOKUPS_TTL_SECONDS);
    }

    public function invalidateLookups(): void
    {
        $this->cache->delete(self::LOOKUPS_KEY);
    }

    public function getRegions(): mixed
    {
        return $this->cache->get(self::REGIONS_KEY);
    }

    public function setRegions(mixed $value): void
    {
        $this->cache->set(self::REGIONS_KEY, $value, self::REGIONS_TTL_SECONDS);
    }

    public function invalidateRegions(): void
    {
        $this->cache->delete(self::REGIONS_KEY);
    }

    /**
     * @param array<string, scalar|null> $params
     */
    public function cataloguePanelKey(array $params): string
    {
        ksort($params);
        $encoded = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::CATALOGUE_PREFIX . 'panel:' . hash('sha256', $encoded);
    }

    /**
     * Public catalogue node / tab tree payloads share the catalogue prefix, so every catalogue
     * invalidation drops them together with the panel.
     *
     * @param 'node'|'tree'|'tab-nodes' $kind
     * @param array<string, scalar|null> $params
     */
    public function catalogueKey(string $kind, array $params): string
    {
        ksort($params);
        $encoded = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::CATALOGUE_PREFIX . $kind . ':' . hash('sha256', $encoded);
    }

    public function getCatalogue(string $key): mixed
    {
        return $this->cache->get($key);
    }

    public function setCatalogue(string $key, mixed $value): void
    {
        $this->cache->set($key, $value, self::CATALOGUE_TTL_SECONDS);
    }

    public function invalidateCatalogue(): void
    {
        $this->cache->set(self::CATALOGUE_GENERATION_KEY, bin2hex(random_bytes(8)), 86_400);
        $this->cache->deleteByPrefix(self::CATALOGUE_PREFIX);
    }
}
