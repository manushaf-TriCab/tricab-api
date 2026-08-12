<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Authentication;

use JsonException;
use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Infrastructure\Database\DatabaseAuthDiagnostics;
use TriCab\Api\Infrastructure\Observability\RequestPerformanceMetrics;

/**
 * Obtains a short-lived Azure SQL access token via Azure CLI.
 * Tokens are cached for up to 60 minutes (typical Entra access-token lifetime),
 * and refreshed 60 seconds before the Azure expiry. Never logged or written to disk.
 */
final class AzureCliAccessTokenProvider
{
    public const CACHE_KEY = 'entra-sql-token:v1:https://database.windows.net/';

    private const LOCK_KEY = 'entra-sql-token-refresh-lock';

    private const RESOURCE = 'https://database.windows.net/';

    /** Cache at most this long (60 minutes). Real Azure expiry still wins if shorter. */
    private const CACHE_TTL_SECONDS = 3600;

    /** Refresh this many seconds before the Azure token's real expiry. */
    private const REFRESH_SKEW_SECONDS = 60;

    private const LOCK_TTL_SECONDS = 5;

    private const LOCK_WAIT_SECONDS = 0.5;

    private readonly ApplicationCache $cache;

    /** @var (callable(): int)|null */
    private $nowFn;

    /**
     * @param (callable(): int)|null $now
     */
    public function __construct(
        private readonly string $azBinary = 'az',
        private readonly AzureCliProcessRunner $processRunner = new ProcOpenAzureCliProcessRunner(),
        private readonly ?RequestPerformanceMetrics $metrics = null,
        ?callable $now = null,
        ?ApplicationCache $cache = null,
    ) {
        $this->nowFn = $now;
        $this->cache = $cache ?? new ArrayApplicationCache();
    }

    public function getAccessToken(): string
    {
        $started = hrtime(true);
        $cached = $this->readValidCachedToken();
        if ($cached !== null) {
            $this->metrics?->recordTokenAcquisition(
                RequestPerformanceMetrics::durationMs($started),
                true,
            );

            return $cached;
        }

        $token = $this->acquireWithLock();
        $this->metrics?->recordTokenAcquisition(
            RequestPerformanceMetrics::durationMs($started),
            false,
        );

        return $token;
    }

    private function acquireWithLock(): string
    {
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        do {
            $cached = $this->readValidCachedToken();
            if ($cached !== null) {
                return $cached;
            }

            if ($this->cache->add(self::LOCK_KEY, 1, self::LOCK_TTL_SECONDS)) {
                try {
                    $cached = $this->readValidCachedToken();
                    if ($cached !== null) {
                        return $cached;
                    }

                    return $this->fetchAndStoreToken();
                } finally {
                    $this->cache->delete(self::LOCK_KEY);
                }
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        $cached = $this->readValidCachedToken();
        if ($cached !== null) {
            return $cached;
        }

        return $this->fetchAndStoreToken();
    }

    private function readValidCachedToken(): ?string
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

        return $token;
    }

    private function fetchAndStoreToken(): string
    {
        DatabaseAuthDiagnostics::log('azure_cli.token.start', [
            'azBinary' => $this->azBinary,
            'resource' => self::RESOURCE,
            'pathEnvPresent' => getenv('PATH') !== false,
            'cacheRefresh' => $this->cache->get(self::CACHE_KEY) !== null,
            'cache_driver' => $this->cache->driver(),
        ]);

        $this->assertAzureCliAvailable();

        [$exitCode, $stdout] = $this->processRunner->run([
            $this->azBinary,
            'account',
            'get-access-token',
            '--resource',
            self::RESOURCE,
            '--output',
            'json',
        ]);

        if ($exitCode !== 0) {
            $this->cache->delete(self::CACHE_KEY);
            DatabaseAuthDiagnostics::log('azure_cli.command.failed', [
                'step' => 'az_get_access_token',
                'success' => false,
                'exitCode' => $exitCode,
                'tokenAcquired' => false,
            ]);
            throw new AzureCliAccessTokenException(
                'Failed to acquire an Azure SQL access token via Azure CLI.',
            );
        }

        try {
            $parsed = $this->parseTokenResponse($stdout);
        } catch (AzureCliAccessTokenException $exception) {
            $this->cache->delete(self::CACHE_KEY);
            throw $exception;
        }

        $ttl = min(
            self::CACHE_TTL_SECONDS,
            $parsed['expiresAt'] - $this->now() - self::REFRESH_SKEW_SECONDS,
        );
        if ($ttl >= 1) {
            $this->cache->set(
                self::CACHE_KEY,
                [
                    'token' => $parsed['token'],
                    'expiresAt' => $parsed['expiresAt'],
                ],
                $ttl,
            );
        }

        DatabaseAuthDiagnostics::log('azure_cli.command.success', [
            'step' => 'az_get_access_token',
            'success' => true,
            'tokenAcquired' => true,
            'tokenLength' => strlen($parsed['token']),
            'expiresAtUnix' => $parsed['expiresAt'],
            'cache_driver' => $this->cache->driver(),
        ]);

        return $parsed['token'];
    }

    private function assertAzureCliAvailable(): void
    {
        if ($this->looksLikePath($this->azBinary)) {
            if (!is_executable($this->azBinary)) {
                DatabaseAuthDiagnostics::log('azure_cli.command.failed', [
                    'step' => 'az_available',
                    'success' => false,
                ]);
                throw new AzureCliAccessTokenException(
                    'Azure CLI is unavailable. Install Azure CLI and ensure "az" is on PATH.',
                );
            }
        }
    }

    /**
     * @return array{token: string, expiresAt: int}
     */
    private function parseTokenResponse(string $stdout): array
    {
        $raw = trim($stdout);
        if ($raw === '') {
            DatabaseAuthDiagnostics::log('azure_cli.command.failed', [
                'step' => 'az_get_access_token',
                'success' => false,
                'tokenAcquired' => false,
                'stdoutEmpty' => true,
            ]);
            throw new AzureCliAccessTokenException(
                'Failed to acquire an Azure SQL access token via Azure CLI.',
            );
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            DatabaseAuthDiagnostics::log('azure_cli.command.failed', [
                'step' => 'az_get_access_token_parse',
                'success' => false,
                'tokenAcquired' => false,
            ]);
            throw new AzureCliAccessTokenException(
                'Azure CLI returned an invalid access-token response.',
            );
        }

        if (!is_array($decoded)) {
            throw new AzureCliAccessTokenException(
                'Azure CLI returned an invalid access-token response.',
            );
        }

        $token = $decoded['accessToken'] ?? null;
        if (!is_string($token) || trim($token) === '') {
            throw new AzureCliAccessTokenException(
                'Azure CLI returned an invalid access-token response.',
            );
        }

        $expiresAt = $this->parseExpiry($decoded);
        if ($expiresAt <= $this->now()) {
            throw new AzureCliAccessTokenException(
                'Azure CLI returned an expired access token.',
            );
        }

        return [
            'token' => trim($token),
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * @param array<mixed> $decoded
     */
    private function parseExpiry(array $decoded): int
    {
        $unix = $decoded['expires_on'] ?? $decoded['expiresOnTimestamp'] ?? null;
        if (is_numeric($unix)) {
            return (int) $unix;
        }

        $expiresOn = $decoded['expiresOn'] ?? null;
        if (is_string($expiresOn) && trim($expiresOn) !== '') {
            $parsed = strtotime($expiresOn);
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return $this->now() + self::CACHE_TTL_SECONDS;
    }

    private function now(): int
    {
        if ($this->nowFn !== null) {
            return ($this->nowFn)();
        }

        return time();
    }

    private function looksLikePath(string $binary): bool
    {
        return str_contains($binary, '/') || str_starts_with($binary, '\\');
    }
}
