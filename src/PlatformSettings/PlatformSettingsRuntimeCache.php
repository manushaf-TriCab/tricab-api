<?php

declare(strict_types=1);

namespace TriCab\Api\PlatformSettings;

use Closure;
use Throwable;
use TriCab\Api\Infrastructure\Cache\ApplicationCache;

/**
 * The last runtime overrides read from the database, kept in the application cache so requests and
 * workers do not query the serverless database each time (it must stay free to pause). A save writes
 * the new overrides here straight away; a reader that may query the database checks it again once the
 * copy is older than REFRESH_SECONDS. If that check fails, the last good copy is used: never the
 * environment baseline, so mail cannot quietly go back to old recipients during an outage.
 */
final class PlatformSettingsRuntimeCache
{
    public const REFRESH_SECONDS = 300;
    // Long, so an outage falls back to the last good copy rather than failing.
    private const KEEP_SECONDS = 30 * 86400;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /** @param (Closure(): int)|null $clock */
    public function __construct(
        private readonly ApplicationCache $cache,
        private readonly string $environment,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param callable(): array<string,mixed> $load reads the overrides from the database
     * @param bool $refresh false for callers that must not wake the database: any stored copy is used,
     *                      and the database is read only when there is none
     * @return array<string,mixed>
     */
    public function overrides(callable $load, bool $refresh): array
    {
        $cached = $this->read();
        if ($cached !== null && (!$refresh || ($this->clock)() - $cached['storedAt'] < self::REFRESH_SECONDS)) {
            return $cached['overrides'];
        }
        try {
            $overrides = $load();
        } catch (Throwable $exception) {
            if ($cached === null) {
                throw $exception;
            }
            error_log(sprintf(
                '[platform-settings] reading the database failed (%s); using the copy stored at %s',
                $exception::class,
                gmdate('c', $cached['storedAt']),
            ));

            return $cached['overrides'];
        }
        $this->store($overrides);

        return $overrides;
    }

    /** @param array<string,mixed> $overrides */
    public function store(array $overrides): void
    {
        $this->cache->set($this->key(), ['storedAt' => ($this->clock)(), 'overrides' => $overrides], self::KEEP_SECONDS);
    }

    public function forget(): void
    {
        $this->cache->delete($this->key());
    }

    /** @return array{storedAt: int, overrides: array<string,mixed>}|null */
    private function read(): ?array
    {
        $entry = $this->cache->get($this->key());
        if (!is_array($entry) || !is_int($entry['storedAt'] ?? null) || !is_array($entry['overrides'] ?? null)) {
            return null;
        }
        /** @var array<string,mixed> $overrides */
        $overrides = $entry['overrides'];

        return ['storedAt' => $entry['storedAt'], 'overrides' => $overrides];
    }

    private function key(): string
    {
        return 'platform-settings:runtime:' . $this->environment;
    }
}
