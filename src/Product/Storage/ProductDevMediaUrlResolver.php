<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Storage;

use TriCab\Api\Config\Settings;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductMediaRepository;

/**
 * Resolves browser-loadable product asset URLs.
 *
 * - Azure connection-string: short-lived blob-scoped READ SAS (never persisted).
 * - Memory/dev: gated /api/v1/dev/product-files/{id} when bytes exist.
 * Never logs SAS tokens, account keys, or connection strings.
 */
final class ProductDevMediaUrlResolver
{
    public const PATH_PREFIX = '/api/v1/dev/product-files/';

    public function __construct(
        private readonly Settings $settings,
        private readonly ProductMediaRepository $media,
        private readonly ProductBlobStore $blobStore,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->isDevMemoryProductFileDeliveryEnabled();
    }

    public function urlForFilePublicId(string $filePublicId): string
    {
        return self::PATH_PREFIX . rawurlencode($filePublicId);
    }

    /**
     * Return a URL the browser can GET. Azure private blobs are signed at response
     * time; SQL continues to store the canonical unsigned PublicUrl / path.
     */
    public function resolvePublicUrl(
        ?string $storedUrl,
        string $filePublicId,
        ?string $storageContainer = null,
        ?string $storagePath = null,
    ): ?string {
        if ($this->settings->blobAuthMode === Settings::BLOB_AUTH_MODE_CONNECTION_STRING) {
            $signed = $this->signPrivateAzureReadUrl($storedUrl, $storageContainer, $storagePath);
            if ($signed !== null) {
                return $signed;
            }
        }

        if ($storedUrl !== null && $storedUrl !== '') {
            return $storedUrl;
        }

        if (!$this->isEnabled() || $filePublicId === '') {
            return null;
        }

        if (!$this->blobStore instanceof InMemoryProductBlobStore) {
            return null;
        }

        $record = $this->media->findByPublicId($filePublicId);
        if ($record === null) {
            return null;
        }

        $container = $record->storageContainer;
        $path = $record->storagePath;
        if ($container === null || $container === '' || $path === null || $path === '') {
            return null;
        }

        if (!$this->blobStore->hasObject($container, $path)) {
            return null;
        }

        return $this->urlForFilePublicId($filePublicId);
    }

    private function signPrivateAzureReadUrl(
        ?string $storedUrl,
        ?string $storageContainer,
        ?string $storagePath,
    ): ?string {
        $cdn = $this->settings->blobCdnBaseUrl;
        if ($cdn !== '' && $storedUrl !== null && $storedUrl !== '' && str_starts_with($storedUrl, $cdn)) {
            return null;
        }

        $container = $storageContainer !== null && trim($storageContainer) !== ''
            ? trim($storageContainer)
            : null;
        $path = $storagePath !== null && trim($storagePath) !== ''
            ? ltrim(trim($storagePath), '/')
            : null;

        if (($container === null || $path === null) && $storedUrl !== null && $storedUrl !== '') {
            $parsed = $this->parseOwnAzureBlobUrl($storedUrl);
            if ($parsed !== null) {
                $container ??= $parsed['container'];
                $path ??= $parsed['path'];
            }
        }

        if ($container === null || $container === '' || $path === null || $path === '') {
            return null;
        }

        $ticket = $this->blobStore->issueReadDownload(
            $container,
            $path,
            $this->settings->blobDownloadSasTtlSeconds,
        );

        return $ticket->uploadUrl;
    }

    /**
     * @return array{container: string, path: string}|null
     */
    private function parseOwnAzureBlobUrl(string $url): ?array
    {
        $endpoint = rtrim($this->settings->blobEndpoint, '/');
        if ($endpoint === '' || !str_starts_with($url, $endpoint . '/')) {
            return null;
        }

        $parts = parse_url($url);
        $rawPath = ltrim((string) ($parts['path'] ?? ''), '/');
        if ($rawPath === '') {
            return null;
        }

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
}
