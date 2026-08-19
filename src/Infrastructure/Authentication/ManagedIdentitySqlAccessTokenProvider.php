<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Authentication;

use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Infrastructure\Database\DatabaseAuthDiagnostics;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;

/**
 * Obtains a short-lived Azure SQL access token from App Service / IMDS managed identity.
 * Tokens are cached until 5 minutes before Azure expiry (capped at 60 minutes).
 * Cache values and identity headers are never logged.
 */
final class ManagedIdentitySqlAccessTokenProvider
{
    public const CACHE_KEY = 'mi-sql-token:v1:https://database.windows.net/';

    private const LOCK_KEY = 'mi-sql-token-refresh-lock';

    private const RESOURCE = 'https://database.windows.net/';

    private const CACHE_TTL_SECONDS = 3600;

    private const REFRESH_SKEW_SECONDS = 300;

    private const LOCK_TTL_SECONDS = 5;

    private const LOCK_WAIT_SECONDS = 0.5;

    private readonly ApplicationCache $cache;

    /** @var (callable(): int)|null */
    private $nowFn;

    /**
     * @param (callable(): int)|null $now
     */
    public function __construct(
        private readonly ManagedIdentityTokenClient $client,
        ?ApplicationCache $cache = null,
        private readonly ?RequestPerformanceMetrics $metrics = null,
        ?callable $now = null,
    ) {
        $this->cache = $cache ?? new ArrayApplicationCache();
        $this->nowFn = $now;
    }

    public function getAccessToken(): string
    {
        $started = hrtime(true);
        $cached = $this->readValidCachedEntry();
        if ($cached !== null) {
            $expiresIn = max(0, $cached['expiresAt'] - $this->now());
            $this->metrics?->recordTokenAcquisition(
                RequestPerformanceMetrics::durationMs($started),
                true,
                $expiresIn,
            );
            DatabaseAuthDiagnostics::log('managed_identity.token.cache_hit', [
                'cache_hit' => 1,
                'refresh' => 0,
                'expires_in_seconds' => $expiresIn,
                'cache_driver' => $this->cache->driver(),
                'resource' => self::RESOURCE,
            ]);

            return $cached['token'];
        }

        $token = $this->acquireWithLock();
        $cachedAfter = $this->readValidCachedEntry();
        $expiresIn = $cachedAfter !== null ? max(0, $cachedAfter['expiresAt'] - $this->now()) : 0;
        $this->metrics?->recordTokenAcquisition(
            RequestPerformanceMetrics::durationMs($started),
            false,
            $expiresIn,
        );

        return $token;
    }

    private function acquireWithLock(): string
    {
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        do {
            $cached = $this->readValidCachedEntry();
            if ($cached !== null) {
                return $cached['token'];
            }

            if ($this->cache->add(self::LOCK_KEY, 1, self::LOCK_TTL_SECONDS)) {
                try {
                    $cached = $this->readValidCachedEntry();
                    if ($cached !== null) {
                        return $cached['token'];
                    }

                    return $this->fetchAndStoreToken();
                } finally {
                    $this->cache->delete(self::LOCK_KEY);
                }
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $cached = $this->readValidCachedEntry();
        if ($cached !== null) {
            return $cached['token'];
        }

        return $this->fetchAndStoreToken();
    }

    /**
     * @return array{token: string, expiresAt: int}|null
     */
    private function readValidCachedEntry(): ?array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        if (!is_array($entry)) {
            return null;
        }

        $token = $entry['token'] ?? null;
        $expiresAt = $entry['expiresAt'] ?? null;
        if (!is_string($token) || $token === '' || !is_int($expiresAt)) {
            $this->cache->delete(self::CACHE_KEY);

            return null;
        }

        if ($this->now() >= ($expiresAt - self::REFRESH_SKEW_SECONDS)) {
            $this->cache->delete(self::CACHE_KEY);

            return null;
        }

        return [
            'token' => $token,
            'expiresAt' => $expiresAt,
        ];
    }

    private function fetchAndStoreToken(): string
    {
        DatabaseAuthDiagnostics::log('managed_identity.token.start', [
            'resource' => self::RESOURCE,
            'cache_driver' => $this->cache->driver(),
        ]);

        try {
            $acquired = $this->client->fetch(self::RESOURCE);
        } catch (ManagedIdentityTokenException $exception) {
            $this->cache->delete(self::CACHE_KEY);
            DatabaseAuthDiagnostics::log('managed_identity.token.failed', [
                'success' => false,
                'endpointKind' => null,
                'reason' => $exception->getMessage(),
            ]);
            throw new ManagedIdentitySqlAccessTokenException(
                'Failed to acquire an Azure SQL access token via managed identity.',
                0,
                $exception,
            );
        }

        $ttl = min(
            self::CACHE_TTL_SECONDS,
            $acquired->expiresAt - $this->now() - self::REFRESH_SKEW_SECONDS,
        );
        if ($ttl >= 1) {
            $this->cache->set(
                self::CACHE_KEY,
                [
                    'token' => $acquired->token,
                    'expiresAt' => $acquired->expiresAt,
                ],
                $ttl,
            );
        }

        DatabaseAuthDiagnostics::log('managed_identity.token.success', [
            'success' => true,
            'cache_hit' => 0,
            'refresh' => 1,
            'expires_in_seconds' => max(0, $acquired->expiresAt - $this->now()),
            'cache_driver' => $this->cache->driver(),
            'endpointKind' => $acquired->endpointKind,
            'resource' => self::RESOURCE,
        ]);

        return $acquired->token;
    }

    private function now(): int
    {
        if ($this->nowFn !== null) {
            return ($this->nowFn)();
        }

        return time();
    }
}
