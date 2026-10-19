<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Media;

use Psr\Http\Message\ServerRequestInterface;
use TriCab\Api\Config\Settings;

/**
 * Issues and verifies permanent image addresses for private product blobs.
 *
 * A stable address names one blob (container + path) and carries an HMAC, so the
 * redirect endpoint only resolves blobs this API already chose to publish. The
 * address never expires; each request is redirected to a fresh read SAS.
 */
final class StableMediaUrlSigner
{
    public const PATH_PREFIX = '/api/v1/media/images/';

    private const KEY_CONTEXT = 'tricab-stable-media-url-v1';
    private const MAC_BYTES = 16;
    private const SAS_SKEW_MARGIN_SECONDS = 60;
    private const MAX_REDIRECT_CACHE_SECONDS = 300;
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'svg', 'bmp'];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function isEnabled(): bool
    {
        return $this->settings->blobAuthMode === Settings::BLOB_AUTH_MODE_CONNECTION_STRING
            && $this->key() !== null
            && rtrim($this->settings->blobEndpoint, '/') !== '';
    }

    public function isImagePath(string $blobPath): bool
    {
        $extension = strtolower(pathinfo(parse_url($blobPath, PHP_URL_PATH) ?: $blobPath, PATHINFO_EXTENSION));

        return in_array($extension, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Absolute stable address for an own-account blob image URL (signed or unsigned).
     * Null when the URL is not an own blob image or stable addresses are disabled.
     */
    public function stableUrlForBlobUrl(string $blobUrl, string $origin): ?string
    {
        $origin = rtrim(trim($origin), '/');
        if ($origin === '' || !$this->isEnabled()) {
            return null;
        }
        $parsed = $this->parseOwnBlobUrl($blobUrl);
        if ($parsed === null || !$this->isImagePath($parsed['path'])) {
            return null;
        }

        return $origin . $this->pathFor($parsed['container'], $parsed['path']);
    }

    /**
     * Origin stable addresses are built from: PUBLIC_API_ORIGIN, else the request's public scheme and host.
     *
     * Nginx terminates HTTPS and proxies to this process on port 8080. That listen port is not part of
     * the public origin; publishing it makes the browser request https://host:8080, which is closed.
     */
    public function originFor(ServerRequestInterface $request): string
    {
        if ($this->settings->publicApiOrigin !== '') {
            return $this->settings->publicApiOrigin;
        }
        $uri = $request->getUri();
        $forwardedProto = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Proto'))[0]));
        $scheme = in_array($forwardedProto, ['http', 'https'], true)
            ? $forwardedProto
            : strtolower($uri->getScheme());
        $host = strtolower(trim(explode(',', $request->getHeaderLine('X-Forwarded-Host'))[0]));
        if ($host === '') {
            $host = strtolower($uri->getHost());
            $port = $forwardedProto !== '' ? $this->forwardedPort($request) : $uri->getPort();
            if (is_int($port) && !$this->isDefaultPort($scheme, $port)) {
                $host .= ':' . $port;
            }
        }
        if ($scheme === 'https' && str_ends_with($host, ':8080')) {
            $host = substr($host, 0, -5);
        }
        if (!in_array($scheme, ['http', 'https'], true) || preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $host) !== 1) {
            return '';
        }

        return $scheme . '://' . $host;
    }

    private function forwardedPort(ServerRequestInterface $request): ?int
    {
        $raw = trim(explode(',', $request->getHeaderLine('X-Forwarded-Port'))[0]);
        if (preg_match('/^\d{1,5}$/', $raw) !== 1) {
            return null;
        }
        $port = (int) $raw;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    private function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
    }

    /**
     * Replace own product-container image URLs anywhere in a response payload with stable addresses.
     * Documents, external URLs and every other value are left as they are.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function stabilizePayload(array $payload, string $origin): array
    {
        $origin = rtrim(trim($origin), '/');
        if ($origin === '' || !$this->isEnabled()) {
            return $payload;
        }
        foreach ($payload as $key => $value) {
            $payload[$key] = $this->stabilizeValue($value, $origin);
        }

        return $payload;
    }

    private function stabilizeValue(mixed $value, string $origin): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->stabilizeValue($item, $origin);
            }

            return $value;
        }
        if (is_string($value) && $this->isProductBlobImageUrl($value)) {
            return $this->stableUrlForBlobUrl($value, $origin) ?? $value;
        }

        return $value;
    }

    private function isProductBlobImageUrl(string $value): bool
    {
        $parsed = $this->parseOwnBlobUrl($value);

        return $parsed !== null
            && $parsed['container'] === $this->settings->blobContainerProducts
            && $this->isImagePath($parsed['path']);
    }

    public function pathFor(string $container, string $blobPath): string
    {
        $blobPath = ltrim($blobPath, '/');
        $payload = $container . "\n" . $blobPath;
        $token = self::base64Url($payload) . '.' . self::base64Url($this->mac($payload));
        $name = basename($blobPath);

        return self::PATH_PREFIX . $token . ($name !== '' ? '/' . rawurlencode($name) : '');
    }

    /**
     * @return array{container: string, path: string}|null
     */
    public function verify(string $token): ?array
    {
        if (!$this->isEnabled() || strlen($token) > 4096) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        $payload = self::base64UrlDecode($parts[0]);
        $mac = self::base64UrlDecode($parts[1]);
        if ($payload === null || $mac === null || strlen($mac) !== self::MAC_BYTES) {
            return null;
        }
        if (!hash_equals($this->mac($payload), $mac)) {
            return null;
        }
        $newline = strpos($payload, "\n");
        if ($newline === false) {
            return null;
        }
        $container = substr($payload, 0, $newline);
        $path = substr($payload, $newline + 1);
        if ($container === '' || $path === '' || str_contains($path, "\n") || str_contains($path, '..')) {
            return null;
        }

        return ['container' => $container, 'path' => $path];
    }

    /**
     * Stored blob URL for a stable address issued by this API, so it is never persisted.
     */
    public function blobUrlForStableUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, self::PATH_PREFIX)) {
            return null;
        }
        $token = explode('/', substr($path, strlen(self::PATH_PREFIX)))[0];
        $blob = $this->verify($token);
        if ($blob === null) {
            return null;
        }
        $segments = array_map('rawurlencode', explode('/', $blob['path']));

        return rtrim($this->settings->blobEndpoint, '/') . '/' . rawurlencode($blob['container']) . '/' . implode('/', $segments);
    }

    /**
     * Redirect lifetime for browsers and shared caches. It ends well before the read SAS.
     */
    public function redirectCacheControl(): string
    {
        $budget = $this->settings->blobDownloadSasTtlSeconds - self::SAS_SKEW_MARGIN_SECONDS;
        $maxAge = max(0, min(self::MAX_REDIRECT_CACHE_SECONDS, intdiv($budget, 2)));
        if ($maxAge === 0) {
            return 'no-store';
        }

        return sprintf('public, max-age=%d', $maxAge);
    }

    /**
     * @return array{container: string, path: string}|null
     */
    public function parseOwnBlobUrl(string $url): ?array
    {
        $endpoint = rtrim($this->settings->blobEndpoint, '/');
        if ($endpoint === '' || !str_starts_with(strtolower($url), strtolower($endpoint) . '/')) {
            return null;
        }
        $rawPath = ltrim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
        $decoded = rawurldecode($rawPath);
        $slash = strpos($decoded, '/');
        if ($slash === false) {
            return null;
        }
        $container = substr($decoded, 0, $slash);
        $path = substr($decoded, $slash + 1);
        if ($container === '' || $path === '') {
            return null;
        }

        return ['container' => $container, 'path' => $path];
    }

    private function key(): ?string
    {
        $secret = $this->settings->mediaUrlSigningSecret !== ''
            ? $this->settings->mediaUrlSigningSecret
            : $this->settings->blobAccountKey;
        if ($secret === '') {
            return null;
        }

        return hash_hmac('sha256', self::KEY_CONTEXT, $secret, true);
    }

    private function mac(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, (string) $this->key(), true), 0, self::MAC_BYTES);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
