<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use RuntimeException;

/**
 * Fetches Google OpenID JWKS for ID-token signature validation.
 */
final class GoogleJwksClient implements GoogleJwksProvider
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const DEFAULT_TIMEOUT_SECONDS = 10.0;
    private const CACHE_TTL_SECONDS = 3600;

    /** @var array{keys: list<array<string, mixed>>}|null */
    private ?array $memoryCache = null;

    private int $memoryCacheFetchedAt = 0;

    public function __construct(
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly ?string $cacheDirectory = null,
    ) {
    }

    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    public function fetch(): array
    {
        if (
            $this->memoryCache !== null
            && (time() - $this->memoryCacheFetchedAt) < self::CACHE_TTL_SECONDS
        ) {
            return $this->memoryCache;
        }

        $cached = $this->readCache();
        if ($cached !== null) {
            $this->memoryCache = $cached;
            $this->memoryCacheFetchedAt = time();

            return $cached;
        }

        $ssl = $this->sslOptions();
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "Accept: application/json\r\nUser-Agent: tricab-api-google-jwks/1.0\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => $ssl,
        ]);

        $body = @file_get_contents(self::JWKS_URL, false, $context);
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header ?? [];
        $status = self::statusFromHeaders($responseHeaders);

        if ($body === false || $body === '') {
            throw new RuntimeException('Unable to download Google JWKS.');
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Unable to download Google JWKS.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            throw new RuntimeException('Google JWKS response is invalid.');
        }

        /** @var array{keys: list<array<string, mixed>>} $decoded */
        $this->writeCache($decoded);
        $this->memoryCache = $decoded;
        $this->memoryCacheFetchedAt = time();

        return $decoded;
    }

    /**
     * @return array{keys: list<array<string, mixed>>}|null
     */
    private function readCache(): ?array
    {
        $path = $this->cacheFilePath();
        if ($path === null || !is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);
        if (
            !is_array($decoded)
            || !isset($decoded['fetchedAt'], $decoded['jwks'])
            || !is_int($decoded['fetchedAt'])
            || !is_array($decoded['jwks'])
        ) {
            return null;
        }

        if ((time() - $decoded['fetchedAt']) >= self::CACHE_TTL_SECONDS) {
            return null;
        }

        $jwks = $decoded['jwks'];
        if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
            return null;
        }

        /** @var array{keys: list<array<string, mixed>>} $jwks */
        return $jwks;
    }

    /**
     * @param array{keys: list<array<string, mixed>>} $jwks
     */
    private function writeCache(array $jwks): void
    {
        $path = $this->cacheFilePath();
        if ($path === null) {
            return;
        }

        $payload = json_encode(
            [
                'fetchedAt' => time(),
                'ttlSeconds' => self::CACHE_TTL_SECONDS,
                'jwks' => $jwks,
            ],
            JSON_THROW_ON_ERROR,
        );

        @file_put_contents($path, $payload, LOCK_EX);
    }

    private function cacheFilePath(): ?string
    {
        $directory = $this->cacheDirectory ?? dirname(__DIR__, 3) . '/var/cache/google-jwks';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        return $directory . '/oauth2-v3-certs.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function sslOptions(): array
    {
        $cafile = self::resolveCaFile();
        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ];

        if ($cafile !== null) {
            $options['cafile'] = $cafile;
        }

        return $options;
    }

    private static function resolveCaFile(): ?string
    {
        $candidates = [
            (string) ini_get('openssl.cafile'),
            (string) ini_get('curl.cainfo'),
            '/opt/homebrew/etc/openssl@3/cert.pem',
            '/opt/homebrew/etc/ca-certificates/cert.pem',
            '/usr/local/etc/openssl@3/cert.pem',
            '/etc/ssl/cert.pem',
        ];

        foreach ($candidates as $candidate) {
            $path = trim($candidate);
            if ($path !== '' && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param list<string> $headers
     */
    private static function statusFromHeaders(array $headers): int
    {
        if ($headers === [] || preg_match('/\s(\d{3})\s/', $headers[0], $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }
}
