<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Authentication;

use RuntimeException;

/**
 * Fetches Microsoft Entra OpenID JWKS for access-token signature validation.
 * Uses verified TLS (never disables peer verification) and a short-lived file cache.
 */
final class EntraJwksClient implements EntraJwksProvider
{
    private const DEFAULT_TIMEOUT_SECONDS = 10.0;

    /** Successful JWKS responses are reused for at most one hour. */
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly ?string $cacheDirectory = null,
    ) {
    }

    /**
     * @return array{keys: list<array<string, mixed>>}
     */
    public function fetch(string $tenantId): array
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            throw new RuntimeException('Microsoft Entra tenant id is required to download JWKS.');
        }

        $cached = $this->readCache($tenantId);
        if ($cached !== null) {
            return $cached;
        }

        $url = sprintf(
            'https://login.microsoftonline.com/%s/discovery/v2.0/keys',
            rawurlencode($tenantId),
        );

        $ssl = $this->sslOptions();
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "Accept: application/json\r\nUser-Agent: tricab-api-jwks/1.0\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => $ssl,
        ]);

        $body = @file_get_contents($url, false, $context);
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header;
        $status = self::statusFromHeaders($responseHeaders);
        $bytes = is_string($body) ? strlen($body) : 0;

        if ($body === false) {
            $last = error_get_last();
            $detail = is_array($last)
                ? self::sanitizeTlsMessage($last['message'])
                : 'stream request failed';
            self::logFailure('tls_or_transport', $status, $bytes, $detail, $ssl);

            throw new RuntimeException('Unable to download Microsoft Entra JWKS.');
        }

        if ($status < 200 || $status >= 300) {
            self::logFailure('http_status', $status, $bytes, 'non-success JWKS HTTP status', $ssl);
            throw new RuntimeException('Unable to download Microsoft Entra JWKS.');
        }

        if ($body === '') {
            self::logFailure('empty_body', $status, 0, 'empty JWKS response body', $ssl);
            throw new RuntimeException('Unable to download Microsoft Entra JWKS.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['keys']) || !is_array($decoded['keys'])) {
            self::logFailure('invalid_json', $status, $bytes, 'JWKS JSON missing keys array', $ssl);
            throw new RuntimeException('Microsoft Entra JWKS response is invalid.');
        }

        /** @var array{keys: list<array<string, mixed>>} $decoded */
        $this->writeCache($tenantId, $decoded);
        self::logSuccess($status, $bytes, count($decoded['keys']), $ssl);

        return $decoded;
    }

    /**
     * @return array{keys: list<array<string, mixed>>}|null
     */
    private function readCache(string $tenantId): ?array
    {
        $path = $this->cacheFilePath($tenantId);
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
    private function writeCache(string $tenantId, array $jwks): void
    {
        $path = $this->cacheFilePath($tenantId);
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

    private function cacheFilePath(string $tenantId): ?string
    {
        $directory = $this->cacheDirectory ?? dirname(__DIR__, 3) . '/var/cache/entra-jwks';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        // Tenant id is a GUID; keep the filename conservative.
        $safeTenant = preg_replace('/[^a-zA-Z0-9.-]/', '_', $tenantId) ?? 'tenant';

        return $directory . '/' . $safeTenant . '.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function sslOptions(): array
    {
        $cafile = self::resolveCaFile();
        $capath = self::resolveCaPath();

        $options = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            // Never disable verification.
            'allow_self_signed' => false,
        ];

        if ($cafile !== null) {
            $options['cafile'] = $cafile;
        }

        if ($capath !== null) {
            $options['capath'] = $capath;
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

    private static function resolveCaPath(): ?string
    {
        $candidates = [
            (string) ini_get('openssl.capath'),
            '/opt/homebrew/etc/openssl@3/certs',
        ];

        foreach ($candidates as $candidate) {
            $path = trim($candidate);
            if ($path !== '' && is_dir($path)) {
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
        if ($headers === []) {
            return 0;
        }

        if (preg_match('/\s(\d{3})\s/', $headers[0], $matches) !== 1) {
            return 0;
        }

        return (int) $matches[1];
    }

    private static function sanitizeTlsMessage(string $message): string
    {
        // Keep only the failure class — never include response bodies.
        $message = preg_replace('/https?:\/\/\S+/i', '[url]', $message) ?? $message;

        return substr($message, 0, 240);
    }

    /**
     * @param array<string, mixed> $ssl
     */
    private static function logFailure(
        string $reason,
        int $status,
        int $bytes,
        string $detail,
        array $ssl,
    ): void {
        error_log(sprintf(
            '[entra-jwks] download_failed reason=%s http_status=%d bytes=%d cafile=%s detail=%s',
            $reason,
            $status,
            $bytes,
            isset($ssl['cafile']) ? 'set' : 'unset',
            $detail,
        ));
    }

    /**
     * @param array<string, mixed> $ssl
     */
    private static function logSuccess(int $status, int $bytes, int $keyCount, array $ssl): void
    {
        error_log(sprintf(
            '[entra-jwks] download_ok http_status=%d bytes=%d key_count=%d cafile=%s',
            $status,
            $bytes,
            $keyCount,
            isset($ssl['cafile']) ? 'set' : 'unset',
        ));
    }
}
