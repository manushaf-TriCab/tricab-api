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

    public function __construct(
        private readonly ApplicationCache $cache,
    ) {
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
        $this->cache->deleteByPrefix(self::CATALOGUE_PREFIX);
    }
}
