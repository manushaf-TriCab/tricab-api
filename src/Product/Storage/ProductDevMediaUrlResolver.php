<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Storage;

use TriCab\Api\Config\Settings;
use TriCab\Core\Product\Contract\ProductBlobStore;
use TriCab\Core\Product\Contract\ProductMediaRepository;

/**
 * Builds browser-loadable development URLs for in-memory product blobs.
 * Emits a URL only when the blob bytes are actually present — never invents
 * Azure/CDN URLs, and never points the browser at a guaranteed 404.
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
     * Prefer a stored Blob/CDN URL; otherwise expose the gated development delivery path
     * only when the in-memory (or disk-backed) object exists.
     */
    public function resolvePublicUrl(?string $storedUrl, string $filePublicId): ?string
    {
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
}
